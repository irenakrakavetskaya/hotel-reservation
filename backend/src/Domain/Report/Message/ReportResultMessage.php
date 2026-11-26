<?php

declare(strict_types=1);

namespace App\Domain\Report\Message;

use App\Domain\Report\Entity\ReportStatus;

final class ReportResultMessage
{
    public function __construct(
        public readonly string $reportId,
        public readonly ReportStatus $status,
        public readonly ?string $artifactKey = null,
        public readonly ?string $error = null,
    ) {}
}
