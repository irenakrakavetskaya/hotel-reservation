<?php

declare(strict_types=1);

namespace App\Infrastructure\Messenger;

use App\Domain\Report\Entity\ReportStatus;
use App\Domain\Report\Message\GenerateReportMessage;
use App\Domain\Report\Message\ReportResultMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

final class ReportMessageSerializer implements SerializerInterface
{
    public function decode(array $encodedEnvelope): Envelope
    {
        try {
            $payload = json_decode($encodedEnvelope['body'], true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new MessageDecodingFailedException('Report message is not valid JSON.', previous: $exception);
        }

        if (!is_array($payload) || 'report.result.v1' !== ($payload['event'] ?? null)) {
            throw new MessageDecodingFailedException('Unsupported report message event.');
        }

        $reportId = $payload['report_id'] ?? null;
        $status = is_string($payload['status'] ?? null) ? ReportStatus::tryFrom($payload['status']) : null;
        $artifactKey = $payload['artifact_key'] ?? null;
        $error = $payload['error'] ?? null;

        if (!is_string($reportId) || !\Symfony\Component\Uid\Uuid::isValid($reportId)
            || null === $status
            || (null !== $artifactKey && !is_string($artifactKey))
            || (null !== $error && !is_string($error))
        ) {
            throw new MessageDecodingFailedException('Report result message has invalid fields.');
        }

        return new Envelope(new ReportResultMessage($reportId, $status, $artifactKey, $error));
    }

    public function encode(Envelope $envelope): array
    {
        $message = $envelope->getMessage();
        if (!$message instanceof GenerateReportMessage) {
            throw new \UnexpectedValueException(sprintf('Unsupported report message: %s.', get_debug_type($message)));
        }

        return [
            'body' => json_encode([
                'event' => 'report.generate.v1',
                'report_id' => $message->reportId,
                'type' => $message->type,
                'from' => $message->from,
                'to' => $message->to,
                'requested_by' => $message->requestedBy,
                'requested_at' => $message->requestedAt,
            ], JSON_THROW_ON_ERROR),
            'headers' => ['content_type' => 'application/json'],
        ];
    }
}
