<?php

declare(strict_types=1);

namespace App\Domain\Reservation\Entity;

use App\Domain\Reservation\Repository\ReservationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Mirrors the `reservation` table — see docs/IMPLEMENTATION_PLAN.md §1 and
 * §3 ("Concurrency & idempotency"), and migrations/Version20260101000006.php.
 *
 * IMPORTANT: `id` is client-supplied and doubles as the idempotency key
 * (CLAUDE.md invariant #1). There is deliberately no #[ORM\GeneratedValue]
 * on it. Never add one.
 *
 * IMPORTANT: this entity is a read/hydration convenience for the query side
 * (GET /v1/reservations, GET /v1/reservations/{id}). The actual reservation
 * write (POST) and cancellation (DELETE) go through
 * ReservationRepository::create()/cancel(), which combine the reservation
 * write with the room_type_inventory update in one native-SQL transaction.
 * Do not add a plain persist()/flush() path for creating reservations — see
 * `.claude/skills/reservation-domain/SKILL.md`.
 *
 * `totalPrice` is stored in integer minor units (cents).
 */
#[ORM\Entity(repositoryClass: ReservationRepository::class)]
#[ORM\Table(name: 'reservation')]
#[ORM\Index(columns: ['user_id'], name: 'idx_reservation_user_id')]
#[ORM\Index(columns: ['hotel_id', 'room_type_id', 'start_date', 'end_date'], name: 'idx_reservation_hotel_room_type_dates')]
#[ORM\Index(columns: ['status'], name: 'idx_reservation_status')]
class Reservation
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    /**
     * No FK constraint yet — the user/auth table isn't part of this data
     * model slice. See migrations/Version20260101000006.php.
     */
    #[ORM\Column(type: 'bigint')]
    private int $userId;

    #[ORM\Column(type: 'bigint')]
    private int $hotelId;

    #[ORM\Column(type: 'bigint')]
    private int $roomTypeId;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $startDate;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $endDate;

    #[ORM\Column(type: 'integer')]
    private int $roomCount;

    #[ORM\Column(type: 'string', length: 20, enumType: ReservationStatus::class)]
    private ReservationStatus $status;

    /** Integer cents. */
    #[ORM\Column(type: 'integer')]
    private int $totalPrice;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Uuid $id,
        int $userId,
        int $hotelId,
        int $roomTypeId,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
        int $roomCount,
        int $totalPrice,
        ReservationStatus $status = ReservationStatus::Pending,
    ) {
        $this->id = $id;
        $this->userId = $userId;
        $this->hotelId = $hotelId;
        $this->roomTypeId = $roomTypeId;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->roomCount = $roomCount;
        $this->totalPrice = $totalPrice;
        $this->status = $status;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getHotelId(): int
    {
        return $this->hotelId;
    }

    public function getRoomTypeId(): int
    {
        return $this->roomTypeId;
    }

    public function getStartDate(): \DateTimeImmutable
    {
        return $this->startDate;
    }

    public function getEndDate(): \DateTimeImmutable
    {
        return $this->endDate;
    }

    public function getRoomCount(): int
    {
        return $this->roomCount;
    }

    public function getStatus(): ReservationStatus
    {
        return $this->status;
    }

    public function getTotalPrice(): int
    {
        return $this->totalPrice;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
