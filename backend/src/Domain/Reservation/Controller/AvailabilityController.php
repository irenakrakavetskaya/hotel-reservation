<?php

declare(strict_types=1);

namespace App\Domain\Reservation\Controller;

use App\Domain\Reservation\Dto\AvailabilityQueryRequest;
use App\Domain\Reservation\Service\AvailabilityQueryService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Customer-facing availability + rate query endpoint used by booking flows
 * to re-validate a stay range before reservation creation.
 */
final class AvailabilityController extends AbstractController
{
    public function __construct(
        private readonly AvailabilityQueryService $availabilityQueryService,
        private readonly ValidatorInterface $validator,
    )
    {
    }

    #[Route('/v1/hotels/{hotelId<\d+>}/room-types/{roomTypeId<\d+>}/availability', name: 'room_type_availability', methods: ['GET'])]
    public function getAvailability(
        int $hotelId,
        int $roomTypeId,
        Request $request,
    ): JsonResponse {
        $startDate = $request->query->get('startDate');
        $endDate = $request->query->get('endDate');
        $roomCount = $request->query->get('roomCount', '1');
        if (!is_string($startDate) || !is_string($endDate) || !is_string($roomCount)) {
            throw new UnprocessableEntityHttpException('startDate, endDate, and roomCount must be scalar query values.');
        }

        $query = new AvailabilityQueryRequest();
        $query->startDate = $startDate;
        $query->endDate = $endDate;
        $query->roomCount = $roomCount;
        $violations = $this->validator->validate($query);
        if (count($violations) > 0) {
            throw new UnprocessableEntityHttpException((string) $violations);
        }

        $availability = $this->availabilityQueryService->getAvailability($hotelId, $roomTypeId, $query);

        return $this->json($availability->toArray());
    }
}
