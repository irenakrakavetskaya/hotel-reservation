<?php

declare(strict_types=1);

namespace App\Domain\Hotel\Exception;

/** Controllers should map this to HTTP 404 (see DomainExceptionListener). */
final class RoomNotFoundException extends \RuntimeException
{
    public static function forId(int $hotelId, int $roomId): self
    {
        return new self(sprintf('Room %d not found for hotel %d.', $roomId, $hotelId));
    }
}
