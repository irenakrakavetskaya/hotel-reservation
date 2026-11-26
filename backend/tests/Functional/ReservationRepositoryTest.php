<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\Reservation\Repository\ReservationRepository;
use App\Domain\User\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Edge paths of ReservationRepository (listing, payment settlement state
 * machine, cancellation failures) not covered by the happy-path controller
 * tests.
 */
final class ReservationRepositoryTest extends WebTestCase
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

    public function testListReturnsOnlyTheCallersReservationsNewestFirst(): void
    {
        ['token' => $token, 'userId' => $userId] = $this->createAuthenticatedUser('lister@example.com');
        ['userId' => $otherId] = $this->createAuthenticatedUser('other@example.com');
        ['hotelId' => $hotelId, 'roomTypeId' => $roomTypeId] = $this->seedRoomTypeWithInventory();

        $older = $this->seedReservation($userId, $hotelId, $roomTypeId, 'pending', '2026-01-01 10:00:00');
        $newer = $this->seedReservation($userId, $hotelId, $roomTypeId, 'pending', '2026-01-02 10:00:00');
        $this->seedReservation($otherId, $hotelId, $roomTypeId, 'pending', '2026-01-03 10:00:00');

        $this->client->request('GET', '/v1/reservations', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([$newer, $older], array_column($payload, 'reservationId'));
    }

    public function testRepositoryFindForUserIsEmptyWithoutReservations(): void
    {
        ['userId' => $userId] = $this->createAuthenticatedUser('empty@example.com');

        /** @var ReservationRepository $repository */
        $repository = self::getContainer()->get(ReservationRepository::class);

        self::assertSame([], $repository->findForUser($userId));
    }

    public function testPaymentForUnknownReservationReturns404(): void
    {
        ['token' => $token] = $this->createAuthenticatedUser('pay404@example.com');

        $this->pay($token, Uuid::v7()->toRfc4122(), true);

        self::assertResponseStatusCodeSame(404);
    }

    public function testPaidReservationCannotBeRejectedAndKeepsItsInventory(): void
    {
        ['token' => $token, 'userId' => $userId] = $this->createAuthenticatedUser('paid@example.com');
        ['hotelId' => $hotelId, 'roomTypeId' => $roomTypeId] = $this->seedRoomTypeWithInventory();
        $id = $this->seedReservation($userId, $hotelId, $roomTypeId, 'paid');

        $this->pay($token, $id, false);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('paid', $this->statusOf($id));
        self::assertSame(2, $this->totalReserved());
    }

    public function testRejectedReservationCannotBePaid(): void
    {
        ['token' => $token, 'userId' => $userId] = $this->createAuthenticatedUser('rejected@example.com');
        ['hotelId' => $hotelId, 'roomTypeId' => $roomTypeId] = $this->seedRoomTypeWithInventory();
        $id = $this->seedReservation($userId, $hotelId, $roomTypeId, 'rejected');

        $this->pay($token, $id, true);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('rejected', $this->statusOf($id));
    }

    public function testApprovingAnAlreadyPaidReservationIsIdempotent(): void
    {
        ['token' => $token, 'userId' => $userId] = $this->createAuthenticatedUser('paid-again@example.com');
        ['hotelId' => $hotelId, 'roomTypeId' => $roomTypeId] = $this->seedRoomTypeWithInventory();
        $id = $this->seedReservation($userId, $hotelId, $roomTypeId, 'paid');

        $this->pay($token, $id, true);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('paid', $this->statusOf($id));
    }

    public function testCancelingAnUnknownReservationReturns404(): void
    {
        ['token' => $token] = $this->createAuthenticatedUser('cancel404@example.com');

        $this->client->request('DELETE', '/v1/reservations/' . Uuid::v7()->toRfc4122(), server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testCancelRollsBackWhenInventoryRowsAreMissing(): void
    {
        ['token' => $token, 'userId' => $userId] = $this->createAuthenticatedUser('cancel409@example.com');
        ['hotelId' => $hotelId, 'roomTypeId' => $roomTypeId] = $this->seedRoomTypeWithInventory();
        $id = $this->seedReservation($userId, $hotelId, $roomTypeId, 'pending');

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement("DELETE FROM room_type_inventory WHERE date = '2027-01-02'");

        $this->client->request('DELETE', '/v1/reservations/' . $id, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('pending', $this->statusOf($id));
        self::assertSame(1, $this->totalReserved());
    }

    public function testRejectingPaymentRollsBackWhenInventoryRowsAreMissing(): void
    {
        ['token' => $token, 'userId' => $userId] = $this->createAuthenticatedUser('reject409@example.com');
        ['hotelId' => $hotelId, 'roomTypeId' => $roomTypeId] = $this->seedRoomTypeWithInventory();
        $id = $this->seedReservation($userId, $hotelId, $roomTypeId, 'pending');

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement("DELETE FROM room_type_inventory WHERE date = '2027-01-02'");

        $this->pay($token, $id, false);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('pending', $this->statusOf($id));
    }

    public function testCancelRollsBackWhenReleaseWouldGoNegative(): void
    {
        ['token' => $token, 'userId' => $userId] = $this->createAuthenticatedUser('negative@example.com');
        ['hotelId' => $hotelId, 'roomTypeId' => $roomTypeId] = $this->seedRoomTypeWithInventory();
        // Reservation row exists but inventory was never held, so releasing
        // would push total_reserved below zero (CHECK violation).
        $id = $this->seedReservation($userId, $hotelId, $roomTypeId, 'pending', holdInventory: false);

        $this->client->request('DELETE', '/v1/reservations/' . $id, server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('pending', $this->statusOf($id));
    }

    private function pay(string $token, string $reservationId, bool $approved): void
    {
        $this->client->request(
            'POST',
            '/v1/payments',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'],
            content: json_encode(['reservationID' => $reservationId, 'approved' => $approved], JSON_THROW_ON_ERROR),
        );
    }

    private function statusOf(string $reservationId): string
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return (string) $connection->fetchOne('SELECT status FROM reservation WHERE id = :id', ['id' => $reservationId]);
    }

    private function totalReserved(): int
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return (int) $connection->fetchOne('SELECT COALESCE(SUM(total_reserved), 0) FROM room_type_inventory');
    }

    /** @return array{hotelId: int, roomTypeId: int} */
    private function seedRoomTypeWithInventory(): array
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO hotel (name, address, city, country, star_rating, description, amenities, created_at, updated_at)
                VALUES ('Repo Hotel', 'Street 3', 'City', 'Country', 4, 'desc', '[]'::jsonb, now(), now())
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

        foreach (['2027-01-01', '2027-01-02'] as $date) {
            $connection->executeStatement(
                <<<'SQL'
                    INSERT INTO room_type_inventory (hotel_id, room_type_id, date, total_inventory, total_reserved, updated_at)
                    VALUES (:hotelId, :roomTypeId, :date, 10, 0, now())
                    SQL,
                ['hotelId' => $hotelId, 'roomTypeId' => $roomTypeId, 'date' => $date],
            );
        }

        return ['hotelId' => $hotelId, 'roomTypeId' => $roomTypeId];
    }

    /**
     * Inserts a 2-night reservation for 1 room; held statuses (everything but
     * rejected) also hold inventory unless $holdInventory is false.
     */
    private function seedReservation(
        int $userId,
        int $hotelId,
        int $roomTypeId,
        string $status,
        string $createdAt = '2026-01-01 00:00:00',
        bool $holdInventory = true,
    ): string {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $id = Uuid::v7()->toRfc4122();

        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO reservation
                    (id, user_id, hotel_id, room_type_id, start_date, end_date, room_count, status, total_price, created_at, updated_at)
                VALUES
                    (:id, :userId, :hotelId, :roomTypeId, '2027-01-01', '2027-01-03', 1, :status, 20000, :createdAt, :createdAt)
                SQL,
            ['id' => $id, 'userId' => $userId, 'hotelId' => $hotelId, 'roomTypeId' => $roomTypeId, 'status' => $status, 'createdAt' => $createdAt],
        );

        if ($holdInventory && 'rejected' !== $status) {
            $connection->executeStatement(
                'UPDATE room_type_inventory SET total_reserved = total_reserved + 1 WHERE hotel_id = :h AND room_type_id = :r',
                ['h' => $hotelId, 'r' => $roomTypeId],
            );
        }

        return $id;
    }

    /** @return array{token: string, userId: int} */
    private function createAuthenticatedUser(string $email): array
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $passwordHasher */
        $passwordHasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        /** @var JWTTokenManagerInterface $tokenManager */
        $tokenManager = self::getContainer()->get(JWTTokenManagerInterface::class);

        $hashingUser = new User($email, 'placeholder');
        $user = new User($email, $passwordHasher->hashPassword($hashingUser, 'secret-password'));

        $entityManager->persist($user);
        $entityManager->flush();

        return ['token' => $tokenManager->create($user), 'userId' => $user->getId()];
    }
}
