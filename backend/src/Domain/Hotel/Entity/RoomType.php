<?php

declare(strict_types=1);

namespace App\Domain\Hotel\Entity;

use App\Domain\Hotel\Repository\RoomTypeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Mirrors the `room_type` table — see docs/IMPLEMENTATION_PLAN.md §1 and
 * migrations/Version20260101000002.php.
 *
 * `basePrice` is stored in integer minor units (cents), per
 * .claude/rules/backend-symfony.md — never floats for money. Convert to a
 * decimal string only at the API serialization boundary, if the contract
 * requires it.
 */
#[ORM\Entity(repositoryClass: RoomTypeRepository::class)]
#[ORM\Table(name: 'room_type')]
#[ORM\Index(columns: ['hotel_id'], name: 'idx_room_type_hotel_id')]
class RoomType
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Hotel::class)]
    #[ORM\JoinColumn(name: 'hotel_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Hotel $hotel;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'smallint')]
    private int $maxOccupancy;

    /** Integer cents. */
    #[ORM\Column(type: 'integer')]
    private int $basePrice;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $amenities = [];

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Hotel $hotel,
        string $name,
        int $maxOccupancy,
        int $basePrice,
        ?string $description = null,
        array $amenities = [],
    ) {
        $this->hotel = $hotel;
        $this->name = $name;
        $this->description = $description;
        $this->maxOccupancy = $maxOccupancy;
        $this->basePrice = $basePrice;
        $this->amenities = $amenities;
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

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getMaxOccupancy(): int
    {
        return $this->maxOccupancy;
    }

    public function getBasePrice(): int
    {
        return $this->basePrice;
    }

    /** @return list<string> */
    public function getAmenities(): array
    {
        return $this->amenities;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function update(
        string $name,
        ?string $description,
        int $maxOccupancy,
        int $basePrice,
        array $amenities,
    ): void {
        $this->name = $name;
        $this->description = $description;
        $this->maxOccupancy = $maxOccupancy;
        $this->basePrice = $basePrice;
        $this->amenities = $amenities;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
