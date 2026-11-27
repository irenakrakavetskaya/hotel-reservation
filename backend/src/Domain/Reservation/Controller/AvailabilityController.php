<?php

declare(strict_types=1);

namespace App\Domain\Reservation\Controller;

use App\Domain\Reservation\Dto\AvailabilityQueryRequest;
use App\Domain\Reservation\Service\AvailabilityQueryService;
use OpenApi\Attributes as OA;
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
    ) {}

    #[Route('/v1/hotels/{hotelId<\d+>}/room-types/{roomTypeId<\d+>}/availability', name: 'room_type_availability', methods: ['GET'])]
    #[OA\Tag(name: 'Availability')]
    #[OA\Get(security: [])]
    #[OA\Parameter(name: 'startDate', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date'))]
    #[OA\Parameter(name: 'endDate', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date'))]
    #[OA\Parameter(name: 'roomCount', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, default: 1))]
    #[OA\Response(response: 200, description: 'Availability and per-date rates', content: new OA\JsonContent(ref: '#/components/schemas/Availability'))]
    #[OA\Response(response: 404, description: 'Hotel or room type not found', content: new OA\JsonContent(ref: '#/components/schemas/DomainError'))]
    #[OA\Response(response: 422, description: 'Invalid date range or room count; JSON problem details when requested', content: new OA\JsonContent(ref: '#/components/schemas/HttpProblem'))]
    public function getAvailability(
        int $hotelId,
        int $roomTypeId,
        Request $request,
    ): JsonResponse {
        $startDate = $request->query->get('startDate');
        $endDate = $request->query->get('endDate');
        $roomCount = $request->query->get('roomCount', '1');
        if (!is_string($startDate) || !is_string($endDate)) {
            throw new UnprocessableEntityHttpException('startDate and endDate are required query values.');
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
