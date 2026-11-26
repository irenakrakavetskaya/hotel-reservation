<?php

declare(strict_types=1);

namespace App\Domain\Report\MessageHandler;

use App\Domain\Report\Message\ReportResultMessage;
use App\Domain\Report\Service\ReportService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ReportResultHandler
{
    public function __construct(private readonly ReportService $reports) {}

    public function __invoke(ReportResultMessage $message): void
    {
        $this->reports->applyWorkerResult($message);
    }
}
