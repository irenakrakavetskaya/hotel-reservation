<?php

declare(strict_types=1);

namespace App\Domain\Hotel\Exception;

/**
 * Thrown when deleting a hotel or room type that still has reservations
 * (reservation.hotel_id / room_type_id are ON DELETE RESTRICT). Mapped to
 * HTTP 409 by DomainExceptionListener.
 */
final class ResourceHasReservationsException extends \RuntimeException
{
    public static function forHotel(int $hotelId, ?\Throwable $previous = null): self
    {
        return new self(sprintf('Hotel %d has reservations and cannot be deleted.', $hotelId), previous: $previous);
    }

    public static function forRoomType(int $roomTypeId, ?\Throwable $previous = null): self
    {
        return new self(sprintf('Room type %d has reservations and cannot be deleted.', $roomTypeId), previous: $previous);
    }
}
