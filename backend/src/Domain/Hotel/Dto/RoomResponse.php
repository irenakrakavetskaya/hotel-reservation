<?php

declare(strict_types=1);

namespace App\Domain\Hotel\Dto;

use App\Domain\Hotel\Entity\Room;

/** Explicit API output shape — never serialize the Room entity directly. */
final class RoomResponse
{
    private function __construct(
        public readonly int $id,
        public readonly int $hotelId,
        public readonly int $roomTypeId,
        public readonly string $roomNumber,
        public readonly ?int $floor,
        public readonly string $status,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {
    }

    public static function fromEntity(Room $room): self
    {
        return new self(
            id: $room->getId(),
            hotelId: $room->getHotel()->getId(),
            roomTypeId: $room->getRoomType()->getId(),
            roomNumber: $room->getRoomNumber(),
            floor: $room->getFloor(),
            status: $room->getStatus()->value,
            createdAt: $room->getCreatedAt()->format(\DateTimeInterface::ATOM),
            updatedAt: $room->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        );
    }

    /**
     * @param list<Room> $rooms
     *
     * @return list<self>
     */
    public static function fromEntities(array $rooms): array
    {
        return array_map(self::fromEntity(...), $rooms);
    }
}
