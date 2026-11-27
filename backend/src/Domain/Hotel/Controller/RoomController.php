<?php

declare(strict_types=1);

namespace App\Domain\Hotel\Controller;

use App\Domain\Hotel\Dto\RoomRequest;
use App\Domain\Hotel\Dto\RoomResponse;
use App\Domain\Hotel\Service\RoomService;
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
 * Room-related APIs from docs/IMPLEMENTATION_PLAN.md §2. These are
 * physical `room` records (admin/maintenance) — not the bookable room
 * *types* customers reserve against; see the note on the Room entity.
 * Reads are public, writes are #[IsGranted('ROLE_STAFF')].
 */
#[Route('/v1/hotels/{hotelId<\d+>}/rooms')]
#[OA\Tag(name: 'Rooms')]
final class RoomController extends AbstractController
{
    private const CACHE_TTL_SECONDS = 300;

    public function __construct(
        private readonly RoomService $roomService,
        private readonly CacheItemPoolInterface $cache,
        private readonly ReadCacheVersionService $cacheVersions,
    ) {}

    /**
     * GET /v1/hotels/{hotelId}/rooms
     */
    #[Route('', name: 'room_list', methods: ['GET'])]
    #[OA\Get(security: [])]
    #[OA\Response(response: 200, description: 'Rooms', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: RoomResponse::class))))]
    #[OA\Response(response: 404, description: 'Hotel not found', content: new OA\JsonContent(ref: '#/components/schemas/DomainError'))]
    public function list(int $hotelId): JsonResponse
    {
        $scopeVersion = $this->cacheVersions->getVersion(CacheScopes::roomRead($hotelId));
        $cacheKey = sprintf('room.list.%d.v%d', $hotelId, $scopeVersion);
        $cacheItem = $this->cache->getItem($cacheKey);

        if ($cacheItem->isHit() && is_array($cacheItem->get())) {
            return $this->json($cacheItem->get());
        }

        $responses = RoomResponse::fromEntities($this->roomService->listRooms($hotelId));
        $payload = array_map(static fn(RoomResponse $room): array => get_object_vars($room), $responses);

        $cacheItem->set($payload);
        $cacheItem->expiresAfter(self::CACHE_TTL_SECONDS);
        $this->cache->save($cacheItem);

        return $this->json($payload);
    }

    /**
     * GET /v1/hotels/{hotelId}/rooms/{id}
     */
    #[Route('/{id<\d+>}', name: 'room_get', methods: ['GET'])]
    #[OA\Get(security: [])]
    #[OA\Response(response: 200, description: 'Room', content: new Model(type: RoomResponse::class))]
    #[OA\Response(response: 404, description: 'Room not found', content: new OA\JsonContent(ref: '#/components/schemas/DomainError'))]
    public function get(int $hotelId, int $id): JsonResponse
    {
        $scopeVersion = $this->cacheVersions->getVersion(CacheScopes::roomRead($hotelId));
        $cacheKey = sprintf('room.get.%d.%d.v%d', $hotelId, $id, $scopeVersion);
        $cacheItem = $this->cache->getItem($cacheKey);

        if ($cacheItem->isHit() && is_array($cacheItem->get())) {
            return $this->json($cacheItem->get());
        }

        $response = RoomResponse::fromEntity($this->roomService->getRoom($hotelId, $id));
        $payload = get_object_vars($response);
        $cacheItem->set($payload);
        $cacheItem->expiresAfter(self::CACHE_TTL_SECONDS);
        $this->cache->save($cacheItem);

        return $this->json($payload);
    }

    /**
     * POST /v1/hotels/{hotelId}/rooms — staff-only.
     */
    #[Route('', name: 'room_create', methods: ['POST'])]
    #[IsGranted('ROLE_STAFF')]
    #[OA\Post(security: [['Bearer' => []]])]
    #[OA\Response(response: 401, description: 'Authentication required')]
    #[OA\Response(response: 403, description: 'Staff access required')]
    #[OA\Response(response: 201, description: 'Room created', headers: [new OA\Header(header: 'Location', schema: new OA\Schema(type: 'string'))], content: new Model(type: RoomResponse::class))]
    #[OA\Response(response: 404, description: 'Hotel not found', content: new OA\JsonContent(ref: '#/components/schemas/DomainError'))]
    #[OA\Response(response: 409, description: 'Duplicate room number or inventory conflict', content: new OA\JsonContent(ref: '#/components/schemas/DomainError'))]
    #[OA\Response(response: 422, description: 'Invalid request or room type; JSON problem details when requested', content: new OA\JsonContent(ref: '#/components/schemas/InvalidRequest'))]
    public function create(int $hotelId, #[MapRequestPayload] RoomRequest $request): JsonResponse
    {
        $room = $this->roomService->createRoom($hotelId, $request);

        return $this->json(
            RoomResponse::fromEntity($room),
            Response::HTTP_CREATED,
            ['Location' => $this->generateUrl('room_get', ['hotelId' => $hotelId, 'id' => $room->getId()])],
        );
    }

    /**
     * PUT /v1/hotels/{hotelId}/rooms/{id} — staff-only. Full-replace
     * semantics — see RoomRequest.
     */
    #[Route('/{id<\d+>}', name: 'room_update', methods: ['PUT'])]
    #[IsGranted('ROLE_STAFF')]
    #[OA\Put(security: [['Bearer' => []]])]
    #[OA\Response(response: 401, description: 'Authentication required')]
    #[OA\Response(response: 403, description: 'Staff access required')]
    #[OA\Response(response: 200, description: 'Updated room', content: new Model(type: RoomResponse::class))]
    #[OA\Response(response: 404, description: 'Room not found', content: new OA\JsonContent(ref: '#/components/schemas/DomainError'))]
    #[OA\Response(response: 409, description: 'Duplicate room number or inventory conflict', content: new OA\JsonContent(ref: '#/components/schemas/DomainError'))]
    #[OA\Response(response: 422, description: 'Invalid request or room type; JSON problem details when requested', content: new OA\JsonContent(ref: '#/components/schemas/InvalidRequest'))]
    public function update(int $hotelId, int $id, #[MapRequestPayload] RoomRequest $request): JsonResponse
    {
        $room = $this->roomService->updateRoom($hotelId, $id, $request);

        return $this->json(RoomResponse::fromEntity($room));
    }

    /**
     * DELETE /v1/hotels/{hotelId}/rooms/{id} — staff-only.
     */
    #[Route('/{id<\d+>}', name: 'room_delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_STAFF')]
    #[OA\Delete(security: [['Bearer' => []]])]
    #[OA\Response(response: 401, description: 'Authentication required')]
    #[OA\Response(response: 403, description: 'Staff access required')]
    #[OA\Response(response: 204, description: 'Room deleted')]
    #[OA\Response(response: 404, description: 'Room not found', content: new OA\JsonContent(ref: '#/components/schemas/DomainError'))]
    #[OA\Response(response: 409, description: 'Inventory conflict', content: new OA\JsonContent(ref: '#/components/schemas/DomainError'))]
    public function delete(int $hotelId, int $id): JsonResponse
    {
        $this->roomService->deleteRoom($hotelId, $id);

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
