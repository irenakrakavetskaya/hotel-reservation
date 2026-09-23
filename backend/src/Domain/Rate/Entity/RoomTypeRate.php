<?php

declare(strict_types=1);

namespace App\Domain\Rate\Entity;

use App\Domain\Rate\Repository\RoomTypeRateRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Mirrors the `room_type_rate` table — see docs/IMPLEMENTATION_PLAN.md §1
 * and §4 ("Dynamic pricing"), and migrations/Version20260101000004.php.
 *
 * Deliberately holds `hotelId`/`roomTypeId` as plain scalar columns rather
 * than ManyToOne associations, matching `.claude/rules/backend-symfony.md`'s
 * guidance that this table is populated/updated by the pricing job via
 * native upsert (see RoomTypeRateRepository::upsertRate()), not through the
 * ORM's unit-of-work — associations would add hydration overhead with no
 * benefit on that write path.
 *
 * `price` is stored in integer minor units (cents).
 */
#[ORM\Entity(repositoryClass: RoomTypeRateRepository::class)]
#[ORM\Table(name: 'room_type_rate')]
#[ORM\Index(columns: ['hotel_id', 'room_type_id', 'date'], name: 'idx_room_type_rate_lookup')]
class RoomTypeRate
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

    /** Integer cents. */
    #[ORM\Column(type: 'integer')]
    private int $price;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(int $hotelId, int $roomTypeId, \DateTimeImmutable $date, int $price)
    {
        $this->hotelId = $hotelId;
        $this->roomTypeId = $roomTypeId;
        $this->date = $date;
        $this->price = $price;
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

    public function getPrice(): int
    {
        return $this->price;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
