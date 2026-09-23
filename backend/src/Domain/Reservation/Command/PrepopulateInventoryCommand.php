<?php

declare(strict_types=1);

namespace App\Domain\Reservation\Command;

use App\Domain\Reservation\Service\InventoryPrepopulationJob;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Thin CLI wrapper — all logic lives in InventoryPrepopulationJob so it
 * stays testable without booting the console kernel.
 *
 * Daily run: `php bin/console app:inventory:prepopulate`
 * One-off backfill (fresh install / new room type): `php bin/console
 * app:inventory:prepopulate --backfill`
 *
 * The daily form is scheduled via
 * src/Infrastructure/Scheduler/MainScheduleProvider.php.
 */
#[AsCommand(
    name: 'app:inventory:prepopulate',
    description: 'Extends room_type_inventory by one day (default) or backfills the full rolling window (--backfill).',
)]
final class PrepopulateInventoryCommand extends Command
{
    public function __construct(private readonly InventoryPrepopulationJob $job)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'backfill',
            null,
            InputOption::VALUE_NONE,
            'Populate the entire rolling window instead of just extending it by one day.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('backfill')) {
            $io->section('Backfilling inventory for the full rolling window');
            $this->job->backfillWindow();
            $io->success('Inventory backfill complete.');

            return Command::SUCCESS;
        }

        $io->section('Extending inventory window by one day');
        $this->job->extendWindowByOneDay();
        $io->success('Inventory window extended.');

        return Command::SUCCESS;
    }
}
