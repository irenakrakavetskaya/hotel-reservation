<?php

declare(strict_types=1);

namespace App\Domain\Reservation\Service;

use App\Domain\Hotel\Exception\RoomTypeResourceNotFoundException;
use App\Domain\Hotel\Repository\RoomTypeRepository;
use App\Domain\Rate\Repository\RoomTypeRateRepository;
use App\Domain\Reservation\Dto\AvailabilityQueryRequest;
use App\Domain\Reservation\Dto\AvailabilityResponse;
use App\Domain\Reservation\Repository\RoomTypeInventoryRepository;
use App\Infrastructure\Cache\CacheScopes;
use App\Infrastructure\Cache\ReadCacheVersionService;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Read model for customer-facing availability/rate checks.
 *
 * Postgres remains source of truth; Redis-backed app cache only accelerates
 * repeated reads.
 */
final class AvailabilityQueryService
{
    private const CACHE_TTL_SECONDS = 60;

    public function __construct(
        private readonly RoomTypeRepository $roomTypeRepository,
        private readonly RoomTypeInventoryRepository $inventoryRepository,
        private readonly RoomTypeRateRepository $rateRepository,
        private readonly CacheItemPoolInterface $cache,
        private readonly ReadCacheVersionService $cacheVersions,
    ) {
    }

    public function getAvailability(int $hotelId, int $roomTypeId, AvailabilityQueryRequest $query): AvailabilityResponse
    {
        $roomType = $this->roomTypeRepository->find($roomTypeId);
        if (null === $roomType || $roomType->getHotel()->getId() !== $hotelId) {
            throw RoomTypeResourceNotFoundException::forId($hotelId, $roomTypeId);
        }

        $startDate = $query->getStartDate();
        $endDate = $query->getEndDate();
        $roomCount = $query->getRoomCount();
        $cacheScope = CacheScopes::availabilityRead($hotelId, $roomTypeId);
        $cacheVersion = $this->cacheVersions->getVersion($cacheScope);
        $cacheKey = $this->buildCacheKey($hotelId, $roomTypeId, $startDate, $endDate, $roomCount, $cacheVersion);

        $item = $this->cache->getItem($cacheKey);
        if ($item->isHit()) {
            $payload = $item->get();
            if (is_array($payload)) {
                return AvailabilityResponse::fromArray($payload);
            }
        }

        $response = $this->buildAvailability($hotelId, $roomTypeId, $startDate, $endDate, $roomCount);

        $item->set($response->toArray());
        $item->expiresAfter(self::CACHE_TTL_SECONDS);
        $this->cache->save($item);

        return $response;
    }

    private function buildCacheKey(
        int $hotelId,
        int $roomTypeId,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
        int $roomCount,
        int $version,
    ): string {
        return sprintf(
            'availability:%d:%d:%s:%s:%d:v%d',
            $hotelId,
            $roomTypeId,
            $startDate->format('Ymd'),
            $endDate->format('Ymd'),
            $roomCount,
            $version,
        );
    }

    private function buildAvailability(
        int $hotelId,
        int $roomTypeId,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
        int $roomCount,
    ): AvailabilityResponse {
        $nights = $startDate->diff($endDate)->days;
        $inventoryByDate = $this->inventoryRepository->findAvailability($hotelId, $roomTypeId, $startDate, $endDate);
        $ratesByDate = $this->rateRepository->findForDateRange($hotelId, $roomTypeId, $startDate, $endDate);

        $canBook = true;
        $totalPrice = 0;
        $minAvailableRooms = null;
        $days = [];

        for ($cursor = $startDate; $cursor < $endDate; $cursor = $cursor->modify('+1 day')) {
            $dateKey = $cursor->format('Y-m-d');
            $inventory = $inventoryByDate[$dateKey] ?? null;
            $nightlyPrice = $ratesByDate[$dateKey] ?? null;

            if (null === $inventory || null === $nightlyPrice) {
                $canBook = false;
                $totalPrice = 0;

                $days[] = [
                    'date' => $dateKey,
                    'totalInventory' => 0,
                    'totalReserved' => 0,
                    'availableRooms' => 0,
                    'price' => $nightlyPrice,
                ];

                continue;
            }

            $maxReservable = (int) ($inventory['total_inventory'] * 1.1);
            $availableRooms = max(0, $maxReservable - $inventory['total_reserved']);

            if ($availableRooms < $roomCount) {
                $canBook = false;
            }

            $minAvailableRooms = null === $minAvailableRooms
                ? $availableRooms
                : min($minAvailableRooms, $availableRooms);

            $totalPrice += $nightlyPrice * $roomCount;

            $days[] = [
                'date' => $dateKey,
                'totalInventory' => $inventory['total_inventory'],
                'totalReserved' => $inventory['total_reserved'],
                'availableRooms' => $availableRooms,
                'price' => $nightlyPrice,
            ];
        }

        return new AvailabilityResponse(
            hotelId: $hotelId,
            roomTypeId: $roomTypeId,
            startDate: $startDate->format('Y-m-d'),
            endDate: $endDate->format('Y-m-d'),
            roomCount: $roomCount,
            nights: $nights,
            canBook: $canBook,
            totalPrice: $canBook ? $totalPrice : null,
            minAvailableRooms: $minAvailableRooms ?? 0,
            days: $days,
        );
    }
}
