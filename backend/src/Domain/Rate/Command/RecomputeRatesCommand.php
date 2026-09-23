<?php

declare(strict_types=1);

namespace App\Domain\Rate\Command;

use App\Domain\Rate\Service\RateRecomputeJob;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Thin CLI wrapper — all logic lives in RateRecomputeJob so it stays
 * testable without booting the console kernel. Run manually with
 * `php bin/console app:rates:recompute`; scheduled automatically via
 * src/Infrastructure/Scheduler/MainScheduleProvider.php.
 */
#[AsCommand(
    name: 'app:rates:recompute',
    description: 'Recomputes room_type_rate for the rolling pricing window based on projected occupancy.',
)]
final class RecomputeRatesCommand extends Command
{
    public function __construct(private readonly RateRecomputeJob $job)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->section('Recomputing room type rates');

        $this->job->run();

        $io->success('Rate recompute complete.');

        return Command::SUCCESS;
    }
}
