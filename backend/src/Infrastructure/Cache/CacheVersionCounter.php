<?php

declare(strict_types=1);

namespace App\Infrastructure\Cache;

interface CacheVersionCounter
{
    public function getVersion(string $scope): int;

    public function bump(string $scope): int;
}
