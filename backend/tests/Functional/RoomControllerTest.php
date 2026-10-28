<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Functional\Support\CreatesTokens;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RoomControllerTest extends WebTestCase
{
    use CreatesTokens;

    private KernelBrowser $client;
    private string $staffToken;
    private string $userToken;
    private int $hotelId;
    private int $otherHotelId;
    private int $roomTypeId;
    private int $otherHotelRoomTypeId;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();

        $this->connection()->executeStatement(
            'TRUNCATE TABLE room_type_inventory, reservation, room, room_type, hotel, app_user RESTART IDENTITY CASCADE',
        );
        $this->staffToken = $this->tokenFor('staff@example.com', ['ROLE_STAFF']);
        $this->userToken = $this->tokenFor('customer@example.com', ['ROLE_USER']);

        $this->hotelId = $this->createHotel('Hotel One');
        $this->otherHotelId = $this->createHotel('Hotel Two');
        $this->roomTypeId = $this->createRoomType($this->hotelId, 'Standard');
        $this->otherHotelRoomTypeId = $this->createRoomType($this->otherHotelId, 'Suite');
    }

    public function testStaffCanCreateReadUpdateAndDeleteARoom(): void
    {
        $this->request('POST', $this->roomsUrl(), $this->staffToken, ['roomTypeId' => $this->roomTypeId, 'roomNumber' => '101', 'floor' => 1]);
        self::assertResponseStatusCodeSame(201);
        $created = $this->payload();
        $id = (int) $created['id'];
        self::assertSame('active', $created['status']);
        self::assertSame('101', $created['roomNumber']);
        self::assertSame($this->hotelId, $created['hotelId']);

        $this->request('GET', $this->roomsUrl() . '/' . $id);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->payload()['floor']);

        $this->request('PUT', $this->roomsUrl() . '/' . $id, $this->staffToken, [
            'roomTypeId' => $this->roomTypeId, 'roomNumber' => '102', 'floor' => 2, 'status' => 'maintenance',
        ]);
        self::assertResponseIsSuccessful();

        // Reads must not serve the pre-update cached copy.
        $this->request('GET', $this->roomsUrl() . '/' . $id);
        $room = $this->payload();
        self::assertSame('102', $room['roomNumber']);
        self::assertSame('maintenance', $room['status']);

        $this->request('GET', $this->roomsUrl());
        self::assertCount(1, $this->payload());

        $this->request('DELETE', $this->roomsUrl() . '/' . $id, $this->staffToken);
        self::assertResponseStatusCodeSame(204);

        $this->request('GET', $this->roomsUrl() . '/' . $id);
        self::assertResponseStatusCodeSame(404);
        $this->request('GET', $this->roomsUrl());
        self::assertSame([], $this->payload());
    }

    public function testWritesRequireStaffRole(): void
    {
        $body = ['roomTypeId' => $this->roomTypeId, 'roomNumber' => '101'];
        $this->request('POST', $this->roomsUrl(), null, $body);
        self::assertResponseStatusCodeSame(401);
        $this->request('POST', $this->roomsUrl(), $this->userToken, $body);
        self::assertResponseStatusCodeSame(403);

        $id = $this->createRoom($this->hotelId, $this->roomTypeId, '101');
        $this->request('PUT', $this->roomsUrl() . '/' . $id, $this->userToken, $body);
        self::assertResponseStatusCodeSame(403);
        $this->request('DELETE', $this->roomsUrl() . '/' . $id, $this->userToken);
        self::assertResponseStatusCodeSame(403);
        $this->request('DELETE', $this->roomsUrl() . '/' . $id);
        self::assertResponseStatusCodeSame(401);

        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT count(*) FROM room'));
    }

    public function testInvalidPayloadsAreRejected(): void
    {
        $this->request('POST', $this->roomsUrl(), $this->staffToken, ['roomTypeId' => $this->roomTypeId, 'roomNumber' => '']);
        self::assertResponseStatusCodeSame(422);

        $this->request('POST', $this->roomsUrl(), $this->staffToken, ['roomTypeId' => $this->roomTypeId, 'roomNumber' => '1', 'status' => 'haunted']);
        self::assertResponseStatusCodeSame(422);

        $this->request('POST', $this->roomsUrl(), $this->staffToken, ['roomTypeId' => 0, 'roomNumber' => '1']);
        self::assertResponseStatusCodeSame(422);

        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT count(*) FROM room'));
    }

    public function testRoomTypeFromAnotherHotelIsRejected(): void
    {
        $this->request('POST', $this->roomsUrl(), $this->staffToken, ['roomTypeId' => $this->otherHotelRoomTypeId, 'roomNumber' => '101']);
        self::assertResponseStatusCodeSame(422);

        $this->request('POST', $this->roomsUrl(), $this->staffToken, ['roomTypeId' => 99999, 'roomNumber' => '101']);
        self::assertResponseStatusCodeSame(422);

        $id = $this->createRoom($this->hotelId, $this->roomTypeId, '101');
        $this->request('PUT', $this->roomsUrl() . '/' . $id, $this->staffToken, ['roomTypeId' => $this->otherHotelRoomTypeId, 'roomNumber' => '101']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame($this->roomTypeId, (int) $this->connection()->fetchOne('SELECT room_type_id FROM room WHERE id = :id', ['id' => $id]));
    }

    public function testRoomNumberMustBeUniquePerHotelButMayRepeatAcrossHotels(): void
    {
        $this->createRoom($this->hotelId, $this->roomTypeId, '101');

        $this->request('POST', $this->roomsUrl(), $this->staffToken, ['roomTypeId' => $this->roomTypeId, 'roomNumber' => '101']);
        self::assertResponseStatusCodeSame(409);

        $this->request('POST', $this->roomsUrl($this->otherHotelId), $this->staffToken, ['roomTypeId' => $this->otherHotelRoomTypeId, 'roomNumber' => '101']);
        self::assertResponseStatusCodeSame(201);
    }

    public function testRoomsAreScopedToTheirHotel(): void
    {
        $id = $this->createRoom($this->hotelId, $this->roomTypeId, '101');

        $this->request('GET', $this->roomsUrl($this->otherHotelId) . '/' . $id);
        self::assertResponseStatusCodeSame(404);
        $this->request('DELETE', $this->roomsUrl($this->otherHotelId) . '/' . $id, $this->staffToken);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT count(*) FROM room'));

        $this->request('GET', $this->roomsUrl(99999));
        self::assertResponseStatusCodeSame(404);
        $this->request('POST', $this->roomsUrl(99999), $this->staffToken, ['roomTypeId' => $this->roomTypeId, 'roomNumber' => '1']);
        self::assertResponseStatusCodeSame(404);
    }

    private function roomsUrl(?int $hotelId = null): string
    {
        return sprintf('/v1/hotels/%d/rooms', $hotelId ?? $this->hotelId);
    }

    private function createHotel(string $name): int
    {
        $this->request('POST', '/v1/hotels', $this->staffToken, [
            'name' => $name, 'address' => '1 Main St', 'city' => 'Paris', 'country' => 'FR', 'starRating' => 3, 'amenities' => [],
        ]);
        self::assertResponseStatusCodeSame(201);

        return (int) $this->payload()['id'];
    }

    private function createRoomType(int $hotelId, string $name): int
    {
        $this->request('POST', sprintf('/v1/hotels/%d/room-types', $hotelId), $this->staffToken, [
            'name' => $name, 'description' => 'd', 'maxOccupancy' => 2, 'basePrice' => 10000, 'amenities' => [],
        ]);
        self::assertResponseStatusCodeSame(201);

        return (int) $this->payload()['id'];
    }

    private function createRoom(int $hotelId, int $roomTypeId, string $number): int
    {
        $this->request('POST', $this->roomsUrl($hotelId), $this->staffToken, ['roomTypeId' => $roomTypeId, 'roomNumber' => $number]);
        self::assertResponseStatusCodeSame(201);

        return (int) $this->payload()['id'];
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
