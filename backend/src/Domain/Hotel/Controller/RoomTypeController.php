<?php

declare(strict_types=1);

namespace App\Domain\Hotel\Controller;

use App\Domain\Hotel\Dto\RoomTypeRequest;
use App\Domain\Hotel\Dto\RoomTypeResponse;
use App\Domain\Hotel\Service\RoomTypeService;
use App\Infrastructure\Cache\CacheScopes;
use App\Infrastructure\Cache\ReadCacheVersionService;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Room-type APIs from docs/IMPLEMENTATION_PLAN.md §2.
 *
 * Reservations are made against room types, while physical rooms are managed
 * by RoomController.
 */
#[Route('/v1/hotels/{hotelId<\d+>}/room-types')]
#[OA\Tag(name: 'Room types')]
final class RoomTypeController extends AbstractController
{
    private const CACHE_TTL_SECONDS = 300;

    public function __construct(
        private readonly RoomTypeService $roomTypeService,
        private readonly CacheItemPoolInterface $cache,
        private readonly ReadCacheVersionService $cacheVersions,
    ) {}

    #[Route('', name: 'room_type_list', methods: ['GET'])]
    #[OA\Get(security: [])]
    #[OA\Response(response: 200, description: 'Room types', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: RoomTypeResponse::class))))]
    #[OA\Response(response: 404, description: 'Hotel not found', content: new OA\JsonContent(ref: '#/components/schemas/DomainError'))]
    public function list(int $hotelId): JsonResponse
    {
        $scopeVersion = $this->cacheVersions->getVersion(CacheScopes::roomTypeRead($hotelId));
        $cacheKey = sprintf('room-type.list.%d.v%d', $hotelId, $scopeVersion);
        $cacheItem = $this->cache->getItem($cacheKey);

        if ($cacheItem->isHit() && is_array($cacheItem->get())) {
            return $this->json($cacheItem->get());
        }

        $responses = RoomTypeResponse::fromEntities($this->roomTypeService->listRoomTypes($hotelId));
        $payload = array_map(static fn(RoomTypeResponse $roomType): array => get_object_vars($roomType), $responses);

        $cacheItem->set($payload);
        $cacheItem->expiresAfter(self::CACHE_TTL_SECONDS);
        $this->cache->save($cacheItem);

        return $this->json($payload);
    }

    #[Route('/{id<\d+>}', name: 'room_type_get', methods: ['GET'])]
    #[OA\Get(security: [])]
    #[OA\Response(response: 200, description: 'Room type', content: new Model(type: RoomTypeResponse::class))]
    #[OA\Response(response: 404, description: 'Room type not found', content: new OA\JsonContent(ref: '#/components/schemas/DomainError'))]
    public function get(int $hotelId, int $id): JsonResponse
    {
        $scopeVersion = $this->cacheVersions->getVersion(CacheScopes::roomTypeRead($hotelId));
        $cacheKey = sprintf('room-type.get.%d.%d.v%d', $hotelId, $id, $scopeVersion);
        $cacheItem = $this->cache->getItem($cacheKey);

        if ($cacheItem->isHit() && is_array($cacheItem->get())) {
            return $this->json($cacheItem->get());
        }

        $response = RoomTypeResponse::fromEntity($this->roomTypeService->getRoomType($hotelId, $id));
        $payload = get_object_vars($response);
        $cacheItem->set($payload);
        $cacheItem->expiresAfter(self::CACHE_TTL_SECONDS);
        $this->cache->save($cacheItem);

        return $this->json($payload);
    }

    #[Route('', name: 'room_type_create', methods: ['POST'])]
    #[IsGranted('ROLE_STAFF')]
    #[OA\Post(security: [['Bearer' => []]])]
    #[OA\Response(response: 401, description: 'Authentication required')]
    #[OA\Response(response: 403, description: 'Staff access required')]
    #[OA\Response(response: 201, description: 'Room type created', headers: [new OA\Header(header: 'Location', schema: new OA\Schema(type: 'string'))], content: new Model(type: RoomTypeResponse::class))]
    #[OA\Response(response: 404, description: 'Hotel not found', content: new OA\JsonContent(ref: '#/components/schemas/DomainError'))]
    #[OA\Response(response: 422, description: 'Invalid request; JSON problem details when requested', content: new OA\JsonContent(ref: '#/components/schemas/HttpProblem'))]
    public function create(int $hotelId, #[MapRequestPayload] RoomTypeRequest $request): JsonResponse
    {
        $roomType = $this->roomTypeService->createRoomType($hotelId, $request);

        return $this->json(
            RoomTypeResponse::fromEntity($roomType),
            Response::HTTP_CREATED,
            ['Location' => $this->generateUrl('room_type_get', ['hotelId' => $hotelId, 'id' => $roomType->getId()])],
        );
    }

    #[Route('/{id<\d+>}', name: 'room_type_update', methods: ['PUT'])]
    #[IsGranted('ROLE_STAFF')]
    #[OA\Put(security: [['Bearer' => []]])]
    #[OA\Response(response: 401, description: 'Authentication required')]
    #[OA\Response(response: 403, description: 'Staff access required')]
    #[OA\Response(response: 200, description: 'Updated room type', content: new Model(type: RoomTypeResponse::class))]
    #[OA\Response(response: 404, description: 'Room type not found', content: new OA\JsonContent(ref: '#/components/schemas/DomainError'))]
    #[OA\Response(response: 422, description: 'Invalid request; JSON problem details when requested', content: new OA\JsonContent(ref: '#/components/schemas/HttpProblem'))]
    public function update(int $hotelId, int $id, #[MapRequestPayload] RoomTypeRequest $request): JsonResponse
    {
        return $this->json(RoomTypeResponse::fromEntity($this->roomTypeService->updateRoomType($hotelId, $id, $request)));
    }

    #[Route('/{id<\d+>}', name: 'room_type_delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_STAFF')]
    #[OA\Delete(security: [['Bearer' => []]])]
    #[OA\Response(response: 401, description: 'Authentication required')]
    #[OA\Response(response: 403, description: 'Staff access required')]
    #[OA\Response(response: 204, description: 'Room type deleted')]
    #[OA\Response(response: 404, description: 'Room type not found', content: new OA\JsonContent(ref: '#/components/schemas/DomainError'))]
    #[OA\Response(response: 409, description: 'Room type has reservations', content: new OA\JsonContent(ref: '#/components/schemas/DomainError'))]
    public function delete(int $hotelId, int $id): JsonResponse
    {
        $this->roomTypeService->deleteRoomType($hotelId, $id);

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
