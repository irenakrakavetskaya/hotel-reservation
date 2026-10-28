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

final class PaymentControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement('TRUNCATE TABLE room_type_inventory, reservation, room_type, hotel, app_user RESTART IDENTITY CASCADE');
    }

    public function testItProcessesApprovedPaymentForPendingReservation(): void
    {
        $client = $this->client;
        ['token' => $token, 'userId' => $userId] = $this->createAuthenticatedUser('buyer@example.com');
        $reservationId = $this->seedPendingReservation($userId);

        $client->request(
            'POST',
            '/v1/payments',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
            content: json_encode(['reservationID' => $reservationId, 'approved' => true], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(200);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($reservationId, $payload['reservationId']);
        self::assertSame('paid', $payload['status']);
        self::assertSame(2, $this->totalReserved());
    }

    public function testRejectedPaymentReleasesInventoryAndLaterCancelDoesNotReleaseTwice(): void
    {
        $client = $this->client;
        ['token' => $token, 'userId' => $userId] = $this->createAuthenticatedUser('decline@example.com');
        $reservationId = $this->seedPendingReservation($userId);
        $server = ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'];

        self::assertSame(2, $this->totalReserved());

        $client->request('POST', '/v1/payments', server: $server, content: json_encode(['reservationID' => $reservationId, 'approved' => false], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200);
        self::assertSame('rejected', json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['status']);
        self::assertSame(0, $this->totalReserved());

        // Repeating the decline is idempotent and must not release again.
        $client->request('POST', '/v1/payments', server: $server, content: json_encode(['reservationID' => $reservationId, 'approved' => false], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200);
        self::assertSame(0, $this->totalReserved());

        $client->request('DELETE', '/v1/reservations/' . $reservationId, server: $server);
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->totalReserved());
    }

    public function testItRequiresAuthentication(): void
    {
        $client = $this->client;

        $client->request(
            'POST',
            '/v1/payments',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['reservationID' => Uuid::v7()->toRfc4122(), 'approved' => true], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(401);
    }

    public function testItValidatesRequestPayload(): void
    {
        $client = $this->client;
        ['token' => $token] = $this->createAuthenticatedUser('buyer2@example.com');

        $client->request(
            'POST',
            '/v1/payments',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
            content: json_encode(['reservationID' => 'not-a-uuid', 'approved' => 'yes'], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * @return array{token: string, userId: int}
     */
    private function createAuthenticatedUser(string $email): array
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $passwordHasher */
        $passwordHasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        /** @var JWTTokenManagerInterface $tokenManager */
        $tokenManager = self::getContainer()->get(JWTTokenManagerInterface::class);

        $hashingUser = new User($email, 'placeholder');
        $hashedPassword = $passwordHasher->hashPassword($hashingUser, 'secret-password');
        $user = new User($email, $hashedPassword);

        $entityManager->persist($user);
        $entityManager->flush();

        return [
            'token' => $tokenManager->create($user),
            'userId' => $user->getId(),
        ];
    }

    private function seedPendingReservation(int $userId): string
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO hotel (name, address, city, country, star_rating, description, amenities, created_at, updated_at)
                VALUES ('Hotel Alpha', 'Street 1', 'City', 'Country', 4, 'desc', '[]'::jsonb, now(), now())
                SQL,
        );

        $hotelId = (int) $connection->lastInsertId();

        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO room_type (hotel_id, name, description, max_occupancy, base_price, amenities, created_at, updated_at)
                VALUES (:hotelId, 'Standard', 'desc', 2, 10000, '[]'::jsonb, now(), now())
                SQL,
            ['hotelId' => $hotelId],
        );

        $roomTypeId = (int) $connection->lastInsertId();
        $reservationId = Uuid::v7()->toRfc4122();

        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO reservation
                    (id, user_id, hotel_id, room_type_id, start_date, end_date, room_count, status, total_price, created_at, updated_at)
                VALUES
                    (:id, :userId, :hotelId, :roomTypeId, :startDate, :endDate, 1, 'pending', 10000, CURRENT_TIMESTAMP(0), CURRENT_TIMESTAMP(0))
                SQL,
            [
                'id' => $reservationId,
                'userId' => $userId,
                'hotelId' => $hotelId,
                'roomTypeId' => $roomTypeId,
                'startDate' => '2026-10-01',
                'endDate' => '2026-10-03',
            ],
        );

        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO room_type_inventory (hotel_id, room_type_id, date, total_inventory, total_reserved, updated_at)
                VALUES (:hotelId, :roomTypeId, '2026-10-01', 10, 1, now()), (:hotelId, :roomTypeId, '2026-10-02', 10, 1, now())
                SQL,
            ['hotelId' => $hotelId, 'roomTypeId' => $roomTypeId],
        );

        return $reservationId;
    }

    private function totalReserved(): int
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return (int) $connection->fetchOne('SELECT COALESCE(SUM(total_reserved), 0) FROM room_type_inventory');
    }
}
