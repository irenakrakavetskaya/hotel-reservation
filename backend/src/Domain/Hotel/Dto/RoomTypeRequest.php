<?php

declare(strict_types=1);

namespace App\Domain\Hotel\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Request body for POST/PUT /v1/hotels/{hotelId}/room-types[/{id}].
 */
final class RoomTypeRequest
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    public string $name;

    public ?string $description = null;

    #[Assert\NotNull]
    #[Assert\Positive]
    public int $maxOccupancy;

    /** Integer cents. */
    #[Assert\NotNull]
    #[Assert\GreaterThanOrEqual(0)]
    public int $basePrice;

    /** @var list<string> */
    #[Assert\Type('array')]
    #[Assert\All([
        new Assert\Type('string'),
        new Assert\Length(max: 100),
    ])]
    public array $amenities = [];
}
