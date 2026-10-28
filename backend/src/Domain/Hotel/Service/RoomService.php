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
use App\Domain\Reservation\Exception\InventoryCapacityConflictException;
use App\Domain\Reservation\Service\InventoryReconciler;
use Doctrine\DBAL\Connection;
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
        private readonly InventoryReconciler $inventoryReconciler,
        private readonly Connection $connection,
    ) {}

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
     * @throws InventoryCapacityConflictException
     */
    public function createRoom(int $hotelId, RoomRequest $request): Room
    {
        $hotel = $this->hotelService->getHotel($hotelId);
        $roomType = $this->resolveRoomType($hotelId, $request->roomTypeId);

        $room = new Room($hotel, $roomType, $request->roomNumber, $request->floor);
        $room->setStatus($request->toStatus());

        $this->inTransaction(function () use ($room, $hotelId, $request): void {
            $this->saveOrThrowOnDuplicate($room, $hotelId, $request->roomNumber);
            $this->inventoryReconciler->reconcileRoomType($hotelId, (int) $room->getRoomType()->getId());
        });
        $this->cacheVersions->bump(CacheScopes::roomRead($hotelId));

        return $room;
    }

    /**
     * @throws RoomNotFoundException
     * @throws RoomTypeNotFoundException
     * @throws DuplicateRoomNumberException
     * @throws InventoryCapacityConflictException
     */
    public function updateRoom(int $hotelId, int $id, RoomRequest $request): Room
    {
        $room = $this->getRoom($hotelId, $id);
        $roomType = $this->resolveRoomType($hotelId, $request->roomTypeId);

        $previousRoomTypeId = (int) $room->getRoomType()->getId();
        $room->update($roomType, $request->roomNumber, $request->floor, $request->toStatus());

        $this->inTransaction(function () use ($room, $hotelId, $request, $previousRoomTypeId): void {
            $this->saveOrThrowOnDuplicate($room, $hotelId, $request->roomNumber);
            $this->inventoryReconciler->reconcileRoomType($hotelId, $previousRoomTypeId);
            $newRoomTypeId = (int) $room->getRoomType()->getId();
            if ($newRoomTypeId !== $previousRoomTypeId) {
                $this->inventoryReconciler->reconcileRoomType($hotelId, $newRoomTypeId);
            }
        });
        $this->cacheVersions->bump(CacheScopes::roomRead($hotelId));

        return $room;
    }

    /**
     * @throws RoomNotFoundException
     * @throws InventoryCapacityConflictException
     */
    public function deleteRoom(int $hotelId, int $id): void
    {
        $room = $this->getRoom($hotelId, $id);
        $roomTypeId = (int) $room->getRoomType()->getId();
        $this->inTransaction(function () use ($room, $hotelId, $roomTypeId): void {
            $this->roomRepository->remove($room);
            $this->inventoryReconciler->reconcileRoomType($hotelId, $roomTypeId);
        });
        $this->cacheVersions->bump(CacheScopes::roomRead($hotelId));
    }

    private function inTransaction(callable $work): void
    {
        $this->connection->beginTransaction();

        try {
            $work();
            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
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
