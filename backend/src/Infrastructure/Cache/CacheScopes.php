<?php

declare(strict_types=1);

namespace App\Infrastructure\Cache;

final class CacheScopes
{
    public static function hotelRead(): string
    {
        return 'hotel-read';
    }

    public static function roomRead(int $hotelId): string
    {
        return sprintf('room-read:%d', $hotelId);
    }

    public static function roomTypeRead(int $hotelId): string
    {
        return sprintf('room-type-read:%d', $hotelId);
    }

    public static function availabilityRead(int $hotelId, int $roomTypeId): string
    {
        return sprintf('availability-read:%d:%d', $hotelId, $roomTypeId);
    }
}
