<?php

declare(strict_types=1);

namespace App\Domain\Hotel\Dto;

use App\Domain\Hotel\Entity\Hotel;

/** Explicit API output shape — never serialize the Hotel entity directly. */
final class HotelResponse
{
    private function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $address,
        public readonly string $city,
        public readonly string $country,
        public readonly int $starRating,
        public readonly ?string $description,
        public readonly array $amenities,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {
    }

    public static function fromEntity(Hotel $hotel): self
    {
        return new self(
            id: $hotel->getId(),
            name: $hotel->getName(),
            address: $hotel->getAddress(),
            city: $hotel->getCity(),
            country: $hotel->getCountry(),
            starRating: $hotel->getStarRating(),
            description: $hotel->getDescription(),
            amenities: $hotel->getAmenities(),
            createdAt: $hotel->getCreatedAt()->format(\DateTimeInterface::ATOM),
            updatedAt: $hotel->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        );
    }

    /**
     * @param list<Hotel> $hotels
     *
     * @return list<self>
     */
    public static function fromEntities(array $hotels): array
    {
        return array_map(self::fromEntity(...), $hotels);
    }
}
