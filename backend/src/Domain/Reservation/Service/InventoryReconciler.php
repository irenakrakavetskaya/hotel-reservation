<?php

declare(strict_types=1);

namespace App\Domain\Reservation\Service;

use App\Domain\Hotel\Repository\RoomRepository;
use App\Domain\Reservation\Exception\InventoryCapacityConflictException;
use App\Domain\Reservation\Repository\RoomTypeInventoryRepository;
use App\Infrastructure\Cache\CacheScopes;
use App\Infrastructure\Cache\ReadCacheVersionService;
use Doctrine\DBAL\Exception\DriverException;

/**
 * Re-syncs a room type's future `total_inventory` with its active room
 * count after a room or room-type change. Never touches `total_reserved`
 * or past dates. Call inside the same transaction as the room change so a
 * conflict rolls everything back.
 */
final class InventoryReconciler
{
    private const SQLSTATE_CHECK_VIOLATION = '23514';

    public function __construct(
        private readonly RoomRepository $roomRepository,
        private readonly RoomTypeInventoryRepository $inventoryRepository,
        private readonly ReadCacheVersionService $cacheVersions,
    ) {}

    /** @throws InventoryCapacityConflictException when held reservations exceed the new capacity ceiling */
    public function reconcileRoomType(int $hotelId, int $roomTypeId, ?\DateTimeImmutable $today = null): void
    {
        $today = $today ?? new \DateTimeImmutable('today');
        $end = $today->modify(sprintf('+%d days', InventoryPrepopulationJob::WINDOW_DAYS));
        $activeRoomCount = $this->roomRepository->countActiveByHotelAndRoomType($hotelId, $roomTypeId);

        try {
            $this->inventoryRepository->upsertInventoryWindow($hotelId, $roomTypeId, $today, $end, $activeRoomCount);
        } catch (DriverException $e) {
            if (self::SQLSTATE_CHECK_VIOLATION === $e->getSQLState()) {
                throw InventoryCapacityConflictException::forRoomType($hotelId, $roomTypeId, $e);
            }

            throw $e;
        }

        $this->cacheVersions->bump(CacheScopes::availabilityRead($hotelId, $roomTypeId));
    }
}
