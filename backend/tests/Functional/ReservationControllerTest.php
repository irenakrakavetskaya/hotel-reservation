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

/**
 * Functional coverage for POST/DELETE /v1/reservations per
 * `.claude/skills/api-scaffold/SKILL.md` and
 * `.claude/skills/reservation-domain/SKILL.md`. Real concurrency and
 * concurrent-idempotency tests live in ReservationConcurrencyTest — this
 * file covers the single-request HTTP contract: happy path, ownership,
 * validation, half-open dates, partial inventory, and 409 conflicts.
 */
final class ReservationControllerTest extends WebTestCase
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
        $connection->executeStatement('TRUNCATE TABLE room_type_rate, room_type_inventory, reservation, room_type, hotel, app_user RESTART IDENTITY CASCADE');
    }

    public function testItCreatesAReservationAndHoldsInventory(): void
    {
        $client = $this->client;
        ['token' => $token] = $this->createAuthenticatedUser('guest@example.com');
        ['hotelId' => $hotelId, 'roomTypeId' => $roomTypeId] = $this->seedBookableRoomType();
        $reservationId = Uuid::v7()->toRfc4122();

        $client->request(
            'POST',
            '/v1/reservations',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            content: json_encode([
                'reservationID' => $reservationId,
                'hotelID' => $hotelId,
                'roomTypeID' => $roomTypeId,
                'startDate' => '2027-01-01',
                'endDate' => '2027-01-03',
                'roomCount' => 1,
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(201);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($reservationId, $payload['reservationId']);
        self::assertSame('pending', $payload['status']);
        self::assertSame(1, $payload['roomCount']);

        self::assertSame(1, $this->totalReservedFor($hotelId, $roomTypeId, '2027-01-01'));
        self::assertSame(1, $this->totalReservedFor($hotelId, $roomTypeId, '2027-01-02'));
    }

    public function testHalfOpenDateRangeDoesNotHoldTheCheckoutNight(): void
    {
        $client = $this->client;
        ['token' => $token] = $this->createAuthenticatedUser('guest-checkout@example.com');
        ['hotelId' => $hotelId, 'roomTypeId' => $roomTypeId] = $this->seedBookableRoomType();

        $client->request(
            'POST',
            '/v1/reservations',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            content: json_encode([
                'reservationID' => Uuid::v7()->toRfc4122(),
                'hotelID' => $hotelId,
                'roomTypeID' => $roomTypeId,
                'startDate' => '2027-01-01',
                'endDate' => '2027-01-03',
                'roomCount' => 1,
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(201);
        // The checkout date (2027-01-03) is not a booked night — its
        // inventory row must be untouched.
        self::assertSame(0, $this->totalReservedFor($hotelId, $roomTypeId, '2027-01-03'));
    }

    public function testUserCanCancelTheirOwnReservationAndInventoryIsReleased(): void
    {
        $client = $this->client;
        ['token' => $token, 'userId' => $userId] = $this->createAuthenticatedUser('owner@example.com');
        ['hotelId' => $hotelId, 'roomTypeId' => $roomTypeId] = $this->seedBookableRoomType();
        $reservationId = $this->seedReservation($userId, $hotelId, $roomTypeId, '2027-01-01', '2027-01-03', 1);

        $client->request(
            'DELETE',
            sprintf('/v1/reservations/%s', $reservationId),
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );

        self::assertResponseStatusCodeSame(200);
        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('canceled', $payload['status']);
        self::assertSame(0, $this->totalReservedFor($hotelId, $roomTypeId, '2027-01-01'));
        self::assertSame(0, $this->totalReservedFor($hotelId, $roomTypeId, '2027-01-02'));
    }

    public function testCancelingAlreadyCanceledReservationIsIdempotentAndDoesNotDoubleReleaseInventory(): void
    {
        $client = $this->client;
        ['token' => $token, 'userId' => $userId] = $this->createAuthenticatedUser('owner2@example.com');
        ['hotelId' => $hotelId, 'roomTypeId' => $roomTypeId] = $this->seedBookableRoomType();
        $reservationId = $this->seedReservation($userId, $hotelId, $roomTypeId, '2027-01-01', '2027-01-03', 1);

        $client->request('DELETE', sprintf('/v1/reservations/%s', $reservationId), server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(200);

        $client->request('DELETE', sprintf('/v1/reservations/%s', $reservationId), server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(200);

        // Only one release should ever have happened, so we can't be below 0.
        self::assertSame(0, $this->totalReservedFor($hotelId, $roomTypeId, '2027-01-01'));
    }

    public function testAnotherUserCannotCancelSomeoneElsesReservation(): void
    {
        $client = $this->client;
        ['userId' => $ownerId] = $this->createAuthenticatedUser('owner3@example.com');
        ['token' => $otherToken] = $this->createAuthenticatedUser('intruder@example.com');
        ['hotelId' => $hotelId, 'roomTypeId' => $roomTypeId] = $this->seedBookableRoomType();
        $reservationId = $this->seedReservation($ownerId, $hotelId, $roomTypeId, '2027-01-01', '2027-01-03', 1);

        $client->request(
            'DELETE',
            sprintf('/v1/reservations/%s', $reservationId),
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $otherToken],
        );

        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, $this->totalReservedFor($hotelId, $roomTypeId, '2027-01-01'));
    }

    public function testAnotherUserCannotViewSomeoneElsesReservation(): void
    {
        $client = $this->client;
        ['userId' => $ownerId] = $this->createAuthenticatedUser('owner4@example.com');
        ['token' => $otherToken] = $this->createAuthenticatedUser('intruder2@example.com');
        ['hotelId' => $hotelId, 'roomTypeId' => $roomTypeId] = $this->seedBookableRoomType();
        $reservationId = $this->seedReservation($ownerId, $hotelId, $roomTypeId, '2027-01-01', '2027-01-03', 1);

        $client->request(
            'GET',
            sprintf('/v1/reservations/%s', $reservationId),
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $otherToken],
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testStaffCanCancelAnyUsersReservation(): void
    {
        $client = $this->client;
        ['userId' => $ownerId] = $this->createAuthenticatedUser('owner5@example.com');
        ['token' => $staffToken] = $this->createAuthenticatedUser('staffer@example.com', ['ROLE_STAFF']);
        ['hotelId' => $hotelId, 'roomTypeId' => $roomTypeId] = $this->seedBookableRoomType();
        $reservationId = $this->seedReservation($ownerId, $hotelId, $roomTypeId, '2027-01-01', '2027-01-03', 1);

        $client->request(
            'DELETE',
            sprintf('/v1/reservations/%s', $reservationId),
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $staffToken],
        );

        self::assertResponseStatusCodeSame(200);
    }

    public function testMalformedReservationIdOnGetReturns422(): void
    {
        $client = $this->client;
        ['token' => $token] = $this->createAuthenticatedUser('malformed-get@example.com');

        $client->request(
            'GET',
            '/v1/reservations/not-a-uuid',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testMalformedReservationIdOnCancelReturns422(): void
    {
        $client = $this->client;
        ['token' => $token] = $this->createAuthenticatedUser('malformed-cancel@example.com');

        $client->request(
            'DELETE',
            '/v1/reservations/not-a-uuid',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testMalformedReservationIdOnCreateReturns422(): void
    {
        $client = $this->client;
        ['token' => $token] = $this->createAuthenticatedUser('malformed-create@example.com');
        ['hotelId' => $hotelId, 'roomTypeId' => $roomTypeId] = $this->seedBookableRoomType();

        $client->request(
            'POST',
            '/v1/reservations',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            content: json_encode([
                'reservationID' => 'not-a-uuid',
                'hotelID' => $hotelId,
                'roomTypeID' => $roomTypeId,
                'startDate' => '2027-01-01',
                'endDate' => '2027-01-03',
                'roomCount' => 1,
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testGettingAMissingReservationReturns404(): void
    {
        $client = $this->client;
        ['token' => $token] = $this->createAuthenticatedUser('missing@example.com');

        $client->request(
            'GET',
            sprintf('/v1/reservations/%s', Uuid::v7()->toRfc4122()),
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );

        self::assertResponseStatusCodeSame(404);
    }

    public function testPartialInventoryWindowReturns409AndRollsBackTheWholeReservation(): void
    {
        $client = $this->client;
        ['token' => $token] = $this->createAuthenticatedUser('partial@example.com');
        // Only seed inventory for the first night of a two-night stay — the
        // pre-population job hasn't reached the second date yet.
        ['hotelId' => $hotelId, 'roomTypeId' => $roomTypeId] = $this->seedBookableRoomType(
            dates: ['2027-02-01'],
        );

        $client->request(
            'POST',
            '/v1/reservations',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            content: json_encode([
                'reservationID' => Uuid::v7()->toRfc4122(),
                'hotelID' => $hotelId,
                'roomTypeID' => $roomTypeId,
                'startDate' => '2027-02-01',
                'endDate' => '2027-02-03',
                'roomCount' => 1,
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(409);
        self::assertSame(0, $this->totalReservedFor($hotelId, $roomTypeId, '2027-02-01'));
        self::assertSame(0, $this->reservationCount());
    }

    public function testOverbookingBeyondTheCeilingReturns409(): void
    {
        $client = $this->client;
        ['token' => $token] = $this->createAuthenticatedUser('overbook@example.com');
        // total_inventory=10 => ceiling (10*1.1)::int = 11, already fully booked.
        ['hotelId' => $hotelId, 'roomTypeId' => $roomTypeId] = $this->seedBookableRoomType(
            totalInventory: 10,
            totalReserved: 11,
        );

        $client->request(
            'POST',
            '/v1/reservations',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            content: json_encode([
                'reservationID' => Uuid::v7()->toRfc4122(),
                'hotelID' => $hotelId,
                'roomTypeID' => $roomTypeId,
                'startDate' => '2027-01-01',
                'endDate' => '2027-01-02',
                'roomCount' => 1,
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(409);
        self::assertSame(11, $this->totalReservedFor($hotelId, $roomTypeId, '2027-01-01'));
    }

    public function testBookingExactlyUpToTheOverbookingCeilingSucceeds(): void
    {
        $client = $this->client;
        ['token' => $token] = $this->createAuthenticatedUser('exact-boundary@example.com');
        // total_inventory=10 => ceiling 11; 10 already reserved, one more
        // fits exactly at the boundary.
        ['hotelId' => $hotelId, 'roomTypeId' => $roomTypeId] = $this->seedBookableRoomType(
            totalInventory: 10,
            totalReserved: 10,
        );

        $client->request(
            'POST',
            '/v1/reservations',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
            content: json_encode([
                'reservationID' => Uuid::v7()->toRfc4122(),
                'hotelID' => $hotelId,
                'roomTypeID' => $roomTypeId,
                'startDate' => '2027-01-01',
                'endDate' => '2027-01-02',
                'roomCount' => 1,
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(201);
        self::assertSame(11, $this->totalReservedFor($hotelId, $roomTypeId, '2027-01-01'));
    }

    public function testSequentialIdempotentRetriesReturnTheSameReservationAndIncrementInventoryOnce(): void
    {
        $client = $this->client;
        ['token' => $token] = $this->createAuthenticatedUser('retry@example.com');
        ['hotelId' => $hotelId, 'roomTypeId' => $roomTypeId] = $this->seedBookableRoomType();
        $reservationId = Uuid::v7()->toRfc4122();
        $body = json_encode([
            'reservationID' => $reservationId,
            'hotelID' => $hotelId,
            'roomTypeID' => $roomTypeId,
            'startDate' => '2027-01-01',
            'endDate' => '2027-01-02',
            'roomCount' => 1,
        ], JSON_THROW_ON_ERROR);

        $client->request('POST', '/v1/reservations', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token], content: $body);
        self::assertResponseStatusCodeSame(201);
        $first = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        // Simulate a client retry (e.g. after a network timeout) reusing
        // the same reservationID, per `.claude/rules/frontend-nextjs.md`.
        $client->request('POST', '/v1/reservations', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token], content: $body);
        self::assertResponseStatusCodeSame(201);
        $second = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame($first, $second);
        self::assertSame(1, $this->reservationCount());
        self::assertSame(1, $this->totalReservedFor($hotelId, $roomTypeId, '2027-01-01'));
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
     * @param list<string> $dates defaults to a two-night window covering
     *                            2027-01-01 and 2027-01-02
     *
     * @return array{hotelId: int, roomTypeId: int}
     */
    private function seedBookableRoomType(
        array $dates = ['2027-01-01', '2027-01-02'],
        int $totalInventory = 10,
        int $totalReserved = 0,
    ): array {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO hotel (name, address, city, country, star_rating, description, amenities, created_at, updated_at)
                VALUES ('Hotel Reservation Test', 'Street 9', 'City', 'Country', 4, 'desc', '[]'::jsonb, now(), now())
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

        foreach ($dates as $date) {
            $connection->executeStatement(
                <<<'SQL'
                    INSERT INTO room_type_inventory (hotel_id, room_type_id, date, total_inventory, total_reserved, updated_at)
                    VALUES (:hotelId, :roomTypeId, :date, :totalInventory, :totalReserved, now())
                    SQL,
                [
                    'hotelId' => $hotelId,
                    'roomTypeId' => $roomTypeId,
                    'date' => $date,
                    'totalInventory' => $totalInventory,
                    'totalReserved' => $totalReserved,
                ],
            );
            $connection->executeStatement(
                <<<'SQL'
                    INSERT INTO room_type_rate (hotel_id, room_type_id, date, price, updated_at)
                    VALUES (:hotelId, :roomTypeId, :date, 10000, now())
                    SQL,
                ['hotelId' => $hotelId, 'roomTypeId' => $roomTypeId, 'date' => $date],
            );
        }

        return ['hotelId' => $hotelId, 'roomTypeId' => $roomTypeId];
    }

    private function seedReservation(
        int $userId,
        int $hotelId,
        int $roomTypeId,
        string $startDate,
        string $endDate,
        int $roomCount,
    ): string {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $reservationId = Uuid::v7()->toRfc4122();

        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO reservation
                    (id, user_id, hotel_id, room_type_id, start_date, end_date, room_count, status, total_price, created_at, updated_at)
                VALUES
                    (:id, :userId, :hotelId, :roomTypeId, :startDate, :endDate, :roomCount, 'pending', 10000, CURRENT_TIMESTAMP(0), CURRENT_TIMESTAMP(0))
                SQL,
            [
                'id' => $reservationId,
                'userId' => $userId,
                'hotelId' => $hotelId,
                'roomTypeId' => $roomTypeId,
                'startDate' => $startDate,
                'endDate' => $endDate,
                'roomCount' => $roomCount,
            ],
        );

        $connection->executeStatement(
            <<<'SQL'
                UPDATE room_type_inventory
                SET total_reserved = total_reserved + :roomCount
                WHERE hotel_id = :hotelId AND room_type_id = :roomTypeId AND date >= :startDate AND date < :endDate
                SQL,
            [
                'roomCount' => $roomCount,
                'hotelId' => $hotelId,
                'roomTypeId' => $roomTypeId,
                'startDate' => $startDate,
                'endDate' => $endDate,
            ],
        );

        return $reservationId;
    }

    private function totalReservedFor(int $hotelId, int $roomTypeId, string $date): int
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        $value = $connection->fetchOne(
            'SELECT total_reserved FROM room_type_inventory WHERE hotel_id = :hotelId AND room_type_id = :roomTypeId AND date = :date',
            ['hotelId' => $hotelId, 'roomTypeId' => $roomTypeId, 'date' => $date],
        );

        return false === $value ? 0 : (int) $value;
    }

    private function reservationCount(): int
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return (int) $connection->fetchOne('SELECT COUNT(*) FROM reservation');
    }
}
