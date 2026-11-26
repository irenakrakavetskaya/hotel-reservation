<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Infrastructure\Cache\RedisCacheVersionCounter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\RedisAdapter;

final class RedisCacheVersionTest extends TestCase
{
    public function testConcurrentIncrementsAndEvictionDoNotReuseOldVersions(): void
    {
        $url = getenv('TEST_REDIS_URL');
        if (false === $url || '' === $url) {
            self::markTestSkipped('Set TEST_REDIS_URL to an isolated Redis instance.');
        }

        $redis = RedisAdapter::createConnection($url);
        self::assertInstanceOf(\Redis::class, $redis);
        $counter = new RedisCacheVersionCounter($redis);
        $scope = 'integration.' . bin2hex(random_bytes(12));
        $key = 'hotel:read-cache-version:' . $scope;

        try {
            $initial = $counter->getVersion($scope);
            $workers = [];
            $code = <<<'PHP'
                require $argv[1];
                $redis = \Symfony\Component\Cache\Adapter\RedisAdapter::createConnection($argv[2]);
                $counter = new \App\Infrastructure\Cache\RedisCacheVersionCounter($redis);
                for ($i = 0; $i < 50; ++$i) {
                    $counter->bump($argv[3]);
                }
                PHP;
            for ($i = 0; $i < 8; ++$i) {
                $process = proc_open(
                    [PHP_BINARY, '-r', $code, dirname(__DIR__, 2) . '/vendor/autoload.php', $url, $scope],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                );
                self::assertIsResource($process);
                fclose($pipes[0]);
                $workers[] = [$process, $pipes[1], $pipes[2]];
            }

            foreach ($workers as [$process, $stdout, $stderr]) {
                $output = stream_get_contents($stdout) . stream_get_contents($stderr);
                fclose($stdout);
                fclose($stderr);
                self::assertSame(0, proc_close($process), $output);
            }
            self::assertSame($initial + 400, $counter->getVersion($scope));

            $cache = new RedisAdapter($redis, 'test_' . bin2hex(random_bytes(8)));
            $oldVersion = $counter->getVersion($scope);
            $oldItem = $cache->getItem('availability.' . $oldVersion);
            $newVersion = $counter->bump($scope);
            $oldItem->set('stale')->expiresAfter(60);
            self::assertTrue($cache->save($oldItem));
            self::assertFalse($cache->getItem('availability.' . $newVersion)->isHit());

            $redis->del($key);
            self::assertNotSame($oldVersion, $counter->getVersion($scope));
            self::assertNotSame($newVersion, $counter->getVersion($scope));

            $versionBeforeEviction = $counter->getVersion($scope);
            $redis->del($key);
            self::assertNotSame($versionBeforeEviction, $counter->bump($scope));
            self::assertTrue($cache->deleteItem('availability.' . $oldVersion));
        } finally {
            $redis->del($key);
        }
    }
}
