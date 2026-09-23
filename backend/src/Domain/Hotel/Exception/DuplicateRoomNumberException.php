<?php

declare(strict_types=1);

namespace App\Domain\Hotel\Exception;

/**
 * Thrown when a room create/update would violate
 * `uniq_room_hotel_room_number` (migrations/Version20260101000003.php).
 * Controllers should map this to HTTP 409 (see DomainExceptionListener).
 */
final class DuplicateRoomNumberException extends \RuntimeException
{
    public static function forNumber(int $hotelId, string $roomNumber, ?\Throwable $previous = null): self
    {
        return new self(
            sprintf('Room number "%s" already exists for hotel %d.', $roomNumber, $hotelId),
            previous: $previous,
        );
    }
}
