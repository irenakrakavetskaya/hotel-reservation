<?php

declare(strict_types=1);

namespace App\Domain\Hotel\Exception;

/**
 * Thrown when a room create/update references a `roomTypeId` that doesn't
 * exist, or that belongs to a different hotel than the room being written.
 * Controllers should map this to HTTP 422 (see DomainExceptionListener) —
 * it's a validation failure of the request body, not a missing URL
 * resource.
 */
final class RoomTypeNotFoundException extends \RuntimeException
{
    public static function forId(int $hotelId, int $roomTypeId): self
    {
        return new self(sprintf('Room type %d not found for hotel %d.', $roomTypeId, $hotelId));
    }
}
