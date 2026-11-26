<?php

declare(strict_types=1);

namespace App\Infrastructure\Cache;

final class RedisCacheVersionCounter implements CacheVersionCounter
{
    public function __construct(private readonly \Redis $redis) {}

    public function getVersion(string $scope): int
    {
        $key = $this->key($scope);
        $value = $this->redis->get($key);
        if (false === $value) {
            // A random initial generation prevents old cached entries becoming
            // reachable if Redis evicts or loses the counter.
            $this->redis->setnx($key, (string) random_int(1, PHP_INT_MAX - 1000000000));
            $value = $this->redis->get($key);
        }

        if (!is_string($value) || !ctype_digit($value) || (int) $value < 1) {
            throw new \RuntimeException('Unable to read Redis cache version for scope ' . $scope);
        }

        return (int) $value;
    }

    public function bump(string $scope): int
    {
        $next = $this->redis->eval(
            <<<'LUA'
                if redis.call('exists', KEYS[1]) == 0 then
                    redis.call('set', KEYS[1], ARGV[1])
                end
                redis.call('incr', KEYS[1])
                return redis.call('get', KEYS[1])
                LUA,
            [$this->key($scope), (string) random_int(1, PHP_INT_MAX - 1000000000)],
            1,
        );
        if (!is_string($next) || !ctype_digit($next) || (int) $next < 1) {
            throw new \RuntimeException('Unable to increment Redis cache version for scope ' . $scope);
        }

        return (int) $next;
    }

    private function key(string $scope): string
    {
        return 'hotel:read-cache-version:' . $scope;
    }
}
