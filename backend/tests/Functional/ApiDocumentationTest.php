<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Functional\Support\CreatesTokens;
use App\Domain\User\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Uid\Uuid;

final class ApiDocumentationTest extends WebTestCase
{
    use CreatesTokens;

    public function testDocumentationRequiresCurrentStaffRole(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement('TRUNCATE TABLE app_user RESTART IDENTITY CASCADE');
        $staff = $this->tokenFor('docs-staff@example.com', ['ROLE_STAFF']);
        $admin = $this->tokenFor('docs-admin@example.com', ['ROLE_ADMIN']);
        $customer = $this->tokenFor('docs-user@example.com', ['ROLE_USER']);
        $user = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'docs-staff@example.com']);
        self::assertNotNull($user);
        $expired = self::getContainer()->get(JWTTokenManagerInterface::class)->createFromPayload($user, ['exp' => time() - 3600]);

        foreach (['/v1/admin/api-doc', '/v1/admin/api-doc/openapi.json'] as $path) {
            $client->request('GET', $path);
            self::assertResponseStatusCodeSame(401);
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            $client->request('GET', $path, server: ['HTTP_AUTHORIZATION' => 'Bearer invalid']);
            self::assertResponseStatusCodeSame(401);
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            $client->request('GET', $path, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $expired]);
            self::assertResponseStatusCodeSame(401);
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            $client->request('GET', $path, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $customer]);
            self::assertResponseStatusCodeSame(403);
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
            foreach ([$staff, $admin] as $token) {
                $client->request('GET', $path, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
                self::assertResponseIsSuccessful();
                self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
                self::assertStringNotContainsString($token, (string) $client->getResponse()->getContent());
                if ('/v1/admin/api-doc' === $path) {
                    $html = (string) $client->getResponse()->getContent();
                    self::assertStringContainsString('persistAuthorization: false', $html);
                    self::assertStringContainsString("requestUrl.pathname === '/admin/api-doc/openapi.json'", $html);
                    self::assertStringContainsString('request.credentials = \'omit\'', $html);
                    self::assertStringContainsString('Only the documentation schema and same-origin /v1 API requests are permitted.', $html);
                    self::assertDoesNotMatchRegularExpression('/<(?:script|link|img)[^>]+(?:src|href)=[\"\\\']https?:/i', $html);
                }
            }
        }

        $connection->executeStatement('UPDATE app_user SET roles = ? WHERE email = ?', [json_encode(['ROLE_USER'], JSON_THROW_ON_ERROR), 'docs-staff@example.com']);
        foreach (['/v1/admin/api-doc', '/v1/admin/api-doc/openapi.json'] as $path) {
            $client->request('GET', $path, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $staff]);
            self::assertResponseStatusCodeSame(403);
            self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        }
    }

    public function testEveryApiRouteHasAnOpenApiOperation(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('nelmio:apidoc:dump'));
        self::assertSame(0, $tester->execute(['--format' => 'json']));
        $spec = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $router = self::getContainer()->get(RouterInterface::class);
        $expected = [];
        foreach ($router->getRouteCollection() as $name => $route) {
            $path = $route->getPath();
            if (!str_starts_with($path, '/v1/') || str_starts_with($name, 'api_doc_')) {
                continue;
            }
            foreach ($route->getMethods() as $method) {
                if (in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                    $expected[] = $method . ' ' . $path;
                }
            }
        }
        $actual = [];
        foreach ($spec['paths'] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                if (in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    $actual[] = strtoupper($method) . ' ' . $path;
                    self::assertArrayHasKey('security', $operations[$method], strtoupper($method) . ' ' . $path);
                }
            }
        }
        sort($expected);
        sort($actual);
        self::assertSame($expected, $actual);
        self::assertArrayNotHasKey('dateRangeValid', $spec['paths']['/v1/reservations']['post']['requestBody']['content']['application/json']['schema']['properties']);
        self::assertArrayNotHasKey('passwordsMatch', $spec['components']['schemas']['RegisterRequest']['properties']);
        self::assertSame(['Bearer' => []], $spec['paths']['/v1/reservations']['post']['security'][0]);
    }

    public function testRepresentativeResponsesMatchDocumentedSchemas(): void
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement('TRUNCATE TABLE room_type_rate, room_type_inventory, reservation, room_type, hotel, app_user RESTART IDENTITY CASCADE');
        $staff = $this->tokenFor('contract-staff@example.com', ['ROLE_STAFF']);
        $client->request('GET', '/v1/admin/api-doc/openapi.json', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $staff]);
        $spec = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        $client->request('POST', '/v1/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => 'contract-staff@example.com', 'password' => 'secret-password'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200);
        $this->assertDocumentedJson($spec, '/v1/auth/login', 'post', 200, (string) $client->getResponse()->getContent());
        $client->request('POST', '/v1/auth/register', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: json_encode(['email' => 'contract-new@example.com', 'password' => 'password-1', 'passwordConfirmation' => 'password-2'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
        $this->assertDocumentedJson($spec, '/v1/auth/register', 'post', 422, (string) $client->getResponse()->getContent());

        $connection->executeStatement("INSERT INTO hotel (name, address, city, country, star_rating, amenities, created_at, updated_at) VALUES ('Contract', 'Street', 'City', 'Country', 4, '[]'::jsonb, now(), now())");
        $hotel = (int) $connection->lastInsertId();
        $connection->executeStatement("INSERT INTO room_type (hotel_id, name, max_occupancy, base_price, amenities, created_at, updated_at) VALUES (?, 'Standard', 2, 10000, '[]'::jsonb, now(), now())", [$hotel]);
        $roomType = (int) $connection->lastInsertId();
        $start = (new \DateTimeImmutable('tomorrow'))->format('Y-m-d');
        $end = (new \DateTimeImmutable('tomorrow +1 day'))->format('Y-m-d');
        $connection->executeStatement('INSERT INTO room_type_inventory (hotel_id, room_type_id, date, total_inventory, total_reserved, updated_at) VALUES (?, ?, ?, 1, 0, now())', [$hotel, $roomType, $start]);
        $connection->executeStatement('INSERT INTO room_type_rate (hotel_id, room_type_id, date, price, updated_at) VALUES (?, ?, ?, 10000, now())', [$hotel, $roomType, $start]);

        $client->request('GET', '/v1/hotels/' . $hotel);
        self::assertResponseStatusCodeSame(200);
        $this->assertDocumentedJson($spec, '/v1/hotels/{id}', 'get', 200, (string) $client->getResponse()->getContent());
        $client->request('GET', '/v1/hotels/999999999');
        self::assertResponseStatusCodeSame(404);
        $this->assertDocumentedJson($spec, '/v1/hotels/{id}', 'get', 404, (string) $client->getResponse()->getContent());
        $client->request('POST', '/v1/hotels/' . $hotel . '/rooms', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $staff, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: json_encode(['roomTypeId' => 999999999, 'roomNumber' => '101'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
        $error = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSchema($error, $spec['components']['schemas']['InvalidRequest']['oneOf'][1], $spec);
        $client->request('GET', sprintf('/v1/hotels/%d/room-types/%d/availability?startDate=%s&endDate=%s', $hotel, $roomType, $start, $end));
        self::assertResponseStatusCodeSame(200);
        $this->assertDocumentedJson($spec, '/v1/hotels/{hotelId}/room-types/{roomTypeId}/availability', 'get', 200, (string) $client->getResponse()->getContent());
        $client->request('GET', sprintf('/v1/hotels/%d/room-types/%d/availability', $hotel, $roomType), server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(422);
        $this->assertDocumentedJson($spec, '/v1/hotels/{hotelId}/room-types/{roomTypeId}/availability', 'get', 422, (string) $client->getResponse()->getContent());

        $body = ['reservationID' => Uuid::v7()->toRfc4122(), 'hotelID' => $hotel, 'roomTypeID' => $roomType, 'startDate' => $start, 'endDate' => $end, 'roomCount' => 1];
        $client->request('POST', '/v1/reservations', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $staff, 'CONTENT_TYPE' => 'application/json'], content: json_encode($body, JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $this->assertDocumentedJson($spec, '/v1/reservations', 'post', 201, (string) $client->getResponse()->getContent());
        $body['reservationID'] = Uuid::v7()->toRfc4122();
        $client->request('POST', '/v1/reservations', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $staff, 'CONTENT_TYPE' => 'application/json'], content: json_encode($body, JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(409);
        $this->assertDocumentedJson($spec, '/v1/reservations', 'post', 409, (string) $client->getResponse()->getContent());

        $client->request('POST', '/v1/admin/reports', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $staff, 'CONTENT_TYPE' => 'application/json'], content: json_encode(['from' => '2026-01-01', 'to' => '2026-01-02'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $this->assertDocumentedJson($spec, '/v1/admin/reports', 'post', 201, (string) $client->getResponse()->getContent());
        $report = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $client->request('GET', '/v1/admin/reports/' . $report['id'] . '/download', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $staff]);
        self::assertResponseStatusCodeSame(409);
        $this->assertDocumentedJson($spec, '/v1/admin/reports/{id}/download', 'get', 409, (string) $client->getResponse()->getContent());
        self::assertArrayHasKey('application/pdf', $spec['paths']['/v1/admin/reports/{id}/download']['get']['responses']['200']['content']);
    }

    /** @param array<string, mixed> $spec */
    private function assertDocumentedJson(array $spec, string $path, string $method, int $status, string $json): void
    {
        $schema = $spec['paths'][$path][$method]['responses'][$status]['content']['application/json']['schema'];
        $this->assertSchema(json_decode($json, true, flags: JSON_THROW_ON_ERROR), $schema, $spec);
    }

    /** @param array<string, mixed> $schema
     *  @param array<string, mixed> $spec
     */
    private function assertSchema(mixed $value, array $schema, array $spec): void
    {
        if (isset($schema['$ref'])) {
            $name = substr($schema['$ref'], strlen('#/components/schemas/'));
            $this->assertSchema($value, $spec['components']['schemas'][$name], $spec);

            return;
        }
        if (null === $value && ($schema['nullable'] ?? false)) {
            return;
        }
        match ($schema['type'] ?? null) {
            'object', 'array' => self::assertIsArray($value),
            'string' => self::assertIsString($value),
            'integer' => self::assertIsInt($value),
            'boolean' => self::assertIsBool($value),
            default => self::fail('Unsupported contract schema type'),
        };
        if ('object' === $schema['type']) {
            foreach ($schema['required'] ?? [] as $name) {
                self::assertArrayHasKey($name, $value);
            }
            foreach ($schema['properties'] ?? [] as $name => $property) {
                if (array_key_exists($name, $value)) {
                    $this->assertSchema($value[$name], $property, $spec);
                }
            }
        } elseif ('array' === $schema['type']) {
            foreach ($value as $item) {
                $this->assertSchema($item, $schema['items'], $spec);
            }
        }
    }
}
