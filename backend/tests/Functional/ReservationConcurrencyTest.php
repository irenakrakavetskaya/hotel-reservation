<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The concurrency test described in docs/IMPLEMENTATION_PLAN.md §9 — "the
 * highest-value test in the whole system". Each simulated request runs in
 * its own OS process (see Support/concurrent_reservation_worker.php) with
 * its own PostgreSQL connection, started at (approximately) the same
 * wall-clock instant, so this exercises genuinely concurrent transactions
 * against the real `check_room_count` constraint rather than sequential
 * calls inside a single PHPUnit process.
 *
 * Uses `proc_open` (not `pcntl_fork`) so this runs in CI images that don't
 * ship the pcntl extension — see `.claude/skills/reservation-domain/SKILL.md`.
 */
final class ReservationConcurrencyTest extends WebTestCase
{
    private const WORKER_SCRIPT = __DIR__ . '/Support/concurrent_reservation_worker.php';

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        // getContainer() alone boots the kernel, and this test never needs
        // an HTTP client, so plain bootKernel()/getContainer() is fine here
        // (unlike ReservationControllerTest, which also calls createClient()).
        self::bootKernel();

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement('TRUNCATE TABLE room_type_rate, room_type_inventory, reservation, room_type, hotel, app_user RESTART IDENTITY CASCADE');
    }

    /**
     * total_inventory=10 => check_room_count ceiling (10*1.1)::int = 11.
     * Fire 15 concurrent booking attempts of 1 room each for the same date
     * and assert exactly 11 succeed, the rest are rejected by the DB
     * constraint, and the final total_reserved never exceeds the ceiling —
     * true no matter what the application logic does or doesn't check
     * beforehand.
     */
    public function testRealPostgresLastRoomConcurrencyNeverExceedsTheOverbookingCeiling(): void
    {
        ['hotelId' => $hotelId, 'roomTypeId' => $roomTypeId] = $this->seedInventory(totalInventory: 10, totalReserved: 0);

        $workerCount = 15;
        $expectedCeiling = 11;

        $reservationIds = [];
        for ($i = 0; $i < $workerCount; ++$i) {
            $reservationIds[] = Uuid::v7()->toRfc4122();
        }

        $results = $this->runConcurrently(
            $reservationIds,
            $hotelId,
            $roomTypeId,
            startDate: '2027-03-01',
            endDate: '2027-03-02',
            roomCount: 1,
        );

        self::assertSame($workerCount, $results['success'] + $results['conflict'] + $results['other']);
        self::assertSame(0, $results['other'], 'No worker should fail with an error other than the expected check_room_count conflict.');
        self::assertSame($expectedCeiling, $results['success'], 'Exactly the overbooking ceiling worth of requests should succeed.');
        self::assertSame($workerCount - $expectedCeiling, $results['conflict']);

        $finalTotalReserved = $this->totalReservedFor($hotelId, $roomTypeId, '2027-03-01');
        self::assertSame($expectedCeiling, $finalTotalReserved);
        self::assertLessThanOrEqual(intdiv(10 * 11, 10), $finalTotalReserved, 'The DB constraint must never be violated, even under concurrency.');

        self::assertSame($expectedCeiling, $this->reservationCount());
    }

    /**
     * The idempotency guarantee (CLAUDE.md invariant #1) must hold even
     * when retries of the *same* reservationID race each other, not just
     * when they arrive sequentially. Exactly one reservation row must be
     * created and inventory must be incremented exactly once.
     */
    public function testConcurrentRetriesWithTheSameReservationIdCreateExactlyOneReservation(): void
    {
        ['hotelId' => $hotelId, 'roomTypeId' => $roomTypeId] = $this->seedInventory(totalInventory: 10, totalReserved: 0);

        $workerCount = 10;
        $sharedReservationId = Uuid::v7()->toRfc4122();
        $reservationIds = array_fill(0, $workerCount, $sharedReservationId);

        $results = $this->runConcurrently(
            $reservationIds,
            $hotelId,
            $roomTypeId,
            startDate: '2027-04-01',
            endDate: '2027-04-02',
            roomCount: 1,
        );

        // ON CONFLICT (id) DO NOTHING means every worker's transaction
        // commits successfully — only the first to win the race actually
        // inserts a row and touches inventory.
        self::assertSame(0, $results['other']);
        self::assertSame($workerCount, $results['success']);

        self::assertSame(1, $this->reservationCount());
        self::assertSame(1, $this->totalReservedFor($hotelId, $roomTypeId, '2027-04-01'));
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function fractionalCeilingProvider(): iterable
    {
        // [totalInventory, alreadyReserved, expectedFinalReserved]
        yield 'inventory 5 (5.5 must floor to 5)' => [5, 4, 5];
        yield 'inventory 15 (16.5 must floor to 16)' => [15, 14, 16];
        yield 'inventory 1 (1.1 floors to 1)' => [1, 0, 1];
    }

    #[DataProvider('fractionalCeilingProvider')]
    public function testFractionalCeilingIsFlooredUnderConcurrency(int $totalInventory, int $alreadyReserved, int $expectedFinal): void
    {
        ['hotelId' => $hotelId, 'roomTypeId' => $roomTypeId] = $this->seedInventory(totalInventory: $totalInventory, totalReserved: $alreadyReserved);

        $workerCount = 6;
        $reservationIds = [];
        for ($i = 0; $i < $workerCount; ++$i) {
            $reservationIds[] = Uuid::v7()->toRfc4122();
        }

        $results = $this->runConcurrently($reservationIds, $hotelId, $roomTypeId, '2027-03-01', '2027-03-02', 1);

        $expectedSuccess = $expectedFinal - $alreadyReserved;
        self::assertSame(0, $results['other']);
        self::assertSame($expectedSuccess, $results['success']);
        self::assertSame($workerCount - $expectedSuccess, $results['conflict']);
        self::assertSame($expectedFinal, $this->totalReservedFor($hotelId, $roomTypeId, '2027-03-01'));
    }

    /**
     * The constraint is the guarantee: a direct write past the floored
     * ceiling must be rejected even when the app check is bypassed.
     */
    public function testConstraintRejectsRoundedUpCeiling(): void
    {
        ['hotelId' => $hotelId, 'roomTypeId' => $roomTypeId] = $this->seedInventory(totalInventory: 5, totalReserved: 5);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        $this->expectException(\Doctrine\DBAL\Exception\DriverException::class);
        $connection->executeStatement(
            'UPDATE room_type_inventory SET total_reserved = 6 WHERE hotel_id = :h AND room_type_id = :r',
            ['h' => $hotelId, 'r' => $roomTypeId],
        );
    }

    /**
     * @param list<string> $reservationIds one entry per worker; repeat the
     *                                     same id to test concurrent idempotency
     *
     * @return array{success: int, conflict: int, other: int}
     */
    private function runConcurrently(
        array $reservationIds,
        int $hotelId,
        int $roomTypeId,
        string $startDate,
        string $endDate,
        int $roomCount,
    ): array {
        $databaseUrl = (string) ($_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL'));
        self::assertNotSame('', $databaseUrl, 'DATABASE_URL must be set for the concurrency test to spawn worker processes.');

        // Give every worker the same start line, slightly in the future, so
        // they line up and genuinely overlap instead of racing to boot.
        $startAt = microtime(true) + 0.3;
        $env = [
            'DATABASE_URL' => $databaseUrl,
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        ];

        $processes = [];
        foreach ($reservationIds as $reservationId) {
            $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $command = [
                \PHP_BINARY,
                self::WORKER_SCRIPT,
                $databaseUrl,
                (string) $startAt,
                (string) $hotelId,
                (string) $roomTypeId,
                $startDate,
                $endDate,
                (string) $roomCount,
                $reservationId,
                '1',
            ];

            $handle = proc_open($command, $descriptors, $pipes, null, $env);
            self::assertIsResource($handle, 'Failed to spawn concurrency worker process.');

            $processes[] = ['handle' => $handle, 'pipes' => $pipes];
        }

        $results = ['success' => 0, 'conflict' => 0, 'other' => 0];
        foreach ($processes as $process) {
            $stdout = stream_get_contents($process['pipes'][1]);
            $stderr = stream_get_contents($process['pipes'][2]);
            fclose($process['pipes'][1]);
            fclose($process['pipes'][2]);

            $exitCode = proc_close($process['handle']);

            match ($exitCode) {
                0 => $results['success']++,
                1 => $results['conflict']++,
                default => $results['other']++,
            };

            if (!in_array($exitCode, [0, 1], true)) {
                fwrite(STDERR, sprintf("Worker exited %d\nstdout: %s\nstderr: %s\n", $exitCode, (string) $stdout, (string) $stderr));
            }
        }

        return $results;
    }

    /**
     * @return array{hotelId: int, roomTypeId: int}
     */
    private function seedInventory(int $totalInventory, int $totalReserved): array
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO hotel (name, address, city, country, star_rating, description, amenities, created_at, updated_at)
                VALUES ('Hotel Concurrency Test', 'Street 42', 'City', 'Country', 4, 'desc', '[]'::jsonb, now(), now())
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

        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO room_type_inventory (hotel_id, room_type_id, date, total_inventory, total_reserved, updated_at)
                VALUES
                    (:hotelId, :roomTypeId, '2027-03-01', :totalInventory, :totalReserved, now()),
                    (:hotelId, :roomTypeId, '2027-04-01', :totalInventory, :totalReserved, now())
                SQL,
            [
                'hotelId' => $hotelId,
                'roomTypeId' => $roomTypeId,
                'totalInventory' => $totalInventory,
                'totalReserved' => $totalReserved,
            ],
        );

        return ['hotelId' => $hotelId, 'roomTypeId' => $roomTypeId];
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
