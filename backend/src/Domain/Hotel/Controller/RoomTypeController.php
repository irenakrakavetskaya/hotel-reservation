<?php

declare(strict_types=1);

namespace App\Domain\Hotel\Controller;

use App\Domain\Hotel\Dto\RoomTypeRequest;
use App\Domain\Hotel\Dto\RoomTypeResponse;
use App\Domain\Hotel\Service\RoomTypeService;
use App\Infrastructure\Cache\CacheScopes;
use App\Infrastructure\Cache\ReadCacheVersionService;
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
final class RoomTypeController extends AbstractController
{
    private const CACHE_TTL_SECONDS = 300;

    public function __construct(
        private readonly RoomTypeService $roomTypeService,
        private readonly CacheItemPoolInterface $cache,
        private readonly ReadCacheVersionService $cacheVersions,
    ) {
    }

    #[Route('', name: 'room_type_list', methods: ['GET'])]
    public function list(int $hotelId): JsonResponse
    {
        $scopeVersion = $this->cacheVersions->getVersion(CacheScopes::roomTypeRead($hotelId));
        $cacheKey = sprintf('room-type:list:%d:v%d', $hotelId, $scopeVersion);
        $cacheItem = $this->cache->getItem($cacheKey);

        if ($cacheItem->isHit() && is_array($cacheItem->get())) {
            return $this->json($cacheItem->get());
        }

        $responses = RoomTypeResponse::fromEntities($this->roomTypeService->listRoomTypes($hotelId));
        $payload = array_map(static fn (RoomTypeResponse $roomType): array => get_object_vars($roomType), $responses);

        $cacheItem->set($payload);
        $cacheItem->expiresAfter(self::CACHE_TTL_SECONDS);
        $this->cache->save($cacheItem);

        return $this->json($payload);
    }

    #[Route('/{id<\d+>}', name: 'room_type_get', methods: ['GET'])]
    public function get(int $hotelId, int $id): JsonResponse
    {
        $scopeVersion = $this->cacheVersions->getVersion(CacheScopes::roomTypeRead($hotelId));
        $cacheKey = sprintf('room-type:get:%d:%d:v%d', $hotelId, $id, $scopeVersion);
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
    public function update(int $hotelId, int $id, #[MapRequestPayload] RoomTypeRequest $request): JsonResponse
    {
        return $this->json(RoomTypeResponse::fromEntity($this->roomTypeService->updateRoomType($hotelId, $id, $request)));
    }

    #[Route('/{id<\d+>}', name: 'room_type_delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_STAFF')]
    public function delete(int $hotelId, int $id): JsonResponse
    {
        $this->roomTypeService->deleteRoomType($hotelId, $id);

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
