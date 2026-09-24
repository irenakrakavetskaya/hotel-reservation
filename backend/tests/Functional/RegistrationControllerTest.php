<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RegistrationControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement('TRUNCATE TABLE app_user RESTART IDENTITY CASCADE');
    }

    public function testItRegistersAUserAndReturnsAJwt(): void
    {
        $client = $this->client;

        $client->request('POST', '/v1/auth/register', content: json_encode([
            'email' => 'new-user@example.com',
            'password' => 'secret-password',
            'passwordConfirmation' => 'secret-password',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(201);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsString($payload['token']);
        self::assertSame('new-user@example.com', $payload['user']['email']);
        self::assertSame(['ROLE_USER'], $payload['user']['roles']);
    }

    public function testItRejectsDuplicateEmailCaseInsensitively(): void
    {
        $client = $this->client;
        $payload = [
            'email' => 'duplicate@example.com',
            'password' => 'secret-password',
            'passwordConfirmation' => 'secret-password',
        ];

        $client->request('POST', '/v1/auth/register', content: json_encode($payload, JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        $payload['email'] = 'DUPLICATE@example.com';
        $client->request('POST', '/v1/auth/register', content: json_encode($payload, JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(409);
    }

    public function testItValidatesRegistrationPayload(): void
    {
        $client = $this->client;

        $client->request('POST', '/v1/auth/register', content: json_encode([
            'email' => 'invalid-email',
            'password' => 'short',
            'passwordConfirmation' => 'different',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
    }
}
