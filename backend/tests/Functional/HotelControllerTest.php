<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Functional\Support\CreatesTokens;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HotelControllerTest extends WebTestCase
{
    use CreatesTokens;

    private KernelBrowser $client;
    private string $staffToken;
    private string $userToken;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();

        $this->connection()->executeStatement(
            'TRUNCATE TABLE room_type_inventory, reservation, room, room_type, hotel, app_user RESTART IDENTITY CASCADE',
        );
        $this->staffToken = $this->tokenFor('staff@example.com', ['ROLE_STAFF']);
        $this->userToken = $this->tokenFor('customer@example.com', ['ROLE_USER']);
    }

    public function testStaffCanCreateReadUpdateAndDeleteAHotel(): void
    {
        $this->request('POST', '/v1/hotels', $this->staffToken, $this->hotelBody('Grand', 'Paris'));
        self::assertResponseStatusCodeSame(201);
        $created = $this->payload();
        $id = (int) $created['id'];
        self::assertSame('Grand', $created['name']);
        self::assertStringEndsWith('/v1/hotels/' . $id, (string) $this->client->getResponse()->headers->get('Location'));

        $this->request('GET', '/v1/hotels/' . $id);
        self::assertResponseIsSuccessful();
        self::assertSame('Paris', $this->payload()['city']);

        $this->request('PUT', '/v1/hotels/' . $id, $this->staffToken, $this->hotelBody('Grand Palace', 'Lyon'));
        self::assertResponseIsSuccessful();

        // Reads must not serve the pre-update cached copy.
        $this->request('GET', '/v1/hotels/' . $id);
        self::assertSame('Grand Palace', $this->payload()['name']);
        self::assertSame('Lyon', $this->payload()['city']);

        $this->request('DELETE', '/v1/hotels/' . $id, $this->staffToken);
        self::assertResponseStatusCodeSame(204);

        $this->request('GET', '/v1/hotels/' . $id);
        self::assertResponseStatusCodeSame(404);
    }

    public function testListIsPublicAndFiltersByCityAndReflectsWrites(): void
    {
        $this->request('GET', '/v1/hotels');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->payload());

        $this->request('POST', '/v1/hotels', $this->staffToken, $this->hotelBody('A', 'Paris'));
        $this->request('POST', '/v1/hotels', $this->staffToken, $this->hotelBody('B', 'Rome'));

        $this->request('GET', '/v1/hotels');
        self::assertCount(2, $this->payload());

        $this->request('GET', '/v1/hotels?city=Rome');
        $rows = $this->payload();
        self::assertCount(1, $rows);
        self::assertSame('B', $rows[0]['name']);
    }

    public function testWritesRequireStaffRole(): void
    {
        $this->request('POST', '/v1/hotels', null, $this->hotelBody('X', 'Y'));
        self::assertResponseStatusCodeSame(401);

        $this->request('POST', '/v1/hotels', $this->userToken, $this->hotelBody('X', 'Y'));
        self::assertResponseStatusCodeSame(403);

        $id = $this->createHotel();
        $this->request('PUT', '/v1/hotels/' . $id, $this->userToken, $this->hotelBody('X', 'Y'));
        self::assertResponseStatusCodeSame(403);
        $this->request('DELETE', '/v1/hotels/' . $id, $this->userToken);
        self::assertResponseStatusCodeSame(403);
        $this->request('DELETE', '/v1/hotels/' . $id);
        self::assertResponseStatusCodeSame(401);

        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT count(*) FROM hotel'));
    }

    public function testInvalidPayloadIsRejected(): void
    {
        $body = $this->hotelBody('', 'Paris');
        $body['starRating'] = 9;
        $this->request('POST', '/v1/hotels', $this->staffToken, $body);
        self::assertResponseStatusCodeSame(422);

        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT count(*) FROM hotel'));
    }

    public function testUnknownHotelReturns404(): void
    {
        $this->request('GET', '/v1/hotels/999');
        self::assertResponseStatusCodeSame(404);
        $this->request('PUT', '/v1/hotels/999', $this->staffToken, $this->hotelBody('X', 'Y'));
        self::assertResponseStatusCodeSame(404);
        $this->request('DELETE', '/v1/hotels/999', $this->staffToken);
        self::assertResponseStatusCodeSame(404);
    }

    public function testDeletingAHotelCascadesToRoomsRoomTypesAndInventory(): void
    {
        $hotelId = $this->createHotel();
        $this->request('POST', sprintf('/v1/hotels/%d/room-types', $hotelId), $this->staffToken, [
            'name' => 'Standard', 'description' => 'd', 'maxOccupancy' => 2, 'basePrice' => 10000, 'amenities' => [],
        ]);
        self::assertResponseStatusCodeSame(201);
        $roomTypeId = (int) $this->payload()['id'];
        $this->request('POST', sprintf('/v1/hotels/%d/rooms', $hotelId), $this->staffToken, ['roomTypeId' => $roomTypeId, 'roomNumber' => '101']);
        self::assertResponseStatusCodeSame(201);

        $this->request('DELETE', '/v1/hotels/' . $hotelId, $this->staffToken);
        self::assertResponseStatusCodeSame(204);

        foreach (['room', 'room_type', 'room_type_inventory'] as $table) {
            self::assertSame(0, (int) $this->connection()->fetchOne('SELECT count(*) FROM ' . $table), $table);
        }
    }

    public function testHotelAndRoomTypeWithReservationsCannotBeDeleted(): void
    {
        $hotelId = $this->createHotel();
        $this->request('POST', sprintf('/v1/hotels/%d/room-types', $hotelId), $this->staffToken, [
            'name' => 'Standard', 'description' => 'd', 'maxOccupancy' => 2, 'basePrice' => 10000, 'amenities' => [],
        ]);
        $roomTypeId = (int) $this->payload()['id'];
        $userId = (int) $this->connection()->fetchOne('SELECT id FROM app_user LIMIT 1');
        $this->connection()->executeStatement(
            <<<'SQL'
                INSERT INTO reservation
                    (id, user_id, hotel_id, room_type_id, start_date, end_date, room_count, status, total_price, created_at, updated_at)
                VALUES
                    ('0190e5b0-0000-7000-8000-000000000001', :u, :h, :r, '2027-01-01', '2027-01-02', 1, 'pending', 1000, now(), now())
                SQL,
            ['u' => $userId, 'h' => $hotelId, 'r' => $roomTypeId],
        );

        $this->request('DELETE', sprintf('/v1/hotels/%d/room-types/%d', $hotelId, $roomTypeId), $this->staffToken);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT count(*) FROM room_type'));

        $this->request('DELETE', '/v1/hotels/' . $hotelId, $this->staffToken);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT count(*) FROM hotel'));
    }

    private function createHotel(): int
    {
        $this->request('POST', '/v1/hotels', $this->staffToken, $this->hotelBody('Seed', 'Paris'));
        self::assertResponseStatusCodeSame(201);

        return (int) $this->payload()['id'];
    }

    /**
     * @return array<string, mixed>
     */
    private function hotelBody(string $name, string $city): array
    {
        return [
            'name' => $name,
            'address' => '1 Main St',
            'city' => $city,
            'country' => 'FR',
            'starRating' => 4,
            'description' => 'desc',
            'amenities' => ['wifi'],
        ];
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function request(string $method, string $uri, ?string $token = null, ?array $body = null): void
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if (null !== $token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }
        $this->client->request($method, $uri, server: $server, content: null === $body ? null : json_encode($body, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<mixed>
     */
    private function payload(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return $connection;
    }
}
