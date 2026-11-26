<?php

declare(strict_types=1);

namespace App\Infrastructure\Command;

use App\Domain\Report\Service\ReportOutboxPublisher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:reports:publish-outbox', description: 'Publish pending report requests to the async queue.')]
final class PublishReportOutboxCommand extends Command
{
    public function __construct(private readonly ReportOutboxPublisher $publisher)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = $this->publisher->publishBatch();
        (new SymfonyStyle($input, $output))->text(sprintf('Published %d report request(s).', $count));

        return Command::SUCCESS;
    }
}
