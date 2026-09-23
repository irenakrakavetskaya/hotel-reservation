<?php

declare(strict_types=1);

namespace App\Domain\Reservation\Controller;

use App\Domain\Reservation\Dto\AvailabilityQueryRequest;
use App\Domain\Reservation\Service\AvailabilityQueryService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Customer-facing availability + rate query endpoint used by booking flows
 * to re-validate a stay range before reservation creation.
 */
final class AvailabilityController extends AbstractController
{
    public function __construct(private readonly AvailabilityQueryService $availabilityQueryService)
    {
    }

    #[Route('/v1/hotels/{hotelId<\d+>}/room-types/{roomTypeId<\d+>}/availability', name: 'room_type_availability', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function getAvailability(
        int $hotelId,
        int $roomTypeId,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] AvailabilityQueryRequest $query,
    ): JsonResponse {
        $availability = $this->availabilityQueryService->getAvailability($hotelId, $roomTypeId, $query);

        return $this->json($availability->toArray());
    }
}
