<?php

declare(strict_types=1);

namespace App\Domain\Report\Exception;

final class ReportNotReadyException extends \RuntimeException
{
    public static function forId(string $id): self
    {
        return new self(sprintf('Report "%s" is not ready for download.', $id));
    }
}
