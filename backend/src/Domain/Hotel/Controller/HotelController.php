<?php

declare(strict_types=1);

namespace App\Domain\Hotel\Controller;

use App\Domain\Hotel\Dto\HotelRequest;
use App\Domain\Hotel\Dto\HotelResponse;
use App\Domain\Hotel\Service\HotelService;
use App\Infrastructure\Cache\CacheScopes;
use App\Infrastructure\Cache\ReadCacheVersionService;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Hotel-related APIs from docs/IMPLEMENTATION_PLAN.md §2. Reads (GET) are
 * public — hotel/room browsing doesn't require auth. Writes are
 * #[IsGranted('ROLE_STAFF')], per Symfony's built-in role-based voter —
 * see `.claude/rules/backend-symfony.md` ("staff-only endpoints use
 * voters, not inline role checks in controllers").
 */
#[Route('/v1/hotels')]
#[OA\Tag(name: 'Hotels')]
final class HotelController extends AbstractController
{
    private const CACHE_TTL_SECONDS = 300;

    public function __construct(
        private readonly HotelService $hotelService,
        private readonly CacheItemPoolInterface $cache,
        private readonly ReadCacheVersionService $cacheVersions,
    ) {}

    /**
     * GET /v1/hotels — list hotels, optionally filtered by ?city=.
     */
    #[Route('', name: 'hotel_list', methods: ['GET'])]
    #[OA\Get(security: [])]
    #[OA\Parameter(name: 'city', in: 'query', schema: new OA\Schema(type: 'string'))]
    #[OA\Response(response: 200, description: 'Hotels', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: HotelResponse::class))))]
    public function list(Request $request): JsonResponse
    {
        $cityParam = $request->query->get('city');
        $city = is_string($cityParam) && '' !== $cityParam ? $cityParam : null;
        $scopeVersion = $this->cacheVersions->getVersion(CacheScopes::hotelRead());
        $cacheKey = sprintf(
            'hotel.list.v%d.%s',
            $scopeVersion,
            null === $city ? 'all' : sha1(mb_strtolower($city)),
        );

        $cacheItem = $this->cache->getItem($cacheKey);
        if ($cacheItem->isHit() && is_array($cacheItem->get())) {
            return $this->json($cacheItem->get());
        }

        $responses = HotelResponse::fromEntities($this->hotelService->listHotels($city));
        $payload = array_map(static fn(HotelResponse $hotel): array => get_object_vars($hotel), $responses);

        $cacheItem->set($payload);
        $cacheItem->expiresAfter(self::CACHE_TTL_SECONDS);
        $this->cache->save($cacheItem);

        return $this->json($payload);
    }

    /**
     * GET /v1/hotels/{id} — the hotel detail page's data.
     */
    #[Route('/{id<\d+>}', name: 'hotel_get', methods: ['GET'])]
    #[OA\Get(security: [])]
    #[OA\Response(response: 200, description: 'Hotel', content: new Model(type: HotelResponse::class))]
    #[OA\Response(response: 404, description: 'Hotel not found', content: new OA\JsonContent(ref: '#/components/schemas/DomainError'))]
    public function get(int $id): JsonResponse
    {
        $scopeVersion = $this->cacheVersions->getVersion(CacheScopes::hotelRead());
        $cacheKey = sprintf('hotel.get.v%d.%d', $scopeVersion, $id);
        $cacheItem = $this->cache->getItem($cacheKey);

        if ($cacheItem->isHit() && is_array($cacheItem->get())) {
            return $this->json($cacheItem->get());
        }

        $response = HotelResponse::fromEntity($this->hotelService->getHotel($id));
        $payload = get_object_vars($response);
        $cacheItem->set($payload);
        $cacheItem->expiresAfter(self::CACHE_TTL_SECONDS);
        $this->cache->save($cacheItem);

        return $this->json($payload);
    }

    /**
     * POST /v1/hotels — staff-only.
     */
    #[Route('', name: 'hotel_create', methods: ['POST'])]
    #[IsGranted('ROLE_STAFF')]
    #[OA\Post(security: [['Bearer' => []]])]
    #[OA\Response(response: 401, description: 'Authentication required')]
    #[OA\Response(response: 403, description: 'Staff access required')]
    #[OA\Response(response: 201, description: 'Hotel created', headers: [new OA\Header(header: 'Location', schema: new OA\Schema(type: 'string'))], content: new Model(type: HotelResponse::class))]
    #[OA\Response(response: 422, description: 'Invalid request; JSON problem details when requested', content: new OA\JsonContent(ref: '#/components/schemas/HttpProblem'))]
    public function create(#[MapRequestPayload] HotelRequest $request): JsonResponse
    {
        $hotel = $this->hotelService->createHotel($request);

        return $this->json(
            HotelResponse::fromEntity($hotel),
            Response::HTTP_CREATED,
            ['Location' => $this->generateUrl('hotel_get', ['id' => $hotel->getId()])],
        );
    }

    /**
     * PUT /v1/hotels/{id} — staff-only. Full-replace semantics — see
     * HotelRequest.
     */
    #[Route('/{id<\d+>}', name: 'hotel_update', methods: ['PUT'])]
    #[IsGranted('ROLE_STAFF')]
    #[OA\Put(security: [['Bearer' => []]])]
    #[OA\Response(response: 401, description: 'Authentication required')]
    #[OA\Response(response: 403, description: 'Staff access required')]
    #[OA\Response(response: 200, description: 'Updated hotel', content: new Model(type: HotelResponse::class))]
    #[OA\Response(response: 404, description: 'Hotel not found', content: new OA\JsonContent(ref: '#/components/schemas/DomainError'))]
    #[OA\Response(response: 422, description: 'Invalid request; JSON problem details when requested', content: new OA\JsonContent(ref: '#/components/schemas/HttpProblem'))]
    public function update(int $id, #[MapRequestPayload] HotelRequest $request): JsonResponse
    {
        $hotel = $this->hotelService->updateHotel($id, $request);

        return $this->json(HotelResponse::fromEntity($hotel));
    }

    /**
     * DELETE /v1/hotels/{id} — staff-only.
     */
    #[Route('/{id<\d+>}', name: 'hotel_delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_STAFF')]
    #[OA\Delete(security: [['Bearer' => []]])]
    #[OA\Response(response: 401, description: 'Authentication required')]
    #[OA\Response(response: 403, description: 'Staff access required')]
    #[OA\Response(response: 204, description: 'Hotel deleted')]
    #[OA\Response(response: 404, description: 'Hotel not found', content: new OA\JsonContent(ref: '#/components/schemas/DomainError'))]
    #[OA\Response(response: 409, description: 'Hotel has reservations', content: new OA\JsonContent(ref: '#/components/schemas/DomainError'))]
    public function delete(int $id): JsonResponse
    {
        $this->hotelService->deleteHotel($id);

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
