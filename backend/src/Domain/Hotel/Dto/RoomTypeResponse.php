<?php

declare(strict_types=1);

namespace App\Domain\Hotel\Dto;

use App\Domain\Hotel\Entity\RoomType;

/** Explicit API output shape — never serialize RoomType entity directly. */
final class RoomTypeResponse
{
    /**
     * @param list<string> $amenities
     */
    private function __construct(
        public readonly int $id,
        public readonly int $hotelId,
        public readonly string $name,
        public readonly ?string $description,
        public readonly int $maxOccupancy,
        public readonly int $basePrice,
        public readonly array $amenities,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {
    }

    public static function fromEntity(RoomType $roomType): self
    {
        return new self(
            id: $roomType->getId() ?? throw new \LogicException('Room type id should be present after persistence.'),
            hotelId: $roomType->getHotel()->getId() ?? throw new \LogicException('Hotel id should be present after persistence.'),
            name: $roomType->getName(),
            description: $roomType->getDescription(),
            maxOccupancy: $roomType->getMaxOccupancy(),
            basePrice: $roomType->getBasePrice(),
            amenities: $roomType->getAmenities(),
            createdAt: $roomType->getCreatedAt()->format(\DateTimeInterface::ATOM),
            updatedAt: $roomType->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        );
    }

    /**
     * @param list<RoomType> $roomTypes
     *
     * @return list<self>
     */
    public static function fromEntities(array $roomTypes): array
    {
        return array_map(self::fromEntity(...), $roomTypes);
    }
}
