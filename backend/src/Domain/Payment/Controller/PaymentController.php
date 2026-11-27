<?php

declare(strict_types=1);

namespace App\Domain\Payment\Controller;

use App\Domain\Payment\Dto\ProcessPaymentRequest;
use App\Domain\Payment\Service\PaymentService;
use App\Domain\Reservation\Dto\ReservationResponse;
use App\Domain\Reservation\Exception\ReservationNotFoundException;
use App\Domain\Reservation\Repository\ReservationRepository;
use App\Domain\Reservation\Security\ReservationVoter;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
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
#[OA\Tag(name: 'Payments')]
#[OA\Response(response: 401, description: 'Authentication required')]
#[OA\Response(response: 403, description: 'Access denied')]
final class PaymentController extends AbstractController
{
    public function __construct(
        private readonly PaymentService $paymentService,
        private readonly ReservationRepository $reservationRepository,
    ) {}

    /**
     * POST /v1/payments
     */
    #[Route('', name: 'payment_process', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[OA\Post(security: [['Bearer' => []]])]
    #[OA\Response(response: 200, description: 'Settled reservation', content: new Model(type: ReservationResponse::class))]
    #[OA\Response(response: 404, description: 'Reservation not found', content: new OA\JsonContent(ref: '#/components/schemas/DomainError'))]
    #[OA\Response(response: 409, description: 'Invalid payment state', content: new OA\JsonContent(ref: '#/components/schemas/DomainError'))]
    #[OA\Response(response: 422, description: 'Invalid request; JSON problem details when requested', content: new OA\JsonContent(ref: '#/components/schemas/HttpProblem'))]
    public function process(
        #[MapRequestPayload]
        ProcessPaymentRequest $request,
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
