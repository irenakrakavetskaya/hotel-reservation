<?php

declare(strict_types=1);

namespace App\Domain\Hotel\Entity;

use App\Domain\Hotel\Repository\HotelRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Mirrors the `hotel` table — see docs/IMPLEMENTATION_PLAN.md §1 and
 * migrations/Version20260101000001.php. Keep both in sync with this file.
 */
#[ORM\Entity(repositoryClass: HotelRepository::class)]
#[ORM\Table(name: 'hotel')]
#[ORM\Index(columns: ['city'], name: 'idx_hotel_city')]
class Hotel
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 255)]
    private string $address;

    #[ORM\Column(length: 120)]
    private string $city;

    #[ORM\Column(length: 120)]
    private string $country;

    /** 1-5 — enforced by the `star_rating` CHECK constraint in the DB. */
    #[ORM\Column(type: 'smallint')]
    private int $starRating;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $amenities = [];

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $name,
        string $address,
        string $city,
        string $country,
        int $starRating,
        ?string $description = null,
        array $amenities = [],
    ) {
        $this->name = $name;
        $this->address = $address;
        $this->city = $city;
        $this->country = $country;
        $this->starRating = $starRating;
        $this->description = $description;
        $this->amenities = $amenities;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getAddress(): string
    {
        return $this->address;
    }

    public function getCity(): string
    {
        return $this->city;
    }

    public function getCountry(): string
    {
        return $this->country;
    }

    public function getStarRating(): int
    {
        return $this->starRating;
    }

    public function getDescription(): ?string
    {
        return $this->description;
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
        string $address,
        string $city,
        string $country,
        int $starRating,
        ?string $description,
        array $amenities,
    ): void {
        $this->name = $name;
        $this->address = $address;
        $this->city = $city;
        $this->country = $country;
        $this->starRating = $starRating;
        $this->description = $description;
        $this->amenities = $amenities;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
