package worker

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
	exchangeName = "hotel_reports"
	queueName    = "reports.generate"
	resultKey    = "reports.result"
	maxRetries   = 5
)

type Worker struct {
	connection *amqp.Connection
	channel    *amqp.Channel
	confirms   <-chan amqp.Confirmation
	db         *sql.DB
	directory  string
	logger     *slog.Logger
}

func New(db *sql.DB, amqpURL, directory string, logger *slog.Logger) (*Worker, error) {
	connection, err := amqp.Dial(amqpURL)
	if err != nil {
		return nil, fmt.Errorf("connect to RabbitMQ: %w", err)
	}

	channel, err := connection.Channel()
	if err != nil {
		connection.Close()
		return nil, fmt.Errorf("open RabbitMQ channel: %w", err)
	}
	if err := channel.ExchangeDeclare(exchangeName, "direct", true, false, false, false, nil); err != nil {
		channel.Close()
		connection.Close()
		return nil, fmt.Errorf("declare reports exchange: %w", err)
	}
	if err := channel.ExchangeDeclare("hotel_reports_dead", "direct", true, false, false, false, nil); err != nil {
		channel.Close()
		connection.Close()
		return nil, fmt.Errorf("declare report dead-letter exchange: %w", err)
	}
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

	return &Worker{
		connection: connection,
		channel:    channel,
		confirms:   channel.NotifyPublish(make(chan amqp.Confirmation, 1)),
		db:         db,
		directory:  directory,
		logger:     logger,
	}, nil
}

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

func (w *Worker) HealthHandler() http.Handler {
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

func (w *Worker) Run(ctx context.Context) error {
	deliveries, err := w.channel.Consume(queueName, "", false, false, false, false, nil)
	if err != nil {
		return fmt.Errorf("consume report requests: %w", err)
	}
	w.logger.Info("report worker started", "queue", queueName)

	for {
		select {
		case <-ctx.Done():
			return nil
		case delivery, ok := <-deliveries:
			if !ok {
				return errors.New("RabbitMQ report delivery channel closed")
			}
			if err := w.handle(ctx, delivery); err != nil {
				w.logger.Error("report delivery failed", "error", err, "delivery_tag", delivery.DeliveryTag)
			}
		}
	}
}

func (w *Worker) handle(ctx context.Context, delivery amqp.Delivery) error {
	var job report.Job
	if err := json.Unmarshal(delivery.Body, &job); err != nil {
		if nackErr := delivery.Nack(false, false); nackErr != nil {
			return errors.Join(fmt.Errorf("decode report request: %w", err), nackErr)
		}
		return fmt.Errorf("invalid report request sent to dead-letter queue: %w", err)
	}
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

	if err := w.publishResult(ctx, resultMessage{
		Event:    "report.result.v1",
		ReportID: job.ReportID,
		Status:   "processing",
	}); err != nil {
		return w.requeue(delivery, err)
	}

	generationCtx, cancel := context.WithTimeout(ctx, 2*time.Minute)
	defer cancel()
	result, err := report.Aggregate(generationCtx, w.db, job)
	if err != nil {
		return w.retryOrFail(ctx, delivery, job.ReportID, fmt.Errorf("aggregate reservations: %w", err))
	}
	artifactKey, err := report.WritePDF(job, result, w.directory)
	if err != nil {
		return w.retryOrFail(ctx, delivery, job.ReportID, fmt.Errorf("write report PDF: %w", err))
	}
	if err := w.publishResult(ctx, resultMessage{
		Event:       "report.result.v1",
		ReportID:    job.ReportID,
		Status:      "done",
		ArtifactKey: artifactKey,
	}); err != nil {
		return w.requeue(delivery, err)
	}

	return delivery.Ack(false)
}

func (w *Worker) retryOrFail(ctx context.Context, delivery amqp.Delivery, reportID string, cause error) error {
	retryCount := headerInt(delivery.Headers["x-report-retry-count"])
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

func (w *Worker) publishResult(ctx context.Context, message resultMessage) error {
	body, err := json.Marshal(message)
	if err != nil {
		return fmt.Errorf("encode report result: %w", err)
	}

	return w.publish(ctx, resultKey, body, nil)
}

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
