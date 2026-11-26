<?php

declare(strict_types=1);

namespace App\Domain\Report\Service;

use App\Domain\Report\Entity\ReservationReport;
use App\Domain\Report\Entity\ReportStatus;
use App\Domain\Report\Exception\ReportNotFoundException;
use App\Domain\Report\Message\ReportResultMessage;
use App\Domain\Report\Repository\ReportRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class ReportService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Connection $connection,
        private readonly ReportRepository $reports,
    ) {}

    public function create(int $requesterId, \DateTimeImmutable $from, \DateTimeImmutable $to): ReservationReport
    {
        $id = Uuid::v7();
        $report = new ReservationReport($id, $requesterId, $from, $to);
        $outboxId = Uuid::v7();

        $this->connection->beginTransaction();
        try {
            $this->entityManager->persist($report);
            $this->entityManager->flush();
            $payload = json_encode([
                'version' => 1,
                'report_id' => $id->toRfc4122(),
                'type' => 'reservations_summary',
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
                'requested_by' => $requesterId,
                'requested_at' => $report->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ], JSON_THROW_ON_ERROR);
            $this->connection->executeStatement(
                'INSERT INTO report_outbox (id, report_id, payload) VALUES (:id, :reportId, CAST(:payload AS JSONB))',
                [
                    'id' => $outboxId->toRfc4122(),
                    'reportId' => $id->toRfc4122(),
                    'payload' => $payload,
                ],
            );
            $this->connection->commit();
        } catch (\Throwable $exception) {
            $this->connection->rollBack();
            throw $exception;
        }

        return $report;
    }

    /** @return list<ReservationReport> */
    public function listRecent(): array
    {
        return $this->reports->findRecent();
    }

    public function get(Uuid $id): ReservationReport
    {
        return $this->reports->findByUuid($id) ?? throw ReportNotFoundException::forId($id->toRfc4122());
    }

    public function applyWorkerResult(ReportResultMessage $message): void
    {
        $report = $this->get(Uuid::fromString($message->reportId));

        match ($message->status) {
            ReportStatus::Processing => $report->markProcessing(),
            ReportStatus::Done => $this->markDone($report, $message->artifactKey),
            ReportStatus::Failed => $this->markFailed($report, $message->error),
            ReportStatus::Pending => throw new \UnexpectedValueException('A report worker cannot return pending status.'),
        };

        $this->entityManager->flush();
    }

    private function markDone(ReservationReport $report, ?string $artifactKey): void
    {
        $expectedKey = sprintf('report-%s.pdf', $report->getId()->toRfc4122());
        if ($artifactKey !== $expectedKey) {
            throw new \UnexpectedValueException('Report worker returned an invalid artifact key.');
        }

        $report->markDone($artifactKey);
    }

    private function markFailed(ReservationReport $report, ?string $error): void
    {
        if (null === $error || '' === trim($error)) {
            throw new \UnexpectedValueException('Report worker failure must include an error message.');
        }

        $report->markFailed($error);
    }
}
