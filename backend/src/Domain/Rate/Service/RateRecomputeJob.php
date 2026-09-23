<?php

declare(strict_types=1);

namespace App\Domain\Rate\Service;

use App\Domain\Hotel\Entity\RoomType;
use App\Domain\Hotel\Repository\RoomTypeRepository;
use App\Domain\Rate\Repository\RoomTypeRateRepository;
use App\Domain\Reservation\Repository\RoomTypeInventoryRepository;
use App\Infrastructure\Cache\CacheScopes;
use App\Infrastructure\Cache\ReadCacheVersionService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Daily job described in docs/IMPLEMENTATION_PLAN.md §4 ("Dynamic
 * pricing") and §8 (build order, step 5): recomputes and upserts
 * room_type_rate for every (hotel, room type, date) in the rolling
 * ~2-year window, based on that date's projected occupancy.
 *
 * Triggered by RecomputeRatesCommand (`app:rates:recompute`), which is
 * wired into Symfony Scheduler — see
 * src/Infrastructure/Scheduler/MainScheduleProvider.php. The command is a
 * thin CLI wrapper; all logic lives here so it's directly unit-testable
 * without booting the console.
 */
final class RateRecomputeJob
{
    /**
     * Matches the inventory pre-population window
     * (InventoryPrepopulationJob::WINDOW_DAYS) — rates only need to exist
     * as far out as inventory does.
     */
    private const WINDOW_DAYS = 730;

    public function __construct(
        private readonly RoomTypeRepository $roomTypeRepository,
        private readonly RoomTypeInventoryRepository $inventoryRepository,
        private readonly RoomTypeRateRepository $rateRepository,
        private readonly PriceCalculator $priceCalculator,
        private readonly EntityManagerInterface $entityManager,
        private readonly ReadCacheVersionService $cacheVersions,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function run(?\DateTimeImmutable $today = null): void
    {
        $today = $today ?? new \DateTimeImmutable('today');
        $windowEnd = $today->modify(sprintf('+%d days', self::WINDOW_DAYS));

        /** @var list<RoomType> $roomTypes */
        $roomTypes = $this->roomTypeRepository->findAll();

        foreach ($roomTypes as $roomType) {
            $this->recomputeForRoomType($roomType, $today, $windowEnd);
        }
    }

    private function recomputeForRoomType(RoomType $roomType, \DateTimeImmutable $start, \DateTimeImmutable $end): void
    {
        $hotelId = $roomType->getHotel()->getId();
        $roomTypeId = $roomType->getId();

        $availability = $this->inventoryRepository->findAvailability(
            $hotelId,
            $roomTypeId,
            $start,
            $end->modify('+1 day'),
        );

        $expectedDays = $start->diff($end)->days + 1;
        if (count($availability) !== $expectedDays) {
            // No inventory rows yet for this window — the pre-population
            // job hasn't reached this room type/date range. Nothing to
            // price against; skip rather than upserting a rate based on a
            // fabricated occupancy figure.
            $this->logger->warning(
                'Incomplete inventory window for room type {roomTypeId}; skipping rate recompute.',
                ['roomTypeId' => $roomTypeId],
            );

            return;
        }

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        $computedRates = [];

        try {
            foreach ($availability as $date => $day) {
                $occupancyRate = $day['total_inventory'] > 0
                    ? $day['total_reserved'] / $day['total_inventory']
                    : 0.0;

                $price = $this->priceCalculator->calculate($roomType->getBasePrice(), $occupancyRate);
                $computedRates[$date] = $price;

                $this->rateRepository->upsertRate($hotelId, $roomTypeId, new \DateTimeImmutable($date), $price);
            }

            $connection->commit();
            $this->rateRepository->warmRateCache($hotelId, $roomTypeId, $computedRates);
            $this->cacheVersions->bump(CacheScopes::availabilityRead($hotelId, $roomTypeId));
        } catch (\Throwable $e) {
            $connection->rollBack();
            $this->logger->error(
                'Rate recompute failed for room type {roomTypeId}: {message}',
                ['roomTypeId' => $roomTypeId, 'message' => $e->getMessage()],
            );

            throw $e;
        }
    }
}
