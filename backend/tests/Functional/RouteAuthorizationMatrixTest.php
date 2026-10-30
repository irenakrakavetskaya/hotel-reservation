<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Functional\Support\CreatesTokens;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Guards against shipping an unprotected write route: every state-changing /v1
 * route except login/register must reject anonymous callers, and the
 * staff/admin ones must reject plain users.
 */
final class RouteAuthorizationMatrixTest extends WebTestCase
{
    use CreatesTokens;

    private const UUID = '0190b0c0-1234-7abc-8def-0123456789ab';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement('TRUNCATE TABLE app_user RESTART IDENTITY CASCADE');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function staffOrAdminRoutes(): iterable
    {
        yield 'create hotel' => ['POST', '/v1/hotels'];
        yield 'update hotel' => ['PUT', '/v1/hotels/1'];
        yield 'delete hotel' => ['DELETE', '/v1/hotels/1'];
        yield 'create room type' => ['POST', '/v1/hotels/1/room-types'];
        yield 'update room type' => ['PUT', '/v1/hotels/1/room-types/1'];
        yield 'delete room type' => ['DELETE', '/v1/hotels/1/room-types/1'];
        yield 'create room' => ['POST', '/v1/hotels/1/rooms'];
        yield 'update room' => ['PUT', '/v1/hotels/1/rooms/1'];
        yield 'delete room' => ['DELETE', '/v1/hotels/1/rooms/1'];
        yield 'list users' => ['GET', '/v1/admin/users'];
        yield 'change user roles' => ['PUT', '/v1/admin/users/1/roles'];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function authenticatedOnlyRoutes(): iterable
    {
        yield 'list reservations' => ['GET', '/v1/reservations'];
        yield 'get reservation' => ['GET', '/v1/reservations/' . self::UUID];
        yield 'create reservation' => ['POST', '/v1/reservations'];
        yield 'cancel reservation' => ['DELETE', '/v1/reservations/' . self::UUID];
        yield 'pay reservation' => ['POST', '/v1/payments'];
    }

    #[DataProvider('staffOrAdminRoutes')]
    #[DataProvider('authenticatedOnlyRoutes')]
    public function testAnonymousCallersAreRejected(string $method, string $path): void
    {
        $this->request($method, $path);

        self::assertResponseStatusCodeSame(401);
    }

    #[DataProvider('staffOrAdminRoutes')]
    public function testPlainUsersAreForbidden(string $method, string $path): void
    {
        $this->request($method, $path, $this->tokenFor('plain@example.com', ['ROLE_USER']));

        self::assertResponseStatusCodeSame(403);
    }

    private function request(string $method, string $path, ?string $token = null): void
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if (null !== $token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }

        $this->client->request($method, $path, server: $server, content: '{}');
    }
}
