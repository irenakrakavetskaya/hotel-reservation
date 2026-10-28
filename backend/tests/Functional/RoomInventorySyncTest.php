<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\Reservation\Service\InventoryPrepopulationJob;
use App\Domain\User\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RoomInventorySyncTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $token;
    private int $hotelId;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();

        $connection = $this->connection();
        $connection->executeStatement('TRUNCATE TABLE room_type_inventory, room, room_type, hotel, app_user RESTART IDENTITY CASCADE');

        $this->token = $this->createStaffToken();
        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO hotel (name, address, city, country, star_rating, description, amenities, created_at, updated_at)
                VALUES ('Hotel Sync', 'Street 3', 'City', 'Country', 4, 'desc', '[]'::jsonb, now(), now())
                SQL,
        );
        $this->hotelId = (int) $connection->lastInsertId();
    }

    public function testNewRoomTypeGetsInventoryRowsAcrossTheWholeWindow(): void
    {
        $roomTypeId = $this->createRoomType('Standard');

        $expectedDays = InventoryPrepopulationJob::WINDOW_DAYS + 1;
        self::assertSame($expectedDays, $this->inventoryRowCount($roomTypeId));
        self::assertSame([0], $this->distinctInventory($roomTypeId));
    }

    public function testAddDeactivateAndDeleteRoomsKeepInventoryInSync(): void
    {
        $roomTypeId = $this->createRoomType('Standard');

        $first = $this->createRoom($roomTypeId, '101');
        $second = $this->createRoom($roomTypeId, '102');
        self::assertSame([2], $this->distinctInventory($roomTypeId));

        $this->request('PUT', sprintf('/v1/hotels/%d/rooms/%d', $this->hotelId, $first), ['roomTypeId' => $roomTypeId, 'roomNumber' => '101', 'status' => 'inactive']);
        self::assertResponseStatusCodeSame(200);
        self::assertSame([1], $this->distinctInventory($roomTypeId));

        $this->request('DELETE', sprintf('/v1/hotels/%d/rooms/%d', $this->hotelId, $second));
        self::assertResponseStatusCodeSame(204);
        self::assertSame([0], $this->distinctInventory($roomTypeId));
    }

    public function testMovingARoomBetweenTypesAdjustsBothTypes(): void
    {
        $source = $this->createRoomType('Standard');
        $target = $this->createRoomType('Deluxe');
        $roomId = $this->createRoom($source, '201');
        $this->createRoom($source, '202');

        $this->request('PUT', sprintf('/v1/hotels/%d/rooms/%d', $this->hotelId, $roomId), ['roomTypeId' => $target, 'roomNumber' => '201']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([1], $this->distinctInventory($source));
        self::assertSame([1], $this->distinctInventory($target));
    }

    public function testChangeBelowHeldReservationsIsRejectedAndNothingChanges(): void
    {
        $roomTypeId = $this->createRoomType('Standard');
        $roomId = $this->createRoom($roomTypeId, '301');
        $this->createRoom($roomTypeId, '302');

        $heldDate = (new \DateTimeImmutable('today'))->modify('+10 days')->format('Y-m-d');
        $this->connection()->executeStatement(
            'UPDATE room_type_inventory SET total_reserved = 2 WHERE room_type_id = :r AND date = :d',
            ['r' => $roomTypeId, 'd' => $heldDate],
        );

        // 2 rooms -> ceiling 2; dropping to 1 room -> ceiling 1 < 2 held.
        $this->request('PUT', sprintf('/v1/hotels/%d/rooms/%d', $this->hotelId, $roomId), ['roomTypeId' => $roomTypeId, 'roomNumber' => '301', 'status' => 'inactive']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame([2], $this->distinctInventory($roomTypeId));
        self::assertSame('active', $this->connection()->fetchOne('SELECT status FROM room WHERE id = :id', ['id' => $roomId]));

        $this->request('DELETE', sprintf('/v1/hotels/%d/rooms/%d', $this->hotelId, $roomId));
        self::assertResponseStatusCodeSame(409);
        self::assertSame(2, (int) $this->connection()->fetchOne('SELECT count(*) FROM room WHERE room_type_id = :r', ['r' => $roomTypeId]));
        self::assertSame(2, (int) $this->connection()->fetchOne('SELECT total_reserved FROM room_type_inventory WHERE room_type_id = :r AND date = :d', ['r' => $roomTypeId, 'd' => $heldDate]));
    }

    /** @param array<string, mixed>|null $body */
    private function request(string $method, string $uri, ?array $body = null): void
    {
        $this->client->request(
            $method,
            $uri,
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->token, 'CONTENT_TYPE' => 'application/json'],
            content: null === $body ? null : json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    private function createRoomType(string $name): int
    {
        $this->request('POST', sprintf('/v1/hotels/%d/room-types', $this->hotelId), ['name' => $name, 'description' => 'desc', 'maxOccupancy' => 2, 'basePrice' => 10000, 'amenities' => []]);
        self::assertResponseStatusCodeSame(201);

        return (int) $this->payload()['id'];
    }

    private function createRoom(int $roomTypeId, string $number): int
    {
        $this->request('POST', sprintf('/v1/hotels/%d/rooms', $this->hotelId), ['roomTypeId' => $roomTypeId, 'roomNumber' => $number]);
        self::assertResponseStatusCodeSame(201);

        return (int) $this->payload()['id'];
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function inventoryRowCount(int $roomTypeId): int
    {
        return (int) $this->connection()->fetchOne(
            'SELECT count(*) FROM room_type_inventory WHERE room_type_id = :r AND date >= CURRENT_DATE',
            ['r' => $roomTypeId],
        );
    }

    /** @return list<int> */
    private function distinctInventory(int $roomTypeId): array
    {
        return array_map('intval', $this->connection()->fetchFirstColumn(
            'SELECT DISTINCT total_inventory FROM room_type_inventory WHERE room_type_id = :r AND date >= CURRENT_DATE',
            ['r' => $roomTypeId],
        ));
    }

    private function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return $connection;
    }

    private function createStaffToken(): string
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $passwordHasher */
        $passwordHasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        /** @var JWTTokenManagerInterface $tokenManager */
        $tokenManager = self::getContainer()->get(JWTTokenManagerInterface::class);

        $roles = ['ROLE_STAFF'];
        $hashed = $passwordHasher->hashPassword(new User('sync@example.com', 'placeholder', $roles), 'secret-password');
        $user = new User('sync@example.com', $hashed, $roles);
        $entityManager->persist($user);
        $entityManager->flush();

        return $tokenManager->create($user);
    }
}
