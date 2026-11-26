<?php

declare(strict_types=1);

namespace App\Infrastructure\Command;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:redis:status', description: 'Report Redis health, memory usage and server-wide cache hit rate.')]
final class RedisStatusCommand extends Command
{
    public function __construct(private readonly \Redis $redis, private readonly LoggerInterface $logger)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            if (false === $this->redis->ping()) {
                throw new \RuntimeException('Redis PING failed.');
            }
            $memory = $this->redis->info('memory');
            $stats = $this->redis->info('stats');
            if (!is_array($memory) || !is_array($stats)
                || !isset($memory['used_memory'], $memory['maxmemory'], $stats['keyspace_hits'], $stats['keyspace_misses'])) {
                throw new \RuntimeException('Redis INFO did not return the required metrics.');
            }

            $used = (int) $memory['used_memory'];
            $max = (int) $memory['maxmemory'];
            $hits = (int) $stats['keyspace_hits'];
            $misses = (int) $stats['keyspace_misses'];
            $hitRate = $hits + $misses > 0 ? 100 * $hits / ($hits + $misses) : null;
            $message = sprintf(
                'Redis: used_memory=%d maxmemory=%d keyspace_hits=%d keyspace_misses=%d hit_rate=%s',
                $used,
                $max,
                $hits,
                $misses,
                null === $hitRate ? 'n/a' : sprintf('%.2f%%', $hitRate),
            );
            $io->writeln($message);
            $this->logger->info($message);

            if ($max > 0 && $used / $max >= 0.85) {
                $this->logger->warning('Redis memory usage is at least 85% of maxmemory.');
                $io->warning('Redis memory usage is at least 85% of maxmemory.');
            }
        } catch (\RedisException | \RuntimeException $exception) {
            $this->logger->error('Redis health check failed: {error}', ['error' => $exception->getMessage()]);
            $io->error('Redis health check failed: ' . $exception->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
