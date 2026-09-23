<?php

declare(strict_types=1);

namespace App\Domain\Reservation\Entity;

use App\Domain\Reservation\Repository\RoomTypeInventoryRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Mirrors the `room_type_inventory` table — see docs/IMPLEMENTATION_PLAN.md
 * §1 and §3 ("Concurrency & idempotency"), and
 * migrations/Version20260101000005.php.
 *
 * IMPORTANT: this entity is a read/hydration convenience. The actual
 * inventory increment/decrement on the booking and cancellation paths goes
 * through RoomTypeInventoryRepository's and ReservationRepository's native
 * SQL methods, inside an explicit transaction shared with the reservation
 * write — never through this entity's setters + flush(). See
 * `.claude/skills/reservation-domain/SKILL.md` before changing how writes
 * to this table work.
 *
 * Deliberately uses plain scalar `hotelId`/`roomTypeId` columns rather than
 * ManyToOne associations, for the same reason as RoomTypeRate.
 */
#[ORM\Entity(repositoryClass: RoomTypeInventoryRepository::class)]
#[ORM\Table(name: 'room_type_inventory')]
#[ORM\Index(columns: ['hotel_id', 'room_type_id', 'date'], name: 'idx_room_type_inventory_lookup')]
class RoomTypeInventory
{
    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    private int $hotelId;

    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    private int $roomTypeId;

    #[ORM\Id]
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $date;

    #[ORM\Column(type: 'integer')]
    private int $totalInventory;

    #[ORM\Column(type: 'integer')]
    private int $totalReserved = 0;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(int $hotelId, int $roomTypeId, \DateTimeImmutable $date, int $totalInventory)
    {
        $this->hotelId = $hotelId;
        $this->roomTypeId = $roomTypeId;
        $this->date = $date;
        $this->totalInventory = $totalInventory;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getHotelId(): int
    {
        return $this->hotelId;
    }

    public function getRoomTypeId(): int
    {
        return $this->roomTypeId;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getTotalInventory(): int
    {
        return $this->totalInventory;
    }

    public function getTotalReserved(): int
    {
        return $this->totalReserved;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Overbooking ceiling mirrored from the `check_room_count` DB constraint,
     * for fast-fail UX only (e.g. showing "sold out" before a form submit).
     * This is NOT the correctness guarantee — the DB constraint is. See
     * docs/IMPLEMENTATION_PLAN.md §3.
     */
    public function getAvailableCount(): int
    {
        $ceiling = (int) ($this->totalInventory * 1.1);

        return max(0, $ceiling - $this->totalReserved);
    }
}
