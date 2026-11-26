<?php

declare(strict_types=1);

namespace App\Domain\Report\Message;

final class GenerateReportMessage
{
    public function __construct(
        public readonly string $reportId,
        public readonly string $type,
        public readonly string $from,
        public readonly string $to,
        public readonly int $requestedBy,
        public readonly string $requestedAt,
    ) {}
}
