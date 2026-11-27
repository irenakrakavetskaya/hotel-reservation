<?php

declare(strict_types=1);

namespace App\Domain\Report\Dto;

use App\Domain\Report\Entity\ReservationReport;
use OpenApi\Attributes as OA;

#[OA\Schema(required: ['id', 'from', 'to', 'status', 'error', 'createdAt', 'finishedAt'])]
final class ReportResponse
{
    private function __construct(
        public readonly string $id,
        public readonly string $from,
        public readonly string $to,
        public readonly string $status,
        public readonly ?string $error,
        public readonly string $createdAt,
        public readonly ?string $finishedAt,
    ) {}

    public static function fromEntity(ReservationReport $report): self
    {
        return new self(
            id: $report->getId()->toRfc4122(),
            from: $report->getPeriodFrom()->format('Y-m-d'),
            to: $report->getPeriodTo()->format('Y-m-d'),
            status: $report->getStatus()->value,
            error: $report->getErrorMessage(),
            createdAt: $report->getCreatedAt()->format(\DateTimeInterface::ATOM),
            finishedAt: $report->getFinishedAt()?->format(\DateTimeInterface::ATOM),
        );
    }

    /** @param list<ReservationReport> $reports
     *
     * @return list<self>
     */
    public static function fromEntities(array $reports): array
    {
        return array_map(self::fromEntity(...), $reports);
    }
}
