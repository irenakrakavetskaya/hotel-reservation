<?php

declare(strict_types=1);

namespace App\Domain\Reservation\Repository;

use App\Domain\Reservation\Entity\RoomTypeInventory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Cache\CacheItemPoolInterface;

/**
 * @extends ServiceEntityRepository<RoomTypeInventory>
 *
 * Read-side + pre-population methods for room_type_inventory. The
 * transactional reserve/release writes tied to an actual booking live on
 * ReservationRepository, not here — see
 * `.claude/skills/reservation-domain/SKILL.md`.
 */
class RoomTypeInventoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, private readonly CacheItemPoolInterface $cache)
    {
        parent::__construct($registry, RoomTypeInventory::class);
    }

    /**
     * Implements the exact query from docs/IMPLEMENTATION_PLAN.md §1 /
     * the original design doc's "Data model" section.
     *
     * @return array<string, array{total_inventory: int, total_reserved: int}> keyed by date (Y-m-d)
     *
     * The end date is exclusive so callers can pass a reservation's
     * checkout date without reserving it.
     */
    public function findAvailability(
        int $hotelId,
        int $roomTypeId,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
    ): array {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            <<<'SQL'
                SELECT date, total_inventory, total_reserved
                FROM room_type_inventory
                WHERE hotel_id = :hotelId
                  AND room_type_id = :roomTypeId
                  AND date >= :startDate
                  AND date < :endDate
                ORDER BY date
                SQL,
            [
                'hotelId' => $hotelId,
                'roomTypeId' => $roomTypeId,
                'startDate' => $startDate->format('Y-m-d'),
                'endDate' => $endDate->format('Y-m-d'),
            ],
        );

        $result = [];
        foreach ($rows as $row) {
            $maxReservable = (int) ($row['total_inventory'] * 1.1);
            $availableRooms = max(0, $maxReservable - (int) $row['total_reserved']);
            $cacheItem = $this->cache->getItem($this->buildAvailabilityKey($hotelId, $roomTypeId, (string) $row['date']));
            $cacheItem->set($availableRooms);
            $cacheItem->expiresAfter(300);
            $this->cache->save($cacheItem);

            $result[$row['date']] = [
                'total_inventory' => (int) $row['total_inventory'],
                'total_reserved' => (int) $row['total_reserved'],
            ];
        }

        return $result;
    }

    /**
     * Fast-fail availability check for the booking page / booking-page
     * re-validation (docs/IMPLEMENTATION_PLAN.md §6). This is a UX nicety,
     * NOT the correctness guarantee — the `check_room_count` DB constraint
     * is what actually prevents overbooking; this can race and say "yes"
     * for two concurrent requests, same as the plan's worked example.
     */
    public function hasAvailability(
        int $hotelId,
        int $roomTypeId,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
        int $roomCount,
    ): bool {
        $allDatesCached = true;
        for ($cursor = $startDate; $cursor < $endDate; $cursor = $cursor->modify('+1 day')) {
            $date = $cursor->format('Y-m-d');
            $cacheItem = $this->cache->getItem($this->buildAvailabilityKey($hotelId, $roomTypeId, $date));
            $cachedValue = $cacheItem->get();

            if (!$cacheItem->isHit() || !is_int($cachedValue)) {
                $allDatesCached = false;
                break;
            }

            if ($cachedValue < $roomCount) {
                return false;
            }
        }

        if ($allDatesCached) {
            return true;
        }

        $availability = $this->findAvailability($hotelId, $roomTypeId, $startDate, $endDate);

        $expectedDays = $startDate->diff($endDate)->days;
        if (count($availability) !== $expectedDays) {
            // No inventory rows for this range means the pre-population job
            // hasn't reached these dates yet — treat as unavailable rather
            // than silently allowing an unbounded booking.
            return false;
        }

        foreach ($availability as $day) {
            $ceiling = (int) ($day['total_inventory'] * 1.1);
            if ($day['total_reserved'] + $roomCount > $ceiling) {
                return false;
            }
        }

        return true;
    }

    /**
     * Used by the scheduled pre-population job (docs/IMPLEMENTATION_PLAN.md
     * §1, §8 step 2) that extends the rolling ~2-year inventory window.
     *
     * Deliberately upserts only `total_inventory`, never `total_reserved` —
     * this must never clobber the live reservation count for a date that
     * already has bookings against it.
     */
    public function upsertInventoryDay(int $hotelId, int $roomTypeId, \DateTimeImmutable $date, int $totalInventory): void
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            <<<'SQL'
                INSERT INTO room_type_inventory (hotel_id, room_type_id, date, total_inventory, total_reserved, updated_at)
                VALUES (:hotelId, :roomTypeId, :date, :totalInventory, 0, now())
                ON CONFLICT (hotel_id, room_type_id, date)
                DO UPDATE SET total_inventory = EXCLUDED.total_inventory, updated_at = now()
                RETURNING date, total_inventory, total_reserved
                SQL,
            [
                'hotelId' => $hotelId,
                'roomTypeId' => $roomTypeId,
                'date' => $date->format('Y-m-d'),
                'totalInventory' => $totalInventory,
            ],
        );

        if (false !== $row) {
            $maxReservable = (int) (((int) $row['total_inventory']) * 1.1);
            $availableRooms = max(0, $maxReservable - (int) $row['total_reserved']);
            $cacheItem = $this->cache->getItem($this->buildAvailabilityKey($hotelId, $roomTypeId, (string) $row['date']));
            $cacheItem->set($availableRooms);
            $cacheItem->expiresAfter(300);
            $this->cache->save($cacheItem);
        }
    }

    /**
     * Same upsert as {@see upsertInventoryDay()} but for an entire date
     * range in one round trip via `generate_series`, rather than one
     * statement per day. Used by the daily pre-population job
     * (docs/IMPLEMENTATION_PLAN.md §8 step 2) to extend the rolling ~2-year
     * window — `total_inventory` for a room type doesn't vary by date the
     * way pricing does, so a single batched write is safe and much cheaper
     * than looping.
     */
    public function upsertInventoryWindow(
        int $hotelId,
        int $roomTypeId,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
        int $totalInventory,
    ): void {
        $this->getEntityManager()->getConnection()->executeStatement(
            <<<'SQL'
                INSERT INTO room_type_inventory (hotel_id, room_type_id, date, total_inventory, total_reserved, updated_at)
                SELECT :hotelId, :roomTypeId, series.day::date, :totalInventory, 0, now()
                FROM generate_series(:startDate::date, :endDate::date, interval '1 day') AS series(day)
                ON CONFLICT (hotel_id, room_type_id, date)
                DO UPDATE SET total_inventory = EXCLUDED.total_inventory, updated_at = now()
                SQL,
            [
                'hotelId' => $hotelId,
                'roomTypeId' => $roomTypeId,
                'startDate' => $startDate->format('Y-m-d'),
                'endDate' => $endDate->format('Y-m-d'),
                'totalInventory' => $totalInventory,
            ],
        );

        $this->refreshAvailabilityCacheForRange($hotelId, $roomTypeId, $startDate, $endDate->modify('+1 day'));
    }

    public function refreshAvailabilityCacheForRange(
        int $hotelId,
        int $roomTypeId,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
    ): void {
        $this->findAvailability($hotelId, $roomTypeId, $startDate, $endDate);
    }

    private function buildAvailabilityKey(int $hotelId, int $roomTypeId, string $date): string
    {
        return sprintf('inventory:%d:%d:%s:available', $hotelId, $roomTypeId, $date);
    }
}
