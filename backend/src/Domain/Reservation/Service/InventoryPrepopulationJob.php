<?php

declare(strict_types=1);

namespace App\Domain\Reservation\Service;

use App\Domain\Hotel\Entity\RoomType;
use App\Domain\Hotel\Repository\RoomRepository;
use App\Domain\Hotel\Repository\RoomTypeRepository;
use App\Domain\Reservation\Repository\RoomTypeInventoryRepository;
use App\Infrastructure\Cache\CacheScopes;
use App\Infrastructure\Cache\ReadCacheVersionService;
use Psr\Log\LoggerInterface;

/**
 * Populates room_type_inventory rows, per docs/IMPLEMENTATION_PLAN.md §1
 * ("Pre-populated 2 years out by a scheduled job ... extended daily as
 * dates advance further") and §8 (build order, step 2).
 *
 * `total_inventory` for a date is taken as the count of currently `active`
 * rooms for that (hotel, room type) — see
 * RoomRepository::countActiveByHotelAndRoomType(). This is a snapshot, not
 * a date-ranged maintenance calendar: the schema (migrations/
 * Version20260101000003.php) models room.status as a single current value,
 * not maintenance windows, so a room taken offline today affects all future
 * dates re-populated from today onward, not retroactively-dated
 * maintenance. If per-date maintenance scheduling becomes a requirement,
 * this is the method to revisit.
 *
 * Triggered by PrepopulateInventoryCommand (`app:inventory:prepopulate`),
 * scheduled via src/Infrastructure/Scheduler/MainScheduleProvider.php.
 */
final class InventoryPrepopulationJob
{
    /** ~2 years — matches RateRecomputeJob's pricing window. */
    private const WINDOW_DAYS = 730;

    public function __construct(
        private readonly RoomTypeRepository $roomTypeRepository,
        private readonly RoomRepository $roomRepository,
        private readonly RoomTypeInventoryRepository $inventoryRepository,
        private readonly ReadCacheVersionService $cacheVersions,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Daily run: extends the rolling window by exactly the one day that
     * just rolled onto the two-year horizon. Cheap, and idempotent — safe
     * to re-run if the scheduler retries a failed run.
     */
    public function extendWindowByOneDay(?\DateTimeImmutable $today = null): void
    {
        $today = $today ?? new \DateTimeImmutable('today');
        $horizon = $today->modify(sprintf('+%d days', self::WINDOW_DAYS));

        $touchedScopes = $this->populateDate($horizon);
        foreach (array_keys($touchedScopes) as $scope) {
            $this->cacheVersions->bump($scope);
        }

        $this->logger->info('Inventory window extended to {date}.', ['date' => $horizon->format('Y-m-d')]);
    }

    /**
     * Full backfill across the entire rolling window — for a fresh install,
     * or after adding a room type that has no inventory rows yet at all.
     * Safe to re-run: each date is upserted independently via
     * RoomTypeInventoryRepository::upsertInventoryDay(), which never
     * touches total_reserved.
     */
    public function backfillWindow(?\DateTimeImmutable $today = null): void
    {
        $today = $today ?? new \DateTimeImmutable('today');
        $end = $today->modify(sprintf('+%d days', self::WINDOW_DAYS));
        $touchedScopes = [];

        for ($date = $today; $date <= $end; $date = $date->modify('+1 day')) {
            foreach ($this->populateDate($date) as $scope => $unused) {
                $touchedScopes[$scope] = true;
            }
        }

        foreach (array_keys($touchedScopes) as $scope) {
            $this->cacheVersions->bump($scope);
        }

        $this->logger->info(
            'Inventory backfilled from {start} to {end}.',
            ['start' => $today->format('Y-m-d'), 'end' => $end->format('Y-m-d')],
        );
    }

    /**
     * @return array<string, true> cache scopes that need version bumps
     */
    private function populateDate(\DateTimeImmutable $date): array
    {
        /** @var list<RoomType> $roomTypes */
        $roomTypes = $this->roomTypeRepository->findAll();
        $touchedScopes = [];

        foreach ($roomTypes as $roomType) {
            $hotelId = $roomType->getHotel()->getId();
            $roomTypeId = $roomType->getId();

            $activeRoomCount = $this->roomRepository->countActiveByHotelAndRoomType($hotelId, $roomTypeId);

            $this->inventoryRepository->upsertInventoryDay($hotelId, $roomTypeId, $date, $activeRoomCount);
            $touchedScopes[CacheScopes::availabilityRead($hotelId, $roomTypeId)] = true;
        }

        return $touchedScopes;
    }
}
