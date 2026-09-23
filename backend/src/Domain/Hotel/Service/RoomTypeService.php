<?php

declare(strict_types=1);

namespace App\Domain\Hotel\Service;

use App\Domain\Hotel\Dto\RoomTypeRequest;
use App\Domain\Hotel\Entity\RoomType;
use App\Domain\Hotel\Exception\HotelNotFoundException;
use App\Domain\Hotel\Exception\RoomTypeResourceNotFoundException;
use App\Domain\Hotel\Repository\RoomTypeRepository;
use App\Infrastructure\Cache\CacheScopes;
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
    ) {
    }

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

        $this->roomTypeRepository->save($roomType);
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

    /** @throws RoomTypeResourceNotFoundException */
    public function deleteRoomType(int $hotelId, int $id): void
    {
        $roomType = $this->getRoomType($hotelId, $id);
        $this->roomTypeRepository->remove($roomType);
        $this->cacheVersions->bump(CacheScopes::roomTypeRead($hotelId));
    }
}
