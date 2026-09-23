<?php

declare(strict_types=1);

namespace App\Domain\Reservation\Exception;

/**
 * Thrown when a reservation write would violate the `check_room_count`
 * overbooking constraint. Controllers should map this to HTTP 409.
 *
 * See docs/IMPLEMENTATION_PLAN.md §3 and
 * `.claude/skills/reservation-domain/SKILL.md`.
 */
final class InsufficientInventoryException extends \RuntimeException
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
                'Not enough inventory for hotel %d, room type %d between %s and %s for %d room(s).',
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
