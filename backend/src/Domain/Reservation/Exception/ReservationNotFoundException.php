<?php

declare(strict_types=1);

namespace App\Domain\Reservation\Exception;

use Symfony\Component\Uid\Uuid;

/**
 * Thrown when a reservation ID doesn't exist. Controllers should map this
 * to HTTP 404.
 */
final class ReservationNotFoundException extends \RuntimeException
{
    public static function forId(Uuid $id): self
    {
        return new self(sprintf('Reservation %s not found.', $id->toRfc4122()));
    }
}
