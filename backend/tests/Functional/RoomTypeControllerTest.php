<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\User\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

final class RoomTypeControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        // The client must be created before the first getContainer() call —
        // WebTestCase::createClient() forbids booting the kernel beforehand.
        $this->client = static::createClient();

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement('TRUNCATE TABLE room_type, hotel, app_user RESTART IDENTITY CASCADE');
    }

    public function testStaffCanCreateRoomType(): void
    {
        $client = $this->client;
        ['token' => $token] = $this->createAuthenticatedUser('staff@example.com', ['ROLE_STAFF']);
        $hotelId = $this->seedHotel();

        $client->request(
            'POST',
            sprintf('/v1/hotels/%d/room-types', $hotelId),
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'name' => 'Deluxe',
                'description' => 'City view',
                'maxOccupancy' => 3,
                'basePrice' => 15000,
                'amenities' => ['wifi', 'tv'],
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(201);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('Deluxe', $payload['name']);
        self::assertSame(3, $payload['maxOccupancy']);
        self::assertSame(15000, $payload['basePrice']);
    }

    public function testNonStaffCannotCreateRoomType(): void
    {
        $client = $this->client;
        ['token' => $token] = $this->createAuthenticatedUser('user@example.com');
        $hotelId = $this->seedHotel();

        $client->request(
            'POST',
            sprintf('/v1/hotels/%d/room-types', $hotelId),
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'name' => 'Deluxe',
                'maxOccupancy' => 3,
                'basePrice' => 15000,
                'amenities' => [],
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testCreateValidationFailureReturns422(): void
    {
        $client = $this->client;
        ['token' => $token] = $this->createAuthenticatedUser('staff2@example.com', ['ROLE_STAFF']);
        $hotelId = $this->seedHotel();

        $client->request(
            'POST',
            sprintf('/v1/hotels/%d/room-types', $hotelId),
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'name' => '',
                'maxOccupancy' => 0,
                'basePrice' => -1,
                'amenities' => ['wifi'],
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testListAndGetRoomTypesArePublic(): void
    {
        $hotelId = $this->seedHotel();
        $roomTypeId = $this->seedRoomType($hotelId, 'Standard');

        $this->client->request('GET', sprintf('/v1/hotels/%d/room-types', $hotelId));
        self::assertResponseIsSuccessful();
        $list = $this->decode();
        self::assertCount(1, $list);
        self::assertSame('Standard', $list[0]['name']);

        $this->client->request('GET', sprintf('/v1/hotels/%d/room-types/%d', $hotelId, $roomTypeId));
        self::assertResponseIsSuccessful();
        self::assertSame($roomTypeId, $this->decode()['id']);
    }

    public function testListForUnknownHotelReturns404(): void
    {
        $this->client->request('GET', '/v1/hotels/999999/room-types');

        self::assertResponseStatusCodeSame(404);
    }

    public function testGetRoomTypeOfAnotherHotelReturns404(): void
    {
        $hotelId = $this->seedHotel();
        $otherHotelId = $this->seedHotel();
        $roomTypeId = $this->seedRoomType($hotelId, 'Standard');

        $this->client->request('GET', sprintf('/v1/hotels/%d/room-types/%d', $otherHotelId, $roomTypeId));

        self::assertResponseStatusCodeSame(404);
    }

    public function testCreateForUnknownHotelReturns404(): void
    {
        ['token' => $token] = $this->createAuthenticatedUser('staff-missing@example.com', ['ROLE_STAFF']);

        $this->client->request('POST', '/v1/hotels/999999/room-types', server: $this->authHeaders($token), content: $this->validPayload('Ghost'));

        self::assertResponseStatusCodeSame(404);
    }

    public function testCreatingRoomTypeBuildsItsInventoryWindow(): void
    {
        ['token' => $token] = $this->createAuthenticatedUser('staff-inv@example.com', ['ROLE_STAFF']);
        $hotelId = $this->seedHotel();

        $this->client->request('POST', sprintf('/v1/hotels/%d/room-types', $hotelId), server: $this->authHeaders($token), content: $this->validPayload('Suite'));
        self::assertResponseStatusCodeSame(201);
        self::assertStringContainsString('/room-types/' . $this->decode()['id'], (string) $this->client->getResponse()->headers->get('Location'));

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        // No rooms exist yet, so the window exists with zero capacity.
        self::assertGreaterThan(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM room_type_inventory WHERE hotel_id = :h', ['h' => $hotelId]));
    }

    public function testStaffCanUpdateRoomTypeAndReadSeesTheChange(): void
    {
        ['token' => $token] = $this->createAuthenticatedUser('staff-upd@example.com', ['ROLE_STAFF']);
        $hotelId = $this->seedHotel();
        $roomTypeId = $this->seedRoomType($hotelId, 'Standard');
        $url = sprintf('/v1/hotels/%d/room-types/%d', $hotelId, $roomTypeId);

        // Prime the read cache so we prove the update invalidates it.
        $this->client->request('GET', $url);
        self::assertSame('Standard', $this->decode()['name']);

        $this->client->request('PUT', $url, server: $this->authHeaders($token), content: $this->validPayload('Renamed'));
        self::assertResponseIsSuccessful();
        self::assertSame('Renamed', $this->decode()['name']);

        $this->client->request('GET', $url);
        $afterUpdate = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($afterUpdate);
        self::assertSame('Renamed', $afterUpdate['name']);
    }

    public function testUpdateNonExistentRoomTypeReturns404(): void
    {
        ['token' => $token] = $this->createAuthenticatedUser('staff-upd404@example.com', ['ROLE_STAFF']);
        $hotelId = $this->seedHotel();

        $this->client->request('PUT', sprintf('/v1/hotels/%d/room-types/999999', $hotelId), server: $this->authHeaders($token), content: $this->validPayload('X'));

        self::assertResponseStatusCodeSame(404);
    }

    public function testNonStaffCannotUpdateOrDeleteRoomType(): void
    {
        ['token' => $token] = $this->createAuthenticatedUser('plain@example.com');
        $hotelId = $this->seedHotel();
        $roomTypeId = $this->seedRoomType($hotelId, 'Standard');
        $url = sprintf('/v1/hotels/%d/room-types/%d', $hotelId, $roomTypeId);

        $this->client->request('PUT', $url, server: $this->authHeaders($token), content: $this->validPayload('X'));
        self::assertResponseStatusCodeSame(403);

        $this->client->request('DELETE', $url, server: $this->authHeaders($token));
        self::assertResponseStatusCodeSame(403);
    }

    public function testStaffCanDeleteRoomTypeWithoutReservations(): void
    {
        ['token' => $token] = $this->createAuthenticatedUser('staff-del@example.com', ['ROLE_STAFF']);
        $hotelId = $this->seedHotel();
        $roomTypeId = $this->seedRoomType($hotelId, 'Standard');
        $url = sprintf('/v1/hotels/%d/room-types/%d', $hotelId, $roomTypeId);

        $this->client->request('DELETE', $url, server: $this->authHeaders($token));
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(404);
    }

    public function testDeleteRoomTypeWithReservationsReturns409(): void
    {
        ['token' => $token, 'userId' => $userId] = $this->createAuthenticatedUser('staff-del409@example.com', ['ROLE_STAFF']);
        $hotelId = $this->seedHotel();
        $roomTypeId = $this->seedRoomType($hotelId, 'Standard');

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO reservation
                    (id, user_id, hotel_id, room_type_id, start_date, end_date, room_count, status, total_price, created_at, updated_at)
                VALUES (:id, :userId, :hotelId, :roomTypeId, '2027-01-01', '2027-01-02', 1, 'pending', 10000, now(), now())
                SQL,
            ['id' => Uuid::v7()->toRfc4122(), 'userId' => $userId, 'hotelId' => $hotelId, 'roomTypeId' => $roomTypeId],
        );

        $this->client->request('DELETE', sprintf('/v1/hotels/%d/room-types/%d', $hotelId, $roomTypeId), server: $this->authHeaders($token));

        self::assertResponseStatusCodeSame(409);
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM room_type WHERE id = :id', ['id' => $roomTypeId]));
    }

    /** @return array<mixed> */
    private function decode(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, string> */
    private function authHeaders(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'];
    }

    private function validPayload(string $name): string
    {
        return json_encode(['name' => $name, 'description' => 'd', 'maxOccupancy' => 2, 'basePrice' => 10000, 'amenities' => []], JSON_THROW_ON_ERROR);
    }

    private function seedRoomType(int $hotelId, string $name): int
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO room_type (hotel_id, name, description, max_occupancy, base_price, amenities, created_at, updated_at)
                VALUES (:hotelId, :name, 'desc', 2, 10000, '[]'::jsonb, now(), now())
                SQL,
            ['hotelId' => $hotelId, 'name' => $name],
        );

        return (int) $connection->lastInsertId();
    }

    /**
     * @param list<string> $roles
     *
     * @return array{token: string, userId: int}
     */
    private function createAuthenticatedUser(string $email, array $roles = []): array
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $passwordHasher */
        $passwordHasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        /** @var JWTTokenManagerInterface $tokenManager */
        $tokenManager = self::getContainer()->get(JWTTokenManagerInterface::class);

        $hashingUser = new User($email, 'placeholder', $roles);
        $hashedPassword = $passwordHasher->hashPassword($hashingUser, 'secret-password');
        $user = new User($email, $hashedPassword, $roles);

        $entityManager->persist($user);
        $entityManager->flush();

        return [
            'token' => $tokenManager->create($user),
            'userId' => $user->getId(),
        ];
    }

    private function seedHotel(): int
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO hotel (name, address, city, country, star_rating, description, amenities, created_at, updated_at)
                VALUES ('Hotel Beta', 'Street 2', 'City', 'Country', 5, 'desc', '[]'::jsonb, now(), now())
                SQL,
        );

        return (int) $connection->lastInsertId();
    }
}
