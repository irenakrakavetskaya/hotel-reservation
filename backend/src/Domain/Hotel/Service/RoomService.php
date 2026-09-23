<?php

declare(strict_types=1);

namespace App\Domain\Hotel\Service;

use App\Domain\Hotel\Dto\RoomRequest;
use App\Domain\Hotel\Entity\Room;
use App\Domain\Hotel\Entity\RoomType;
use App\Domain\Hotel\Exception\DuplicateRoomNumberException;
use App\Domain\Hotel\Exception\HotelNotFoundException;
use App\Domain\Hotel\Exception\RoomNotFoundException;
use App\Domain\Hotel\Exception\RoomTypeNotFoundException;
use App\Domain\Hotel\Repository\RoomRepository;
use App\Domain\Hotel\Repository\RoomTypeRepository;
use App\Infrastructure\Cache\CacheScopes;
use App\Infrastructure\Cache\ReadCacheVersionService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Business logic for room CRUD, always scoped to a hotel — rooms are
 * managed as a sub-resource of a hotel per docs/IMPLEMENTATION_PLAN.md §2.
 * Controllers call this, never RoomRepository directly.
 */
final class RoomService
{
    public function __construct(
        private readonly RoomRepository $roomRepository,
        private readonly RoomTypeRepository $roomTypeRepository,
        private readonly HotelService $hotelService,
        private readonly ReadCacheVersionService $cacheVersions,
    ) {
    }

    /** @return list<Room> */
    public function listRooms(int $hotelId): array
    {
        // Confirms the hotel exists (404s otherwise) before returning what
        // may be an empty list — an empty room list for a hotel that
        // doesn't exist should read as 404, not as "no rooms".
        $this->hotelService->getHotel($hotelId);

        return $this->roomRepository->findByHotel($hotelId);
    }

    /** @throws RoomNotFoundException */
    public function getRoom(int $hotelId, int $id): Room
    {
        $room = $this->roomRepository->find($id);

        if (null === $room || $room->getHotel()->getId() !== $hotelId) {
            throw RoomNotFoundException::forId($hotelId, $id);
        }

        return $room;
    }

    /**
     * @throws HotelNotFoundException
     * @throws RoomTypeNotFoundException
     * @throws DuplicateRoomNumberException
     */
    public function createRoom(int $hotelId, RoomRequest $request): Room
    {
        $hotel = $this->hotelService->getHotel($hotelId);
        $roomType = $this->resolveRoomType($hotelId, $request->roomTypeId);

        $room = new Room($hotel, $roomType, $request->roomNumber, $request->floor);
        $room->setStatus($request->getStatus());

        $this->saveOrThrowOnDuplicate($room, $hotelId, $request->roomNumber);
        $this->cacheVersions->bump(CacheScopes::roomRead($hotelId));

        return $room;
    }

    /**
     * @throws RoomNotFoundException
     * @throws RoomTypeNotFoundException
     * @throws DuplicateRoomNumberException
     */
    public function updateRoom(int $hotelId, int $id, RoomRequest $request): Room
    {
        $room = $this->getRoom($hotelId, $id);
        $roomType = $this->resolveRoomType($hotelId, $request->roomTypeId);

        $room->update($roomType, $request->roomNumber, $request->floor, $request->getStatus());

        $this->saveOrThrowOnDuplicate($room, $hotelId, $request->roomNumber);
        $this->cacheVersions->bump(CacheScopes::roomRead($hotelId));

        return $room;
    }

    /** @throws RoomNotFoundException */
    public function deleteRoom(int $hotelId, int $id): void
    {
        $room = $this->getRoom($hotelId, $id);
        $this->roomRepository->remove($room);
        $this->cacheVersions->bump(CacheScopes::roomRead($hotelId));
    }

    /** @throws RoomTypeNotFoundException */
    private function resolveRoomType(int $hotelId, int $roomTypeId): RoomType
    {
        $roomType = $this->roomTypeRepository->find($roomTypeId);

        if (null === $roomType || $roomType->getHotel()->getId() !== $hotelId) {
            throw RoomTypeNotFoundException::forId($hotelId, $roomTypeId);
        }

        return $roomType;
    }

    /** @throws DuplicateRoomNumberException */
    private function saveOrThrowOnDuplicate(Room $room, int $hotelId, string $roomNumber): void
    {
        try {
            $this->roomRepository->save($room);
        } catch (UniqueConstraintViolationException $e) {
            throw DuplicateRoomNumberException::forNumber($hotelId, $roomNumber, previous: $e);
        }
    }
}
