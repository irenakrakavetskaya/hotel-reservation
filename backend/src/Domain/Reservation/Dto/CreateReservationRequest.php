<?php

declare(strict_types=1);

namespace App\Domain\Reservation\Dto;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Mirrors the POST /v1/reservations request body from
 * docs/IMPLEMENTATION_PLAN.md §2, field names and casing included
 * (`hotelID`, `roomTypeID`, `reservationID`) so the wire contract matches
 * the original API design exactly.
 *
 * `reservationID` is the client-generated idempotency key — see CLAUDE.md
 * invariant #1 and `.claude/rules/frontend-nextjs.md`. It must be a v4/v7
 * UUID; the frontend generates it, not this API.
 *
 * Deserialized via #[MapRequestPayload] in ReservationController, which
 * validates these constraints and throws a 422 automatically on failure —
 * no manual validation needed in the controller.
 */
final class CreateReservationRequest
{
    #[Assert\NotBlank]
    #[Assert\Uuid(message: 'reservationID must be a valid UUID.')]
    #[SerializedName('reservationID')]
    public string $reservationId;

    #[Assert\Positive]
    #[SerializedName('hotelID')]
    public int $hotelId;

    #[Assert\Positive]
    #[SerializedName('roomTypeID')]
    public int $roomTypeId;

    #[Assert\NotBlank]
    #[Assert\Date]
    public string $startDate;

    #[Assert\NotBlank]
    #[Assert\Date]
    public string $endDate;

    #[Assert\Positive]
    public int $roomCount;

    public function getHotelId(): int
    {
        return $this->hotelId;
    }

    public function getRoomTypeId(): int
    {
        return $this->roomTypeId;
    }

    public function getRoomCount(): int
    {
        return $this->roomCount;
    }

    public function getStartDate(): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->startDate);
    }

    public function getEndDate(): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->endDate);
    }

    #[Assert\IsTrue(message: 'endDate must be after startDate.')]
    public function isDateRangeValid(): bool
    {
        return $this->getEndDate() > $this->getStartDate();
    }
}
