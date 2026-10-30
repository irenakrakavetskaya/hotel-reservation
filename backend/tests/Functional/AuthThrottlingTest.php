<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AuthThrottlingTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->clearLimiters();

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement('TRUNCATE TABLE app_user RESTART IDENTITY CASCADE');
    }

    protected function tearDown(): void
    {
        $this->clearLimiters();
        parent::tearDown();
    }

    private function clearLimiters(): void
    {
        /** @var CacheItemPoolInterface $pool */
        $pool = self::getContainer()->get('cache.rate_limiter_test');
        $pool->clear();
    }

    public function testLoginIsThrottledAfterRepeatedFailures(): void
    {
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->login('victim@example.com', 'wrong-password');
            self::assertResponseStatusCodeSame(401);
        }

        $this->login('victim@example.com', 'wrong-password');

        self::assertResponseStatusCodeSame(401);
        self::assertStringContainsString('Too many failed login attempts', (string) $this->client->getResponse()->getContent());
    }

    public function testRegistrationIsThrottledPerClientIp(): void
    {
        for ($attempt = 1; $attempt <= 10; ++$attempt) {
            $this->register(sprintf('user%d@example.com', $attempt));
            self::assertResponseStatusCodeSame(201);
        }

        $this->register('one-too-many@example.com');

        self::assertResponseStatusCodeSame(429);
        self::assertNotNull($this->client->getResponse()->headers->get('Retry-After'));
    }

    private function login(string $email, string $password): void
    {
        $this->client->request('POST', '/v1/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => $email,
            'password' => $password,
        ], JSON_THROW_ON_ERROR));
    }

    private function register(string $email): void
    {
        $this->client->request('POST', '/v1/auth/register', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => $email,
            'password' => 'secret-password',
            'passwordConfirmation' => 'secret-password',
        ], JSON_THROW_ON_ERROR));
    }
}
