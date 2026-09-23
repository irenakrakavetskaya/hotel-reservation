<?php

declare(strict_types=1);

namespace App\Domain\Hotel\Service;

use App\Domain\Hotel\Dto\HotelRequest;
use App\Domain\Hotel\Entity\Hotel;
use App\Domain\Hotel\Exception\HotelNotFoundException;
use App\Domain\Hotel\Repository\HotelRepository;
use App\Infrastructure\Cache\CacheScopes;
use App\Infrastructure\Cache\ReadCacheVersionService;

/**
 * Business logic for hotel CRUD. Controllers call this, never
 * HotelRepository directly — see `.claude/rules/backend-symfony.md`.
 */
final class HotelService
{
    public function __construct(
        private readonly HotelRepository $hotelRepository,
        private readonly ReadCacheVersionService $cacheVersions,
    ) {
    }

    /** @return list<Hotel> */
    public function listHotels(?string $city = null): array
    {
        return null !== $city
            ? $this->hotelRepository->findByCity($city)
            : $this->hotelRepository->findAll();
    }

    /** @throws HotelNotFoundException */
    public function getHotel(int $id): Hotel
    {
        return $this->hotelRepository->find($id) ?? throw HotelNotFoundException::forId($id);
    }

    public function createHotel(HotelRequest $request): Hotel
    {
        $hotel = new Hotel(
            name: $request->name,
            address: $request->address,
            city: $request->city,
            country: $request->country,
            starRating: $request->starRating,
            description: $request->description,
            amenities: $request->amenities,
        );

        $this->hotelRepository->save($hotel);
        $this->cacheVersions->bump(CacheScopes::hotelRead());

        return $hotel;
    }

    /** @throws HotelNotFoundException */
    public function updateHotel(int $id, HotelRequest $request): Hotel
    {
        $hotel = $this->getHotel($id);

        $hotel->update(
            name: $request->name,
            address: $request->address,
            city: $request->city,
            country: $request->country,
            starRating: $request->starRating,
            description: $request->description,
            amenities: $request->amenities,
        );

        $this->hotelRepository->save($hotel);
        $this->cacheVersions->bump(CacheScopes::hotelRead());

        return $hotel;
    }

    /** @throws HotelNotFoundException */
    public function deleteHotel(int $id): void
    {
        $hotel = $this->getHotel($id);
        $this->hotelRepository->remove($hotel);
        $this->cacheVersions->bump(CacheScopes::hotelRead());
        $this->cacheVersions->bump(CacheScopes::roomRead($id));
        $this->cacheVersions->bump(CacheScopes::roomTypeRead($id));
    }
}
