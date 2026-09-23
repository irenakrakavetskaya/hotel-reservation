<?php

declare(strict_types=1);

namespace App\Infrastructure\Scheduler;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Component\Scheduler\Messenger\RunCommandMessage;

/**
 * Requires `symfony/scheduler` (composer require symfony/scheduler) and a
 * `scheduler` transport consumer running (`php bin/console messenger:consume
 * scheduler_default`) in production — e.g. as its own container/process
 * alongside the FPM one in docker/docker-compose.yml.
 *
 * Both jobs run inventory-first, then rates — pricing reads the inventory
 * row for the day, so a stale/missing inventory row would make a freshly
 * added date price off a wrong (or absent) occupancy figure.
 *
 * `->stateful($cache)` makes missed runs (e.g. a deploy during the
 * scheduled window) catch up on the next process start instead of
 * silently skipping a day — important here because skipping a day means
 * the rolling window falls a day short and a booking on the horizon date
 * finds no rate.
 */
#[AsSchedule('default')]
final class MainScheduleProvider implements ScheduleProviderInterface
{
    public function __construct(private readonly CacheItemPoolInterface $cache)
    {
    }

    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->add(
                RecurringMessage::cron('0 2 * * *', new RunCommandMessage('app:inventory:prepopulate')),
                RecurringMessage::cron('0 3 * * *', new RunCommandMessage('app:rates:recompute')),
            )
            ->stateful($this->cache);
    }
}
