<?php

declare(strict_types=1);

namespace App\Domain\Hotel\Entity;

/**
 * Mirrors the `status` CHECK constraint on the `room` table
 * (migrations/Version20260101000003.php): `active`, `maintenance`, `inactive`.
 */
enum RoomStatus: string
{
    case Active = 'active';
    case Maintenance = 'maintenance';
    case Inactive = 'inactive';
}
