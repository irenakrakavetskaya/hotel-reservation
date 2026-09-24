<?php

declare(strict_types=1);

namespace App\Infrastructure\Cache;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Tiny version-counter layer to invalidate read caches without scanning keys.
 */
final class ReadCacheVersionService
{
    public function __construct(private readonly CacheItemPoolInterface $cache)
    {
    }

    public function getVersion(string $scope): int
    {
        $item = $this->cache->getItem($this->toVersionKey($scope));
        $value = $item->get();

        return is_int($value) ? $value : 1;
    }

    public function bump(string $scope): int
    {
        $item = $this->cache->getItem($this->toVersionKey($scope));
        $current = $item->get();
        $next = (is_int($current) ? $current : 1) + 1;
        $item->set($next);
        $this->cache->save($item);

        return $next;
    }

    private function toVersionKey(string $scope): string
    {
        return 'cache-version.' . $scope;
    }
}
