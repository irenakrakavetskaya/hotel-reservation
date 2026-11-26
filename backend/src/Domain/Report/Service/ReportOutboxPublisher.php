<?php

declare(strict_types=1);

namespace App\Domain\Report\Service;

use App\Domain\Report\Message\GenerateReportMessage;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpStamp;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

final class ReportOutboxPublisher
{
    private const MAX_ATTEMPTS = 10;

    public function __construct(
        private readonly Connection $connection,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
        private readonly ReportRealtime $realtime,
    ) {}

    public function publishBatch(int $limit = 50): int
    {
        $published = 0;
        for ($count = 0; $count < $limit; ++$count) {
            $this->connection->beginTransaction();
            try {
                $row = $this->connection->fetchAssociative(<<<'SQL'
                    SELECT id, report_id, payload, attempt_count
                    FROM report_outbox
                    WHERE published_at IS NULL AND available_at <= now()
                    ORDER BY available_at, id
                    FOR UPDATE SKIP LOCKED
                    LIMIT 1
                    SQL);

                if (false === $row) {
                    $this->connection->commit();

                    break;
                }

                try {
                    $message = $this->messageFromPayload((string) $row['payload']);
                    $this->messageBus->dispatch($message, [new AmqpStamp('reports.generate')]);
                    $this->connection->executeStatement(
                        'UPDATE report_outbox SET published_at = now(), attempt_count = attempt_count + 1, last_error = NULL WHERE id = :id',
                        ['id' => $row['id']],
                    );
                    $this->connection->commit();
                    ++$published;
                } catch (TransportException|\JsonException|\UnexpectedValueException $exception) {
                    $reportChanged = $this->recordFailure($row, $exception);
                    $this->connection->commit();
                    if ($reportChanged) {
                        $this->realtime->notify((string) $row['report_id']);
                    }
                    $this->logger->error('Could not publish report generation request.', [
                        'report_id' => $row['report_id'],
                        'attempt_count' => (int) $row['attempt_count'] + 1,
                        'exception' => $exception,
                    ]);
                }
            } catch (\Throwable $exception) {
                if ($this->connection->isTransactionActive()) {
                    $this->connection->rollBack();
                }

                throw $exception;
            }
        }

        return $published;
    }

    private function messageFromPayload(string $json): GenerateReportMessage
    {
        $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($payload)
            || 1 !== ($payload['version'] ?? null)
            || 'reservations_summary' !== ($payload['type'] ?? null)
            || !is_string($payload['report_id'] ?? null)
            || !Uuid::isValid($payload['report_id'])
            || !is_string($payload['from'] ?? null)
            || !is_string($payload['to'] ?? null)
            || !is_int($payload['requested_by'] ?? null)
            || !is_string($payload['requested_at'] ?? null)
        ) {
            throw new \UnexpectedValueException('Report outbox payload has an invalid shape.');
        }

        return new GenerateReportMessage(
            $payload['report_id'],
            $payload['type'],
            $payload['from'],
            $payload['to'],
            $payload['requested_by'],
            $payload['requested_at'],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function recordFailure(array $row, \Throwable $exception): bool
    {
        $attempt = (int) $row['attempt_count'] + 1;
        $error = mb_substr($exception->getMessage(), 0, 1000);

        if ($attempt >= self::MAX_ATTEMPTS) {
            $changed = $this->connection->executeStatement(
                "UPDATE reservation_report SET status = 'failed', error_message = :error, started_at = COALESCE(started_at, now()), finished_at = now(), updated_at = now() WHERE id = :reportId AND status IN ('pending', 'processing')",
                ['error' => 'Unable to queue report after repeated delivery failures.', 'reportId' => $row['report_id']],
            );
            $this->connection->executeStatement(
                'UPDATE report_outbox SET attempt_count = :attempt, published_at = now(), last_error = :error WHERE id = :id',
                ['attempt' => $attempt, 'error' => $error, 'id' => $row['id']],
            );

            return $changed > 0;
        }

        $delay = min(300, 2 ** $attempt);
        $this->connection->executeStatement(
            "UPDATE report_outbox SET attempt_count = :attempt, available_at = now() + (:delay * INTERVAL '1 second'), last_error = :error WHERE id = :id",
            ['attempt' => $attempt, 'delay' => $delay, 'error' => $error, 'id' => $row['id']],
        );

        return false;
    }
}
