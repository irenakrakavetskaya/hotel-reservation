<?php

declare(strict_types=1);

namespace App\Domain\Hotel\Exception;

/**
 * Thrown when a room type URL resource doesn't exist under the requested
 * hotel (GET/PUT/DELETE /v1/hotels/{hotelId}/room-types/{id}).
 */
final class RoomTypeResourceNotFoundException extends \RuntimeException
{
    public static function forId(int $hotelId, int $roomTypeId): self
    {
        return new self(sprintf('Room type %d not found for hotel %d.', $roomTypeId, $hotelId));
    }
}
