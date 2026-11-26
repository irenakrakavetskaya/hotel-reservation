<?php

declare(strict_types=1);

namespace App\Domain\Report\Entity;

use App\Domain\Report\Repository\ReportRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ReportRepository::class)]
#[ORM\Table(name: 'reservation_report')]
#[ORM\Index(columns: ['created_at'], name: 'idx_reservation_report_created_at')]
class ReservationReport
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(name: 'requested_by', type: 'bigint')]
    private int $requestedBy;

    #[ORM\Column(name: 'period_from', type: 'date_immutable')]
    private \DateTimeImmutable $periodFrom;

    #[ORM\Column(name: 'period_to', type: 'date_immutable')]
    private \DateTimeImmutable $periodTo;

    #[ORM\Column(type: 'string', length: 20, enumType: ReportStatus::class)]
    private ReportStatus $status = ReportStatus::Pending;

    #[ORM\Column(name: 'artifact_key', type: 'string', length: 100, nullable: true)]
    private ?string $artifactKey = null;

    #[ORM\Column(name: 'error_message', type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'started_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(name: 'finished_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    public function __construct(
        Uuid $id,
        int $requestedBy,
        \DateTimeImmutable $periodFrom,
        \DateTimeImmutable $periodTo,
    ) {
        $this->id = $id;
        $this->requestedBy = $requestedBy;
        $this->periodFrom = $periodFrom;
        $this->periodTo = $periodTo;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getRequestedBy(): int
    {
        return $this->requestedBy;
    }

    public function getPeriodFrom(): \DateTimeImmutable
    {
        return $this->periodFrom;
    }

    public function getPeriodTo(): \DateTimeImmutable
    {
        return $this->periodTo;
    }

    public function getStatus(): ReportStatus
    {
        return $this->status;
    }

    public function getArtifactKey(): ?string
    {
        return $this->artifactKey;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function markProcessing(): void
    {
        if (ReportStatus::Pending !== $this->status) {
            return;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->status = ReportStatus::Processing;
        $this->startedAt = $now;
        $this->updatedAt = $now;
    }

    public function markDone(string $artifactKey): void
    {
        if (ReportStatus::Done === $this->status || ReportStatus::Failed === $this->status) {
            return;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->status = ReportStatus::Done;
        $this->artifactKey = $artifactKey;
        $this->errorMessage = null;
        $this->startedAt ??= $now;
        $this->finishedAt = $now;
        $this->updatedAt = $now;
    }

    public function markFailed(string $errorMessage): void
    {
        if (ReportStatus::Done === $this->status || ReportStatus::Failed === $this->status) {
            return;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->status = ReportStatus::Failed;
        $this->errorMessage = mb_substr($errorMessage, 0, 1000);
        $this->startedAt ??= $now;
        $this->finishedAt = $now;
        $this->updatedAt = $now;
    }
}
