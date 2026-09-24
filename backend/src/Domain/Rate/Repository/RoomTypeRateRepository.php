<?php

declare(strict_types=1);

namespace App\Domain\Rate\Repository;

use App\Domain\Rate\Entity\RoomTypeRate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Cache\CacheItemPoolInterface;

/**
 * @extends ServiceEntityRepository<RoomTypeRate>
 */
class RoomTypeRateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, private readonly CacheItemPoolInterface $cache)
    {
        parent::__construct($registry, RoomTypeRate::class);
    }

    /**
     * Read path for the room/booking pages — reads the pre-computed rate,
     * never calculates a price live. See docs/IMPLEMENTATION_PLAN.md §4.
     *
     * @return array<string, int> date (Y-m-d) => price in cents
     */
    public function findForDateRange(
        int $hotelId,
        int $roomTypeId,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
    ): array {
        $cached = [];
        $needsDbFetch = false;
        for ($cursor = $startDate; $cursor < $endDate; $cursor = $cursor->modify('+1 day')) {
            $date = $cursor->format('Y-m-d');
            $cacheItem = $this->cache->getItem($this->buildRateKey($hotelId, $roomTypeId, $date));
            $value = $cacheItem->get();

            if (!$cacheItem->isHit() || !is_int($value)) {
                $needsDbFetch = true;
                break;
            }

            $cached[$date] = $value;
        }

        if (!$needsDbFetch) {
            return $cached;
        }

        $conn = $this->getEntityManager()->getConnection();
        $rows = $conn->fetchAllAssociative(
            <<<'SQL'
                SELECT date, price
                FROM room_type_rate
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
            $date = (string) $row['date'];
            $price = (int) $row['price'];
            $result[$date] = $price;

            $cacheItem = $this->cache->getItem($this->buildRateKey($hotelId, $roomTypeId, $date));
            $cacheItem->set($price);
            $cacheItem->expiresAfter(300);
            $this->cache->save($cacheItem);
        }

        return $result;
    }

    /**
     * Used by the daily pricing recompute job (docs/IMPLEMENTATION_PLAN.md
     * §4) to upsert a single (hotel, room type, date) rate. Native upsert
     * rather than persist()/flush() — this job runs across a rolling
     * ~2-year window and needs set-based, not per-entity, writes.
     */
    public function upsertRate(int $hotelId, int $roomTypeId, \DateTimeImmutable $date, int $priceCents): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            <<<'SQL'
                INSERT INTO room_type_rate (hotel_id, room_type_id, date, price, updated_at)
                VALUES (:hotelId, :roomTypeId, :date, :price, now())
                ON CONFLICT (hotel_id, room_type_id, date)
                DO UPDATE SET price = EXCLUDED.price, updated_at = now()
                SQL,
            [
                'hotelId' => $hotelId,
                'roomTypeId' => $roomTypeId,
                'date' => $date->format('Y-m-d'),
                'price' => $priceCents,
            ],
        );
    }

    /**
     * @param array<string, int> $ratesByDate
     */
    public function warmRateCache(int $hotelId, int $roomTypeId, array $ratesByDate): void
    {
        foreach ($ratesByDate as $date => $price) {
            $cacheItem = $this->cache->getItem($this->buildRateKey($hotelId, $roomTypeId, $date));
            $cacheItem->set($price);
            $cacheItem->expiresAfter(300);
            $this->cache->save($cacheItem);
        }
    }

    private function buildRateKey(int $hotelId, int $roomTypeId, string $date): string
    {
        return sprintf('rate.%d.%d.%s', $hotelId, $roomTypeId, $date);
    }
}
