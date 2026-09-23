<?php

declare(strict_types=1);

namespace App\Domain\Payment\Exception;

use App\Domain\Reservation\Entity\ReservationStatus;
use Symfony\Component\Uid\Uuid;

/**
 * Thrown when a payment attempt targets a reservation in a state that cannot
 * transition to the requested payment outcome.
 */
final class ReservationPaymentStateException extends \RuntimeException
{
    public static function forStatus(
        Uuid $reservationId,
        ReservationStatus $currentStatus,
        ReservationStatus $requestedStatus,
    ): self {
        return new self(sprintf(
            'Cannot transition reservation %s from %s to %s via payment processing.',
            $reservationId->toRfc4122(),
            $currentStatus->value,
            $requestedStatus->value,
        ));
    }
}
