package main

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"strconv"
	"syscall"
	"time"

	"github.com/irenakrakavetskaya/hotel-report-worker/internal/worker"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/stdlib"
)

/*
Program entry point.
Creates a JSON logger (slog) that writes to stdout.
Calls run(logger).
If run returns an error, logs it and exits with code 1.
*/
func main() {
	logger := slog.New(slog.NewJSONHandler(os.Stdout, nil))
	if err := run(logger); err != nil {
		logger.Error("report worker stopped", "error", err)
		os.Exit(1)
	}
}

func run(logger *slog.Logger) error {
	amqpURL, err := requiredEnv("AMQP_URL")
	if err != nil {
		return err
	}
	config, err := databaseConfig()
	if err != nil {
		return err
	}
	db := stdlib.OpenDB(*config) //Open the DB pool
	defer db.Close()

	startupCtx, startupCancel := context.WithTimeout(context.Background(), 2*time.Minute)
	defer startupCancel()
	for {
		if err := db.PingContext(startupCtx); err == nil {
			break
		} else if startupCtx.Err() != nil {
			return fmt.Errorf("connect to report database: %w", err)
		}
		time.Sleep(2 * time.Second)
	}

	//Limit the pool to 2 open and 1 idle connection
	db.SetMaxOpenConns(2)
	db.SetMaxIdleConns(1)

	reportDirectory := envOr("REPORTS_DIR", "/data/reports")

	//Create the worker
	reportWorker, err := worker.New(db, amqpURL, reportDirectory, logger)
	if err != nil {
		return err
	}
	defer func() {
		if err := reportWorker.Close(); err != nil {
			logger.Error("close report worker connections", "error", err)
		}
	}()

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()
	server := &http.Server{
		Addr:              envOr("HEALTH_ADDR", ":8081"),
		Handler:           reportWorker.HealthHandler(),
		ReadHeaderTimeout: 5 * time.Second,
	}
	serverErrors := make(chan error, 1)

	//Start the health HTTP server
	go func() {
		if err := server.ListenAndServe(); !errors.Is(err, http.ErrServerClosed) {
			serverErrors <- err
		}
	}()

	workerErrors := make(chan error, 1)

	//Start the worker in another goroutine and sends its result to workerErrors
	go func() {
		workerErrors <- reportWorker.Run(ctx)
	}()

	//Wait for the first event with select:
	var result error
	select {
	case <-ctx.Done():
	case result = <-serverErrors: //server fails
		stop()
	case result = <-workerErrors: //worker stops or fails.
		stop()
	}

	//Shut down the server with a 10 s timeout
	shutdownCtx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	if err := server.Shutdown(shutdownCtx); err != nil {
		result = errors.Join(result, fmt.Errorf("shut down health server: %w", err))
	}

	//Return the combined error, or nil.
	if result != nil {
		return result
	}

	return nil
}

// Build the PostgreSQL connection settings from environment variables
func databaseConfig() (*pgx.ConnConfig, error) {
	host, err := requiredEnv("DB_HOST")
	if err != nil {
		return nil, err
	}
	database, err := requiredEnv("DB_NAME")
	if err != nil {
		return nil, err
	}
	user, err := requiredEnv("DB_USER")
	if err != nil {
		return nil, err
	}
	password, err := requiredEnv("DB_PASSWORD")
	if err != nil {
		return nil, err
	}
	port, err := strconv.ParseUint(envOr("DB_PORT", "5432"), 10, 16)
	if err != nil {
		return nil, fmt.Errorf("parse DB_PORT: %w", err)
	}
	config, err := pgx.ParseConfig("postgres://localhost?sslmode=disable")
	if err != nil {
		return nil, fmt.Errorf("parse PostgreSQL connection configuration: %w", err)
	}
	config.Host = host
	config.Port = uint16(port)
	config.Database = database
	config.User = user
	config.Password = password
	config.RuntimeParams["application_name"] = "hotel-report-worker"
	config.RuntimeParams["default_transaction_read_only"] = "on"

	return config, nil
}

// Return the environment variable, or the error KEY is required
// if it is empty. Startup fail early with a clear message.
func requiredEnv(key string) (string, error) {
	value := os.Getenv(key)
	if value == "" {
		return "", fmt.Errorf("%s is required", key)
	}

	return value, nil
}

// Returns the environment variable, or the fallback if it is
func envOr(key, fallback string) string {
	if value := os.Getenv(key); value != "" {
		return value
	}

	return fallback
}
