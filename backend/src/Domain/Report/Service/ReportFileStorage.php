<?php

declare(strict_types=1);

namespace App\Domain\Report\Service;

use App\Domain\Report\Entity\ReservationReport;
use App\Domain\Report\Exception\ReportNotReadyException;

final class ReportFileStorage
{
    public function __construct(private readonly string $directory) {}

    public function pathFor(ReservationReport $report): string
    {
        $expectedKey = sprintf('report-%s.pdf', $report->getId()->toRfc4122());
        if ($expectedKey !== $report->getArtifactKey()) {
            throw ReportNotReadyException::forId($report->getId()->toRfc4122());
        }

        $path = rtrim($this->directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $expectedKey;
        if (!is_file($path) || !is_readable($path)) {
            throw ReportNotReadyException::forId($report->getId()->toRfc4122());
        }

        return $path;
    }
}
