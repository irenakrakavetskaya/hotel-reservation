<?php

declare(strict_types=1);

namespace App\Domain\Payment\Controller;

use App\Domain\Payment\Dto\ProcessPaymentRequest;
use App\Domain\Payment\Service\PaymentService;
use App\Domain\Reservation\Dto\ReservationResponse;
use App\Domain\Reservation\Exception\ReservationNotFoundException;
use App\Domain\Reservation\Repository\ReservationRepository;
use App\Domain\Reservation\Security\ReservationVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Mock payment endpoint for v1. Updates reservation status from `pending` to
 * `paid` or `rejected`.
 */
#[Route('/v1/payments')]
final class PaymentController extends AbstractController
{
    public function __construct(
        private readonly PaymentService $paymentService,
        private readonly ReservationRepository $reservationRepository,
    ) {
    }

    /**
     * POST /v1/payments
     */
    #[Route('', name: 'payment_process', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function process(
        #[MapRequestPayload] ProcessPaymentRequest $request,
    ): JsonResponse {
        $reservationId = $this->parseId($request->reservationId);

        $reservation = $this->reservationRepository->find($reservationId)
            ?? throw ReservationNotFoundException::forId($reservationId);

        $this->denyAccessUnlessGranted(ReservationVoter::PAY, $reservation);

        $settledReservation = $this->paymentService->processReservationPayment($reservationId, $request->approved);
        $this->denyAccessUnlessGranted(ReservationVoter::PAY, $settledReservation);

        return $this->json(ReservationResponse::fromEntity($settledReservation));
    }

    private function parseId(string $id): Uuid
    {
        if (!Uuid::isValid($id)) {
            throw new UnprocessableEntityHttpException('Invalid reservation id.');
        }

        return Uuid::fromString($id);
    }
}
