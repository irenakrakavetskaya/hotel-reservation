<?php

declare(strict_types=1);

namespace App\Domain\Hotel\Service;

use Doctrine\DBAL\Exception\DriverException;
use App\Domain\Hotel\Exception\ResourceHasReservationsException;
use App\Domain\Hotel\Dto\RoomTypeRequest;
use App\Domain\Hotel\Entity\RoomType;
use App\Domain\Hotel\Exception\HotelNotFoundException;
use App\Domain\Hotel\Exception\RoomTypeResourceNotFoundException;
use App\Domain\Hotel\Repository\RoomTypeRepository;
use App\Domain\Reservation\Service\InventoryReconciler;
use App\Infrastructure\Cache\CacheScopes;
use Doctrine\DBAL\Connection;
use App\Infrastructure\Cache\ReadCacheVersionService;

/**
 * Business logic for room-type CRUD. Controllers call this service, not the
 * repository directly.
 */
final class RoomTypeService
{
    public function __construct(
        private readonly RoomTypeRepository $roomTypeRepository,
        private readonly HotelService $hotelService,
        private readonly ReadCacheVersionService $cacheVersions,
        private readonly InventoryReconciler $inventoryReconciler,
        private readonly Connection $connection,
    ) {}

    /** @return list<RoomType> */
    public function listRoomTypes(int $hotelId): array
    {
        $this->hotelService->getHotel($hotelId);

        return $this->roomTypeRepository->findByHotel($hotelId);
    }

    /** @throws RoomTypeResourceNotFoundException */
    public function getRoomType(int $hotelId, int $id): RoomType
    {
        $roomType = $this->roomTypeRepository->find($id);

        if (null === $roomType || $roomType->getHotel()->getId() !== $hotelId) {
            throw RoomTypeResourceNotFoundException::forId($hotelId, $id);
        }

        return $roomType;
    }

    /** @throws HotelNotFoundException */
    public function createRoomType(int $hotelId, RoomTypeRequest $request): RoomType
    {
        $hotel = $this->hotelService->getHotel($hotelId);

        $roomType = new RoomType(
            hotel: $hotel,
            name: $request->name,
            maxOccupancy: $request->maxOccupancy,
            basePrice: $request->basePrice,
            description: $request->description,
            amenities: $request->amenities,
        );

        // Create the inventory window up front so the new type is bookable
        // (and priced) without waiting for a manual backfill.
        $this->connection->beginTransaction();

        try {
            $this->roomTypeRepository->save($roomType);
            $this->inventoryReconciler->reconcileRoomType($hotelId, (int) $roomType->getId());
            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }

        $this->cacheVersions->bump(CacheScopes::roomTypeRead($hotelId));

        return $roomType;
    }

    /** @throws RoomTypeResourceNotFoundException */
    public function updateRoomType(int $hotelId, int $id, RoomTypeRequest $request): RoomType
    {
        $roomType = $this->getRoomType($hotelId, $id);

        $roomType->update(
            name: $request->name,
            description: $request->description,
            maxOccupancy: $request->maxOccupancy,
            basePrice: $request->basePrice,
            amenities: $request->amenities,
        );

        $this->roomTypeRepository->save($roomType);
        $this->cacheVersions->bump(CacheScopes::roomTypeRead($hotelId));

        return $roomType;
    }

    /** @throws RoomTypeResourceNotFoundException
     * @throws ResourceHasReservationsException
     */
    public function deleteRoomType(int $hotelId, int $id): void
    {
        $roomType = $this->getRoomType($hotelId, $id);
        try {
            $this->roomTypeRepository->remove($roomType);
        } catch (DriverException $e) {
            // 23001 restrict_violation / 23503 foreign_key_violation
            if (in_array($e->getSQLState(), ['23001', '23503'], true)) {
                throw ResourceHasReservationsException::forRoomType($id, $e);
            }

            throw $e;
        }
        $this->cacheVersions->bump(CacheScopes::roomTypeRead($hotelId));
    }
}
