<?php

declare(strict_types=1);

namespace App\Domain\Reservation\Entity;

/**
 * Mirrors the `status` CHECK constraint on the `reservation` table
 * (migrations/Version20260101000006.php).
 */
enum ReservationStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Refunded = 'refunded';
    case Canceled = 'canceled';
    case Rejected = 'rejected';
}
