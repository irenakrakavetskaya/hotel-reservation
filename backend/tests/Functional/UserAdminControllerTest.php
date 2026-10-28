<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\User\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class UserAdminControllerTest extends WebTestCase
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

    public function testAnonymousIsRejected(): void
    {
        $this->client->request('GET', '/v1/admin/users');

        self::assertResponseStatusCodeSame(401);
    }

    public function testStaffCannotListOrChangeRoles(): void
    {
        ['token' => $token] = $this->createUser('staff@example.com', ['ROLE_STAFF']);
        ['id' => $otherId] = $this->createUser('other@example.com');

        $this->call('GET', '/v1/admin/users', $token);
        self::assertResponseStatusCodeSame(403);

        $this->call('PUT', sprintf('/v1/admin/users/%d/roles', $otherId), $token, ['roles' => ['ROLE_STAFF']]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminListsAndSearchesUsers(): void
    {
        ['token' => $token] = $this->createUser('admin@example.com', ['ROLE_ADMIN']);
        $this->createUser('alice@example.com');

        $all = $this->call('GET', '/v1/admin/users', $token);
        self::assertResponseIsSuccessful();
        self::assertCount(2, $all);

        $found = $this->call('GET', '/v1/admin/users?search=ALI', $token);
        self::assertCount(1, $found);
        self::assertSame('alice@example.com', $found[0]['email']);
        self::assertArrayNotHasKey('password', $found[0]);
    }

    public function testAdminCanGrantAndRevokeStaff(): void
    {
        ['token' => $token] = $this->createUser('admin@example.com', ['ROLE_ADMIN']);
        ['id' => $id] = $this->createUser('alice@example.com');

        $granted = $this->call('PUT', sprintf('/v1/admin/users/%d/roles', $id), $token, ['roles' => ['ROLE_STAFF']]);
        self::assertResponseIsSuccessful();
        self::assertContains('ROLE_STAFF', $granted['roles']);

        $revoked = $this->call('PUT', sprintf('/v1/admin/users/%d/roles', $id), $token, ['roles' => []]);
        self::assertResponseIsSuccessful();
        self::assertSame(['ROLE_USER'], $revoked['roles']);
    }

    public function testInvalidRoleReturns422(): void
    {
        ['token' => $token] = $this->createUser('admin@example.com', ['ROLE_ADMIN']);
        ['id' => $id] = $this->createUser('alice@example.com');

        $this->call('PUT', sprintf('/v1/admin/users/%d/roles', $id), $token, ['roles' => ['ROLE_SUPER']]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testUnknownUserReturns404(): void
    {
        ['token' => $token] = $this->createUser('admin@example.com', ['ROLE_ADMIN']);

        $this->call('PUT', '/v1/admin/users/9999/roles', $token, ['roles' => ['ROLE_STAFF']]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testAdminCannotRemoveOwnAdminRole(): void
    {
        ['token' => $token, 'id' => $id] = $this->createUser('admin@example.com', ['ROLE_ADMIN']);
        $this->createUser('admin2@example.com', ['ROLE_ADMIN']);

        $this->call('PUT', sprintf('/v1/admin/users/%d/roles', $id), $token, ['roles' => ['ROLE_STAFF']]);

        self::assertResponseStatusCodeSame(409);
    }

    public function testAdminCanDemoteAnotherAdminButNotTheLastOne(): void
    {
        ['token' => $token] = $this->createUser('admin@example.com', ['ROLE_ADMIN']);
        ['id' => $otherId] = $this->createUser('admin2@example.com', ['ROLE_ADMIN']);

        $this->call('PUT', sprintf('/v1/admin/users/%d/roles', $otherId), $token, ['roles' => ['ROLE_STAFF']]);
        self::assertResponseIsSuccessful();

        // The demoted user is no longer an admin, so a stale-token admin re-demoting the only admin hits the last-admin guard.
        ['token' => $staleToken, 'id' => $soleAdminId] = $this->createUser('admin3@example.com', ['ROLE_ADMIN']);
        $this->demoteViaDatabase('admin@example.com');
        $this->call('PUT', sprintf('/v1/admin/users/%d/roles', $soleAdminId), $staleToken, ['roles' => []]);
        self::assertResponseStatusCodeSame(409);
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<mixed>
     */
    private function call(string $method, string $uri, string $token, ?array $body = null): array
    {
        $this->client->request(
            $method,
            $uri,
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
            content: null === $body ? null : json_encode($body, JSON_THROW_ON_ERROR),
        );

        $content = (string) $this->client->getResponse()->getContent();

        if ('' === $content) {
            return [];
        }
        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function demoteViaDatabase(string $email): void
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement("UPDATE app_user SET roles = '[]' WHERE email = :email", ['email' => $email]);
    }

    /**
     * @param list<string> $roles
     *
     * @return array{token: string, id: int}
     */
    private function createUser(string $email, array $roles = []): array
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        /** @var JWTTokenManagerInterface $tokenManager */
        $tokenManager = self::getContainer()->get(JWTTokenManagerInterface::class);

        $user = new User($email, 'not-used', $roles);
        $entityManager->persist($user);
        $entityManager->flush();

        return ['token' => $tokenManager->create($user), 'id' => $user->getId()];
    }
}
