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

final class AvailabilityControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement('TRUNCATE TABLE room_type_rate, room_type_inventory, reservation, room_type, hotel, app_user RESTART IDENTITY CASCADE');
    }

    public function testItReturnsAvailabilityAndRatesForStayRange(): void
    {
        $client = $this->client;
        ['token' => $token] = $this->createAuthenticatedUser('guest@example.com');
        ['hotelId' => $hotelId, 'roomTypeId' => $roomTypeId] = $this->seedAvailabilityScenario();

        $client->request(
            'GET',
            sprintf('/v1/hotels/%d/room-types/%d/availability?startDate=2026-10-01&endDate=2026-10-03&roomCount=2', $hotelId, $roomTypeId),
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );

        self::assertResponseStatusCodeSame(200);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertTrue($payload['canBook']);
        self::assertSame(2, $payload['nights']);
        self::assertSame(100000, $payload['totalPrice']);
        self::assertCount(2, $payload['days']);
        self::assertSame(6, $payload['minAvailableRooms']);
    }

    public function testItAllowsAnonymousAvailabilityChecks(): void
    {
        $client = $this->client;
        ['hotelId' => $hotelId, 'roomTypeId' => $roomTypeId] = $this->seedAvailabilityScenario();

        $client->request(
            'GET',
            sprintf('/v1/hotels/%d/room-types/%d/availability?startDate=2026-10-01&endDate=2026-10-03', $hotelId, $roomTypeId),
        );

        self::assertResponseStatusCodeSame(200);
    }

    public function testItValidatesQueryString(): void
    {
        $client = $this->client;
        ['token' => $token] = $this->createAuthenticatedUser('guest2@example.com');
        ['hotelId' => $hotelId, 'roomTypeId' => $roomTypeId] = $this->seedAvailabilityScenario();

        $client->request(
            'GET',
            sprintf('/v1/hotels/%d/room-types/%d/availability?startDate=invalid&endDate=2026-10-03&roomCount=0', $hotelId, $roomTypeId),
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );

        self::assertResponseStatusCodeSame(422);
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

    /**
     * @return array{hotelId: int, roomTypeId: int}
     */
    private function seedAvailabilityScenario(): array
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO hotel (name, address, city, country, star_rating, description, amenities, created_at, updated_at)
                VALUES ('Hotel Gamma', 'Street 3', 'City', 'Country', 4, 'desc', '[]'::jsonb, now(), now())
                SQL,
        );
        $hotelId = (int) $connection->lastInsertId();

        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO room_type (hotel_id, name, description, max_occupancy, base_price, amenities, created_at, updated_at)
                VALUES (:hotelId, 'Suite', 'desc', 3, 25000, '[]'::jsonb, now(), now())
                SQL,
            ['hotelId' => $hotelId],
        );
        $roomTypeId = (int) $connection->lastInsertId();

        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO room_type_inventory (hotel_id, room_type_id, date, total_inventory, total_reserved, updated_at)
                VALUES
                    (:hotelId, :roomTypeId, '2026-10-01', 10, 5, now()),
                    (:hotelId, :roomTypeId, '2026-10-02', 10, 5, now())
                SQL,
            ['hotelId' => $hotelId, 'roomTypeId' => $roomTypeId],
        );

        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO room_type_rate (hotel_id, room_type_id, date, price, updated_at)
                VALUES
                    (:hotelId, :roomTypeId, '2026-10-01', 25000, now()),
                    (:hotelId, :roomTypeId, '2026-10-02', 25000, now())
                SQL,
            ['hotelId' => $hotelId, 'roomTypeId' => $roomTypeId],
        );

        return ['hotelId' => $hotelId, 'roomTypeId' => $roomTypeId];
    }
}
