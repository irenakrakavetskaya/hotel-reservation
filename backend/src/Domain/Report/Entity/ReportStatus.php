<?php

declare(strict_types=1);

namespace App\Domain\Report\Entity;

enum ReportStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Done = 'done';
    case Failed = 'failed';
}
