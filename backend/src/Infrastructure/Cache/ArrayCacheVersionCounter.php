<?php

declare(strict_types=1);

namespace App\Infrastructure\Cache;

final class ArrayCacheVersionCounter implements CacheVersionCounter
{
    /** @var array<string, int> */
    private array $versions = [];

    public function getVersion(string $scope): int
    {
        return $this->versions[$scope] ?? 1;
    }

    public function bump(string $scope): int
    {
        return $this->versions[$scope] = $this->getVersion($scope) + 1;
    }
}
