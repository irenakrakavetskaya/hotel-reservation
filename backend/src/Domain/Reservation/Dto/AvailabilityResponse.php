<?php

declare(strict_types=1);

namespace App\Domain\Reservation\Dto;

/**
 * Explicit output shape for the availability/rate endpoint.
 */
final class AvailabilityResponse
{
    /**
     * @param list<array{
     *   date: string,
     *   totalInventory: int,
     *   totalReserved: int,
     *   availableRooms: int,
     *   price: int|null
     * }> $days
     */
    public function __construct(
        public readonly int $hotelId,
        public readonly int $roomTypeId,
        public readonly string $startDate,
        public readonly string $endDate,
        public readonly int $roomCount,
        public readonly int $nights,
        public readonly bool $canBook,
        public readonly ?int $totalPrice,
        public readonly int $minAvailableRooms,
        public readonly array $days,
    ) {
    }

    /**
     * @return array{
     *   hotelID: int,
     *   roomTypeID: int,
     *   startDate: string,
     *   endDate: string,
     *   roomCount: int,
     *   nights: int,
     *   canBook: bool,
     *   totalPrice: int|null,
     *   minAvailableRooms: int,
     *   days: list<array{
     *     date: string,
     *     totalInventory: int,
     *     totalReserved: int,
     *     availableRooms: int,
     *     price: int|null
     *   }>
     * }
     */
    public function toArray(): array
    {
        return [
            'hotelID' => $this->hotelId,
            'roomTypeID' => $this->roomTypeId,
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'roomCount' => $this->roomCount,
            'nights' => $this->nights,
            'canBook' => $this->canBook,
            'totalPrice' => $this->totalPrice,
            'minAvailableRooms' => $this->minAvailableRooms,
            'days' => $this->days,
        ];
    }

    /**
     * @param array{
     *   hotelID: int,
     *   roomTypeID: int,
     *   startDate: string,
     *   endDate: string,
     *   roomCount: int,
     *   nights: int,
     *   canBook: bool,
     *   totalPrice: int|null,
     *   minAvailableRooms: int,
     *   days: list<array{
     *     date: string,
     *     totalInventory: int,
     *     totalReserved: int,
     *     availableRooms: int,
     *     price: int|null
     *   }>
     * } $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            hotelId: $payload['hotelID'],
            roomTypeId: $payload['roomTypeID'],
            startDate: $payload['startDate'],
            endDate: $payload['endDate'],
            roomCount: $payload['roomCount'],
            nights: $payload['nights'],
            canBook: $payload['canBook'],
            totalPrice: $payload['totalPrice'],
            minAvailableRooms: $payload['minAvailableRooms'],
            days: $payload['days'],
        );
    }
}
