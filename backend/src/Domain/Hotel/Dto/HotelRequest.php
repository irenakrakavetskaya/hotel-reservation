<?php

declare(strict_types=1);

namespace App\Domain\Hotel\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Request body for POST /v1/hotels and PUT /v1/hotels/{id}. PUT uses the
 * same shape as POST (full-replace semantics) rather than a separate patch
 * DTO — simplest option given nothing in docs/IMPLEMENTATION_PLAN.md calls
 * for partial updates.
 */
final class HotelRequest
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public string $name;

    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public string $address;

    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    public string $city;

    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    public string $country;

    #[Assert\NotNull]
    #[Assert\Range(min: 1, max: 5)]
    public int $starRating;

    public ?string $description = null;

    /** @var list<string> */
    #[Assert\All([new Assert\Type('string')])]
    public array $amenities = [];
}
