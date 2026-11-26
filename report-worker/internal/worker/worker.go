package worker

// Package worker connects RabbitMQ, the database, and the report logic.
// It consumes jobs, publishes status updates, handles retries, and exposes a health endpoint.
// Results go back to Symfony through the RabbitMQ queue reports.results.
import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	"net/http"
	"strconv"
	"time"

	"github.com/irenakrakavetskaya/hotel-report-worker/internal/report"
	amqp "github.com/rabbitmq/amqp091-go"
)

const (
	exchangeName = "hotel_reports"    //Main direct exchange
	queueName    = "reports.generate" //Incoming jobs
	resultKey    = "reports.result"   //Routing key for report results
	maxRetries   = 5                  //Maximum number of retries for a job
)

// Hold the AMQP connection and channel, the publisher-confirm channel, the DB handle,
// the output directory, and the logger
type Worker struct {
	connection *amqp.Connection
	channel    *amqp.Channel
	confirms   <-chan amqp.Confirmation
	db         *sql.DB
	directory  string
	logger     *slog.Logger
}

// Create a new Worker with the given database handle, AMQP URL, output directory, and logger.
// It sets up the necessary exchanges, queues, and bindings in RabbitMQ.
func New(db *sql.DB, amqpURL, directory string, logger *slog.Logger) (*Worker, error) {
	// Connects and opens a channel.
	connection, err := amqp.Dial(amqpURL)
	if err != nil {
		return nil, fmt.Errorf("connect to RabbitMQ: %w", err)
	}

	channel, err := connection.Channel()
	if err != nil {
		connection.Close()
		return nil, fmt.Errorf("open RabbitMQ channel: %w", err)
	}

	// Declare the main exchange for report messages.
	if err := channel.ExchangeDeclare(exchangeName, "direct", true, false, false, false, nil); err != nil {
		channel.Close()
		connection.Close()
		return nil, fmt.Errorf("declare reports exchange: %w", err)
	}

	// Declare the dead-letter exchange for failed reports.
	if err := channel.ExchangeDeclare("hotel_reports_dead", "direct", true, false, false, false, nil); err != nil {
		channel.Close()
		connection.Close()
		return nil, fmt.Errorf("declare report dead-letter exchange: %w", err)
	}

	// Declare the main report request queue with dead-letter settings.
	_, err = channel.QueueDeclare(queueName, true, false, false, false, amqp.Table{
		"x-dead-letter-exchange":    "hotel_reports_dead",
		"x-dead-letter-routing-key": "reports.failed",
	})
	if err != nil {
		channel.Close()
		connection.Close()
		return nil, fmt.Errorf("declare report request queue: %w", err)
	}
	if err := channel.QueueBind(queueName, queueName, exchangeName, false, nil); err != nil {
		channel.Close()
		connection.Close()
		return nil, fmt.Errorf("bind report request queue: %w", err)
	}
	if _, err := channel.QueueDeclare("reports.failed", true, false, false, false, nil); err != nil {
		channel.Close()
		connection.Close()
		return nil, fmt.Errorf("declare failed report queue: %w", err)
	}
	if err := channel.QueueBind("reports.failed", "reports.failed", "hotel_reports_dead", false, nil); err != nil {
		channel.Close()
		connection.Close()
		return nil, fmt.Errorf("bind failed report queue: %w", err)
	}
	if _, err := channel.QueueDeclare("reports.results", true, false, false, false, nil); err != nil {
		channel.Close()
		connection.Close()
		return nil, fmt.Errorf("declare report result queue: %w", err)
	}
	if err := channel.QueueBind("reports.results", resultKey, exchangeName, false, nil); err != nil {
		channel.Close()
		connection.Close()
		return nil, fmt.Errorf("bind report result queue: %w", err)
	}
	if err := channel.Qos(1, 0, false); err != nil {
		channel.Close()
		connection.Close()
		return nil, fmt.Errorf("set report worker prefetch: %w", err)
	}
	if err := channel.Confirm(false); err != nil {
		channel.Close()
		connection.Close()
		return nil, fmt.Errorf("enable RabbitMQ publisher confirms: %w", err)
	}

	// Return the initialized worker with the connection, channel, confirms channel,
	// DB handle, output directory, and logger.
	return &Worker{
		connection: connection,
		channel:    channel,
		confirms:   channel.NotifyPublish(make(chan amqp.Confirmation, 1)),
		db:         db,
		directory:  directory,
		logger:     logger,
	}, nil
}

// Closes the channel and the connection, returning both errors if both fail.
func (w *Worker) Close() error {
	channelErr := w.channel.Close()
	connectionErr := w.connection.Close()
	if channelErr != nil && connectionErr != nil {
		return errors.Join(channelErr, connectionErr)
	}
	if channelErr != nil {
		return channelErr
	}

	return connectionErr
}

// Returns a small router with GET /healthz:
// 503 if the RabbitMQ connection or channel is closed.
// 503 if the DB ping fails.
// Otherwise 200 with ok.
func (w *Worker) HealthHandler() http.Handler {
	// Create a new ServeMux for handling HTTP requests.
	mux := http.NewServeMux()
	mux.HandleFunc("GET /healthz", func(writer http.ResponseWriter, request *http.Request) {
		if w.connection.IsClosed() || w.channel.IsClosed() {
			http.Error(writer, "worker unavailable", http.StatusServiceUnavailable)
			return
		}
		if err := w.db.PingContext(request.Context()); err != nil {
			http.Error(writer, "database unavailable", http.StatusServiceUnavailable)
			return
		}

		writer.WriteHeader(http.StatusOK)
		_, _ = writer.Write([]byte("ok"))
	})

	return mux
}

// The main loop.
func (w *Worker) Run(ctx context.Context) error {
	//Starts consuming reports.generate with manual ack (autoAck=false).
	deliveries, err := w.channel.Consume(queueName, "", false, false, false, false, nil)
	if err != nil {
		return fmt.Errorf("consume report requests: %w", err)
	}
	w.logger.Info("report worker started", "queue", queueName)

	//Waits in a select for context cancellation (returns nil) or a delivery.
	for {
		select {
		// Check for context cancellation or incoming delivery.
		// If the context is done, exit the loop.
		case <-ctx.Done():
			return nil
		case delivery, ok := <-deliveries:
			if !ok {
				return errors.New("RabbitMQ report delivery channel closed")
			}
			// Handle the delivery and log any errors.
			if err := w.handle(ctx, delivery); err != nil {
				w.logger.Error("report delivery failed", "error", err, "delivery_tag", delivery.DeliveryTag)
			}
		}
	}
}

// Processes one job.
func (w *Worker) handle(ctx context.Context, delivery amqp.Delivery) error {
	var job report.Job
	// Attempt to decode the delivery body into a report job.
	// If decoding fails, nack the delivery and return an error.
	if err := json.Unmarshal(delivery.Body, &job); err != nil {
		if nackErr := delivery.Nack(false, false); nackErr != nil {
			return errors.Join(fmt.Errorf("decode report request: %w", err), nackErr)
		}
		return fmt.Errorf("invalid report request sent to dead-letter queue: %w", err)
	}

	// Validate the report job.
	// If the report ID is valid, publish a failed status ("Report request is invalid.") and Ack.
	// If publishing fails, requeue.
	// If the ID is not valid, Nack without requeue (dead-letter).
	if err := report.Validate(job, time.Now()); err != nil {
		if report.ValidReportID(job.ReportID) {
			if publishErr := w.publishResult(ctx, resultMessage{
				Event:    "report.result.v1",
				ReportID: job.ReportID,
				Status:   "failed",
				Error:    "Report request is invalid.",
			}); publishErr != nil {
				return w.requeue(delivery, errors.Join(err, publishErr))
			}
			return delivery.Ack(false)
		}
		if nackErr := delivery.Nack(false, false); nackErr != nil {
			return errors.Join(err, nackErr)
		}
		return fmt.Errorf("invalid report request sent to dead-letter queue: %w", err)
	}

	// Publishes processing. On failure, requeue.
	if err := w.publishResult(ctx, resultMessage{
		Event:    "report.result.v1",
		ReportID: job.ReportID,
		Status:   "processing",
	}); err != nil {
		return w.requeue(delivery, err)
	}

	// Calculates with report.Aggregate. Errors go to retryOrFail.
	generationCtx, cancel := context.WithTimeout(ctx, 2*time.Minute)
	defer cancel()
	result, err := report.Aggregate(generationCtx, w.db, job)
	if err != nil {
		return w.retryOrFail(ctx, delivery, job.ReportID, fmt.Errorf("aggregate reservations: %w", err))
	}

	// Writes the PDF with report.WritePDF. Errors go to retryOrFail.
	artifactKey, err := report.WritePDF(job, result, w.directory)
	if err != nil {
		return w.retryOrFail(ctx, delivery, job.ReportID, fmt.Errorf("write report PDF: %w", err))
	}

	// Publishes done with the file name as artifact_key. On failure, requeue.
	if err := w.publishResult(ctx, resultMessage{
		Event:       "report.result.v1",
		ReportID:    job.ReportID,
		Status:      "done",
		ArtifactKey: artifactKey,
	}); err != nil {
		return w.requeue(delivery, err)
	}

	// Ack the original message
	return delivery.Ack(false)
}

// Handles processing errors.
func (w *Worker) retryOrFail(ctx context.Context, delivery amqp.Delivery, reportID string, cause error) error {
	//Reads the retry counter from the header x-report-retry-count.
	retryCount := headerInt(delivery.Headers["x-report-retry-count"])

	//Limit reached (≥ 5): logs, publishes a failed status
	// ("Report generation failed after repeated attempts."), and Acks.
	if retryCount >= maxRetries {
		w.logger.Error("report generation exhausted retries", "report_id", reportID, "error", cause)
		if err := w.publishResult(ctx, resultMessage{
			Event:    "report.result.v1",
			ReportID: reportID,
			Status:   "failed",
			Error:    "Report generation failed after repeated attempts.",
		}); err != nil {
			return w.requeue(delivery, errors.Join(cause, err))
		}
		return delivery.Ack(false)
	}

	// Exponential backoff (1, 2, 4, 8, 16 s) before retrying.
	// copies the headers with the counter + 1,
	//  republishes the same body to reports.generate,
	// and Acks the original.
	// The wait is cancelled by ctx, and then the message is requeued.
	delay := time.Duration(1<<retryCount) * time.Second
	timer := time.NewTimer(delay)
	select {
	case <-ctx.Done():
		timer.Stop()
		return w.requeue(delivery, ctx.Err())
	case <-timer.C:
	}

	headers := amqp.Table{}
	for key, value := range delivery.Headers {
		headers[key] = value
	}
	headers["x-report-retry-count"] = int32(retryCount + 1)
	if err := w.publish(ctx, queueName, delivery.Body, headers); err != nil {
		return w.requeue(delivery, errors.Join(cause, err))
	}
	if err := delivery.Ack(false); err != nil {
		return fmt.Errorf("acknowledge retried report: %w", err)
	}

	return nil
}

// Calls Nack(false, true) (put the message back in the queue).
// Returns cause, joined with the Nack error if that also failed.
// Used when the problem is with infrastructure (RabbitMQ), not with the job.
func (w *Worker) requeue(delivery amqp.Delivery, cause error) error {
	if err := delivery.Nack(false, true); err != nil {
		return errors.Join(cause, fmt.Errorf("requeue report request: %w", err))
	}

	return cause
}

type resultMessage struct {
	Event       string `json:"event"`
	ReportID    string `json:"report_id"`
	Status      string `json:"status"`
	ArtifactKey string `json:"artifact_key,omitempty"`
	Error       string `json:"error,omitempty"`
}

// Encodes resultMessage to JSON and publishes it
// with the routing key reports.result.
func (w *Worker) publishResult(ctx context.Context, message resultMessage) error {
	body, err := json.Marshal(message)
	if err != nil {
		return fmt.Errorf("encode report result: %w", err)
	}

	return w.publish(ctx, resultKey, body, nil)
}

// Low-level publish.
// Sends a persistent JSON message to hotel_reports.
// Waits for the publisher confirmation: up to 10 s, or until ctx ends.
// Returns an error on nack, timeout, or cancellation.
func (w *Worker) publish(ctx context.Context, routingKey string, body []byte, headers amqp.Table) error {
	if err := w.channel.PublishWithContext(ctx, exchangeName, routingKey, false, false, amqp.Publishing{
		Headers:      headers,
		ContentType:  "application/json",
		DeliveryMode: amqp.Persistent,
		Timestamp:    time.Now().UTC(),
		Body:         body,
	}); err != nil {
		return fmt.Errorf("publish report message: %w", err)
	}

	timer := time.NewTimer(10 * time.Second)
	defer timer.Stop()
	select {
	case confirmation := <-w.confirms:
		if !confirmation.Ack {
			return errors.New("RabbitMQ negatively acknowledged report message")
		}
	case <-timer.C:
		return errors.New("timed out waiting for RabbitMQ publisher confirmation")
	case <-ctx.Done():
		return ctx.Err()
	}

	return nil
}

// Converts an AMQP header value (int32, int, etc.) to int.
// Returns 0 for a missing or unknown value.
// Needed because AMQP headers arrive as any.
func headerInt(value any) int {
	switch typed := value.(type) {
	case int32:
		return int(typed)
	case int64:
		return int(typed)
	case int:
		return typed
	case string:
		parsed, err := strconv.Atoi(typed)
		if err == nil {
			return parsed
		}
	}

	return 0
}
