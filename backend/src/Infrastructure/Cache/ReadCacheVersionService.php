<?php

declare(strict_types=1);

namespace App\Infrastructure\Cache;

final class ReadCacheVersionService
{
    public function __construct(private readonly CacheVersionCounter $counter) {}

    public function getVersion(string $scope): int
    {
        return $this->counter->getVersion($scope);
    }

    public function bump(string $scope): int
    {
        return $this->counter->bump($scope);
    }
}
