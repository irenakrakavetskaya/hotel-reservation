<?php

declare(strict_types=1);

namespace App\Domain\Reservation\Controller;

use App\Domain\Reservation\Dto\CreateReservationRequest;
use App\Domain\Reservation\Dto\ReservationResponse;
use App\Domain\Reservation\Exception\ReservationNotFoundException;
use App\Domain\Reservation\Security\ReservationVoter;
use App\Domain\Reservation\Service\ReservationService;
use App\Domain\User\Security\AppUserInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Reservation-related APIs from docs/IMPLEMENTATION_PLAN.md §2.
 *
 * Kept thin per `.claude/rules/backend-symfony.md`: deserialize (via
 * #[MapRequestPayload], which also validates and throws 422 automatically)
 * → authorize (via voter) → call ReservationService → serialize via
 * ReservationResponse. All booking/cancellation logic lives in
 * ReservationService and ReservationRepository, not here.
 */
#[Route('/v1/reservations')]
final class ReservationController extends AbstractController
{
    public function __construct(
        private readonly ReservationService $reservationService,
    ) {
    }

    /**
     * GET /v1/reservations — the logged-in user's reservation history.
     */
    #[Route('', name: 'reservation_list', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function list(#[CurrentUser] AppUserInterface $user): JsonResponse
    {
        $reservations = $this->reservationService->listForUser($user->getId());

        return $this->json(ReservationResponse::fromEntities($reservations));
    }

    /**
     * GET /v1/reservations/{id} — a single reservation's detail.
     */
    #[Route('/{id}', name: 'reservation_get', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function get(string $id): JsonResponse
    {
        $reservationId = $this->parseId($id);
        $reservation = $this->reservationService->getReservation($reservationId)
            ?? throw ReservationNotFoundException::forId($reservationId);

        $this->denyAccessUnlessGranted(ReservationVoter::VIEW, $reservation);

        return $this->json(ReservationResponse::fromEntity($reservation));
    }

    /**
     * POST /v1/reservations — make a new reservation.
     *
     * `reservationID` in the body is the idempotency key: calling this
     * twice with the same value returns the same reservation both times
     * rather than creating a duplicate or erroring. See CLAUDE.md
     * invariant #1.
     */
    #[Route('', name: 'reservation_create', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function create(
        #[MapRequestPayload] CreateReservationRequest $request,
        #[CurrentUser] AppUserInterface $user,
    ): JsonResponse {
        $reservation = $this->reservationService->createReservation($request, $user->getId());
        $this->denyAccessUnlessGranted(ReservationVoter::VIEW, $reservation);

        return $this->json(
            ReservationResponse::fromEntity($reservation),
            Response::HTTP_CREATED,
            ['Location' => $this->generateUrl('reservation_get', ['id' => $reservation->getId()->toRfc4122()])],
        );
    }

    /**
     * DELETE /v1/reservations/{id} — cancel a reservation. Idempotent:
     * canceling an already-canceled reservation is a no-op, not an error.
     */
    #[Route('/{id}', name: 'reservation_cancel', methods: ['DELETE'])]
    #[IsGranted('ROLE_USER')]
    public function cancel(string $id): JsonResponse
    {
        $reservationId = $this->parseId($id);

        $existing = $this->reservationService->getReservation($reservationId)
            ?? throw ReservationNotFoundException::forId($reservationId);
        $this->denyAccessUnlessGranted(ReservationVoter::CANCEL, $existing);

        $reservation = $this->reservationService->cancelReservation($reservationId);

        return $this->json(ReservationResponse::fromEntity($reservation));
    }

    private function parseId(string $id): Uuid
    {
        if (!Uuid::isValid($id)) {
            throw new UnprocessableEntityHttpException('Invalid reservation id.');
        }

        return Uuid::fromString($id);
    }
}
