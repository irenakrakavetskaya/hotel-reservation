<?php

declare(strict_types=1);

// Standalone worker process spawned (via proc_open, one OS process per
// simulated concurrent request) by ReservationConcurrencyTest. Deliberately
// framework-free: it exercises the exact SQL from
// ReservationRepository::create() directly against real PostgreSQL so the
// test proves the `check_room_count` constraint itself is race-safe, not
// just that the Doctrine code path happens to behave under test doubles.
//
// argv: [databaseUrl, startAtMicrotime, hotelId, roomTypeId, startDate, endDate, roomCount, reservationId, userId]
// Exit codes: 0 = transaction committed, 1 = check_room_count violation
// (expected "no more rooms" conflict), 2 = unexpected error.

[, $databaseUrl, $startAt, $hotelId, $roomTypeId, $startDate, $endDate, $roomCount, $reservationId, $userId] = $argv;

$parts = parse_url($databaseUrl);
$dbName = ltrim((string) ($parts['path'] ?? ''), '/');
$query = [];
parse_str($parts['query'] ?? '', $query);

$dsn = sprintf(
    'pgsql:host=%s;port=%d;dbname=%s',
    $parts['host'] ?? '127.0.0.1',
    (int) ($parts['port'] ?? 5432),
    $dbName,
);

$pdo = new \PDO($dsn, $parts['user'] ?? null, $parts['pass'] ?? null, [
    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
]);

// Synchronize all workers to attempt their transaction at (about) the same
// wall-clock instant, so their transactions genuinely overlap at the
// database instead of running one after another.
while (microtime(true) < (float) $startAt) {
    usleep(500);
}

try {
    $pdo->beginTransaction();

    $insert = $pdo->prepare(<<<'SQL'
        INSERT INTO reservation
            (id, user_id, hotel_id, room_type_id, start_date, end_date, room_count, status, total_price, created_at, updated_at)
        VALUES
            (:id, :userId, :hotelId, :roomTypeId, :startDate, :endDate, :roomCount, 'pending', 1000, now(), now())
        ON CONFLICT (id) DO NOTHING
        SQL);
    $insert->execute([
        'id' => $reservationId,
        'userId' => (int) $userId,
        'hotelId' => (int) $hotelId,
        'roomTypeId' => (int) $roomTypeId,
        'startDate' => $startDate,
        'endDate' => $endDate,
        'roomCount' => (int) $roomCount,
    ]);

    if (1 === $insert->rowCount()) {
        // Mirrors ReservationRepository::create(): the inventory UPDATE only
        // runs for a genuinely new insert, so a retry of an
        // already-processed reservationID can never double-increment.
        $update = $pdo->prepare(<<<'SQL'
            UPDATE room_type_inventory
            SET total_reserved = total_reserved + :roomCount, updated_at = now()
            WHERE hotel_id = :hotelId
              AND room_type_id = :roomTypeId
              AND date >= :startDate
              AND date < :endDate
            SQL);
        $update->execute([
            'roomCount' => (int) $roomCount,
            'hotelId' => (int) $hotelId,
            'roomTypeId' => (int) $roomTypeId,
            'startDate' => $startDate,
            'endDate' => $endDate,
        ]);
    }

    $pdo->commit();
    exit(0);
} catch (\PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $sqlState = $e->errorInfo[0] ?? null;
    exit('23514' === $sqlState ? 1 : 2);
}
