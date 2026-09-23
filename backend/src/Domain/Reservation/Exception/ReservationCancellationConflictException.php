<?php

declare(strict_types=1);

namespace App\Domain\Reservation\Exception;

/**
 * Thrown when a cancellation cannot safely release inventory for the full
 * reservation window in one transaction.
 */
final class ReservationCancellationConflictException extends \RuntimeException
{
    public static function forRequest(
        int $hotelId,
        int $roomTypeId,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
        int $roomCount,
        ?\Throwable $previous = null,
    ): self {
        return new self(
            sprintf(
                'Unable to cancel reservation safely for hotel %d, room type %d between %s and %s for %d room(s).',
                $hotelId,
                $roomTypeId,
                $startDate->format('Y-m-d'),
                $endDate->format('Y-m-d'),
                $roomCount,
            ),
            previous: $previous,
        );
    }
}
