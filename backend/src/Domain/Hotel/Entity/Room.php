<?php

declare(strict_types=1);

namespace App\Domain\Hotel\Entity;

use App\Domain\Hotel\Repository\RoomRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Mirrors the `room` table — see docs/IMPLEMENTATION_PLAN.md §1 and
 * migrations/Version20260101000003.php.
 *
 * Individual physical rooms, tracked for admin/maintenance purposes.
 * Reservations are made against room *types*, not specific rooms — do not
 * add a direct Room <-> Reservation relationship; that's a deliberate
 * design decision, not an oversight.
 */
#[ORM\Entity(repositoryClass: RoomRepository::class)]
#[ORM\Table(name: 'room')]
#[ORM\UniqueConstraint(name: 'uniq_room_hotel_room_number', columns: ['hotel_id', 'room_number'])]
#[ORM\Index(columns: ['hotel_id'], name: 'idx_room_hotel_id')]
#[ORM\Index(columns: ['room_type_id'], name: 'idx_room_room_type_id')]
class Room
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Hotel::class)]
    #[ORM\JoinColumn(name: 'hotel_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Hotel $hotel;

    #[ORM\ManyToOne(targetEntity: RoomType::class)]
    #[ORM\JoinColumn(name: 'room_type_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private RoomType $roomType;

    #[ORM\Column(length: 20)]
    private string $roomNumber;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $floor = null;

    #[ORM\Column(type: 'string', length: 20, enumType: RoomStatus::class)]
    private RoomStatus $status = RoomStatus::Active;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Hotel $hotel, RoomType $roomType, string $roomNumber, ?int $floor = null)
    {
        $this->hotel = $hotel;
        $this->roomType = $roomType;
        $this->roomNumber = $roomNumber;
        $this->floor = $floor;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getHotel(): Hotel
    {
        return $this->hotel;
    }

    public function getRoomType(): RoomType
    {
        return $this->roomType;
    }

    public function getRoomNumber(): string
    {
        return $this->roomNumber;
    }

    public function getFloor(): ?int
    {
        return $this->floor;
    }

    public function getStatus(): RoomStatus
    {
        return $this->status;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setStatus(RoomStatus $status): void
    {
        $this->status = $status;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function update(RoomType $roomType, string $roomNumber, ?int $floor, RoomStatus $status): void
    {
        $this->roomType = $roomType;
        $this->roomNumber = $roomNumber;
        $this->floor = $floor;
        $this->status = $status;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
