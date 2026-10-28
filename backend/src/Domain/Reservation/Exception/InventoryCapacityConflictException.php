<?php

declare(strict_types=1);

namespace App\Domain\Reservation\Exception;

/**
 * Thrown when a room change would reduce a room type's inventory below what
 * is already held by reservations on some future date.
 */
final class InventoryCapacityConflictException extends \RuntimeException
{
    public static function forRoomType(int $hotelId, int $roomTypeId, ?\Throwable $previous = null): self
    {
        return new self(
            sprintf(
                'Change rejected: future reservations for hotel %d, room type %d already hold more rooms than the resulting capacity allows.',
                $hotelId,
                $roomTypeId,
            ),
            previous: $previous,
        );
    }
}
