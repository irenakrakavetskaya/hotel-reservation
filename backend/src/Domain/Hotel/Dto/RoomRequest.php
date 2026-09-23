<?php

declare(strict_types=1);

namespace App\Domain\Hotel\Dto;

use App\Domain\Hotel\Entity\RoomStatus;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Request body for POST /v1/hotels/{hotelId}/rooms and
 * PUT /v1/hotels/{hotelId}/rooms/{id}.
 */
final class RoomRequest
{
    #[Assert\NotNull]
    #[Assert\Positive]
    public int $roomTypeId;

    #[Assert\NotBlank]
    #[Assert\Length(max: 20)]
    public string $roomNumber;

    public ?int $floor = null;

    #[Assert\Choice(callback: [self::class, 'statusChoices'])]
    public string $status = 'active';

    /** @return list<string> */
    public static function statusChoices(): array
    {
        return array_map(static fn (RoomStatus $s) => $s->value, RoomStatus::cases());
    }

    public function getStatus(): RoomStatus
    {
        return RoomStatus::from($this->status);
    }
}
