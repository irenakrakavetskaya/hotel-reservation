<?php

declare(strict_types=1);

namespace App\Domain\Payment\Service;

use App\Domain\Reservation\Entity\Reservation;
use App\Domain\Reservation\Repository\ReservationRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Mock payment processor orchestration for v1.
 *
 * The actual reservation status write is delegated to ReservationRepository so
 * all reservation mutations stay in one place.
 */
final class PaymentService
{
    public function __construct(private readonly ReservationRepository $reservationRepository)
    {
    }

    public function processReservationPayment(Uuid $reservationId, bool $approved): Reservation
    {
        return $this->reservationRepository->settlePayment($reservationId, $approved);
    }
}
