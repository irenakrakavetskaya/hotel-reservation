<?php

declare(strict_types=1);

namespace App\Domain\Reservation\Dto;

use App\Domain\Reservation\Entity\Reservation;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Explicit API output shape for a reservation, per
 * `.claude/skills/api-scaffold/SKILL.md` step 5 — never serialize the
 * Doctrine entity directly, so the wire contract doesn't shift every time
 * the entity's internals change.
 */
final class ReservationResponse
{
    private function __construct(
        public readonly string $reservationId,
        #[SerializedName('hotelID')]
        public readonly int $hotelId,
        #[SerializedName('roomTypeID')]
        public readonly int $roomTypeId,
        public readonly string $startDate,
        public readonly string $endDate,
        public readonly int $roomCount,
        public readonly string $status,
        public readonly int $totalPrice,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {
    }

    public static function fromEntity(Reservation $reservation): self
    {
        return new self(
            reservationId: $reservation->getId()->toRfc4122(),
            hotelId: $reservation->getHotelId(),
            roomTypeId: $reservation->getRoomTypeId(),
            startDate: $reservation->getStartDate()->format('Y-m-d'),
            endDate: $reservation->getEndDate()->format('Y-m-d'),
            roomCount: $reservation->getRoomCount(),
            status: $reservation->getStatus()->value,
            totalPrice: $reservation->getTotalPrice(),
            createdAt: $reservation->getCreatedAt()->format(\DateTimeInterface::ATOM),
            updatedAt: $reservation->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        );
    }

    /**
     * @param list<Reservation> $reservations
     *
     * @return list<self>
     */
    public static function fromEntities(array $reservations): array
    {
        return array_map(self::fromEntity(...), $reservations);
    }
}
