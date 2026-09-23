<?php

declare(strict_types=1);

namespace App\Domain\Reservation\Service;

use App\Domain\Rate\Repository\RoomTypeRateRepository;
use App\Domain\Reservation\Dto\CreateReservationRequest;
use App\Domain\Reservation\Entity\Reservation;
use App\Domain\Reservation\Entity\ReservationStatus;
use App\Domain\Reservation\Exception\InsufficientInventoryException;
use App\Domain\Reservation\Repository\ReservationRepository;
use App\Domain\Reservation\Repository\RoomTypeInventoryRepository;
use App\Infrastructure\Cache\CacheScopes;
use App\Infrastructure\Cache\ReadCacheVersionService;
use Symfony\Component\Uid\Uuid;

/**
 * Orchestrates reservation creation/cancellation. Controllers call this,
 * never ReservationRepository directly — see
 * `.claude/rules/backend-symfony.md` ("no business logic in controllers").
 */
final class ReservationService
{
    public function __construct(
        private readonly ReservationRepository $reservationRepository,
        private readonly RoomTypeInventoryRepository $inventoryRepository,
        private readonly RoomTypeRateRepository $rateRepository,
        private readonly ReadCacheVersionService $cacheVersions,
    ) {
    }

    /**
     * @throws InsufficientInventoryException if the DB constraint rejects the booking
     */
    public function createReservation(CreateReservationRequest $request, int $userId): Reservation
    {
        $reservationId = Uuid::fromString($request->reservationId);
        $existing = $this->reservationRepository->find($reservationId);
        if (null !== $existing) {
            return $existing;
        }

        $hotelId = $request->getHotelId();
        $roomTypeId = $request->getRoomTypeId();
        $startDate = $request->getStartDate();
        $endDate = $request->getEndDate();
        $roomCount = $request->getRoomCount();

        // Fast-fail UX check only — NOT the correctness guarantee. The
        // check_room_count DB constraint, enforced inside
        // ReservationRepository::create(), is what actually prevents
        // overbooking. See docs/IMPLEMENTATION_PLAN.md §3.
        if (!$this->inventoryRepository->hasAvailability($hotelId, $roomTypeId, $startDate, $endDate, $roomCount)) {
            throw InsufficientInventoryException::forRequest($hotelId, $roomTypeId, $startDate, $endDate, $roomCount);
        }

        $totalPrice = $this->calculateTotalPrice($hotelId, $roomTypeId, $startDate, $endDate, $roomCount);

        $reservation = new Reservation(
            id: $reservationId,
            userId: $userId,
            hotelId: $hotelId,
            roomTypeId: $roomTypeId,
            startDate: $startDate,
            endDate: $endDate,
            roomCount: $roomCount,
            totalPrice: $totalPrice,
            status: ReservationStatus::Pending,
        );

        // The repository re-derives the guarantee from the DB constraint
        // regardless of the fast-fail check above, and is what actually
        // throws InsufficientInventoryException on a genuine race.
        $created = $this->reservationRepository->create($reservation);
        $this->inventoryRepository->refreshAvailabilityCacheForRange($hotelId, $roomTypeId, $startDate, $endDate);
        $this->cacheVersions->bump(CacheScopes::availabilityRead($hotelId, $roomTypeId));

        return $created;
    }

    public function cancelReservation(Uuid $id): Reservation
    {
        $reservation = $this->reservationRepository->cancel($id);
        $this->inventoryRepository->refreshAvailabilityCacheForRange(
            $reservation->getHotelId(),
            $reservation->getRoomTypeId(),
            $reservation->getStartDate(),
            $reservation->getEndDate(),
        );
        $this->cacheVersions->bump(CacheScopes::availabilityRead($reservation->getHotelId(), $reservation->getRoomTypeId()));

        return $reservation;
    }

    public function getReservation(Uuid $id): ?Reservation
    {
        return $this->reservationRepository->find($id);
    }

    /** @return list<Reservation> */
    public function listForUser(int $userId): array
    {
        return $this->reservationRepository->findForUser($userId);
    }

    /**
     * Sums the pre-computed per-date rate (docs/IMPLEMENTATION_PLAN.md §4)
     * across the stay, times room count. Never calculates a price live from
     * occupancy here — that's RateRecomputeJob's job, on its own schedule.
     */
    private function calculateTotalPrice(
        int $hotelId,
        int $roomTypeId,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
        int $roomCount,
    ): int {
        // Stay nights are [startDate, endDate) — the checkout day itself
        // isn't a booked night.
        $rates = $this->rateRepository->findForDateRange($hotelId, $roomTypeId, $startDate, $endDate);
        $expectedNights = $startDate->diff($endDate)->days;

        if (count($rates) !== $expectedNights) {
            throw InsufficientInventoryException::forRequest($hotelId, $roomTypeId, $startDate, $endDate, $roomCount);
        }

        $nightlyTotal = array_sum($rates);

        return $nightlyTotal * $roomCount;
    }
}
