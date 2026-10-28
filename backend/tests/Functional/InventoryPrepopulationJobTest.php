<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\Reservation\Service\InventoryPrepopulationJob;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InventoryPrepopulationJobTest extends KernelTestCase
{
    private int $hotelId;
    private int $standardId;
    private int $emptyTypeId;
    private \DateTimeImmutable $today;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->today = new \DateTimeImmutable('today');

        $db = $this->connection();
        $db->executeStatement('TRUNCATE TABLE room_type_inventory, reservation, room, room_type, hotel, app_user RESTART IDENTITY CASCADE');

        $db->executeStatement("INSERT INTO hotel (name, address, city, country, star_rating, description, amenities, created_at, updated_at) VALUES ('H', 'a', 'c', 'x', 3, 'd', '[]'::jsonb, now(), now())");
        $this->hotelId = (int) $db->lastInsertId();
        $this->standardId = $this->insertRoomType('Standard');
        $this->emptyTypeId = $this->insertRoomType('Empty');

        $this->insertRoom($this->standardId, '101', 'active');
        $this->insertRoom($this->standardId, '102', 'active');
        $this->insertRoom($this->standardId, '103', 'maintenance');
        $this->insertRoom($this->standardId, '104', 'inactive');
    }

    public function testBackfillCoversTheWholeWindowWithActiveRoomCapacity(): void
    {
        $this->job()->backfillWindow($this->today);

        foreach ([$this->standardId, $this->emptyTypeId] as $roomTypeId) {
            self::assertSame(InventoryPrepopulationJob::WINDOW_DAYS + 1, $this->rowCount($roomTypeId));
        }
        self::assertSame([2], $this->capacities($this->standardId));
        self::assertSame([0], $this->capacities($this->emptyTypeId));
        self::assertSame($this->today->format('Y-m-d'), $this->dateBound($this->standardId, 'MIN'));
        self::assertSame(
            $this->today->modify('+' . InventoryPrepopulationJob::WINDOW_DAYS . ' days')->format('Y-m-d'),
            $this->dateBound($this->standardId, 'MAX'),
        );
    }

    public function testBackfillIsIdempotentAndNeverTouchesReservedCounts(): void
    {
        $this->job()->backfillWindow($this->today);
        $date = $this->today->modify('+10 days')->format('Y-m-d');
        $this->connection()->executeStatement(
            'UPDATE room_type_inventory SET total_reserved = 2 WHERE room_type_id = :r AND date = :d',
            ['r' => $this->standardId, 'd' => $date],
        );
        // A room becomes active, so capacity should follow on the next run.
        $this->connection()->executeStatement("UPDATE room SET status = 'active' WHERE room_number = '103'");

        $this->job()->backfillWindow($this->today);

        self::assertSame(InventoryPrepopulationJob::WINDOW_DAYS + 1, $this->rowCount($this->standardId));
        self::assertSame([3], $this->capacities($this->standardId));
        self::assertSame(2, (int) $this->connection()->fetchOne(
            'SELECT total_reserved FROM room_type_inventory WHERE room_type_id = :r AND date = :d',
            ['r' => $this->standardId, 'd' => $date],
        ));
        self::assertSame(2, (int) $this->connection()->fetchOne('SELECT SUM(total_reserved) FROM room_type_inventory WHERE room_type_id = :r', ['r' => $this->standardId]));
    }

    public function testDailyExtensionAddsOnlyTheHorizonDateAndIsIdempotent(): void
    {
        $horizon = $this->today->modify('+' . InventoryPrepopulationJob::WINDOW_DAYS . ' days');

        $this->job()->extendWindowByOneDay($this->today);
        $this->job()->extendWindowByOneDay($this->today);

        self::assertSame(1, $this->rowCount($this->standardId));
        self::assertSame($horizon->format('Y-m-d'), $this->dateBound($this->standardId, 'MIN'));
        self::assertSame([2], $this->capacities($this->standardId));
        self::assertSame([0], $this->capacities($this->emptyTypeId));
    }

    public function testDailyExtensionDoesNotBackfillAGap(): void
    {
        // Documents the known limit behind the room-CRUD reconciler: the
        // daily job only ever writes the horizon date.
        $this->job()->extendWindowByOneDay($this->today);
        self::assertSame(1, $this->rowCount($this->standardId));

        $this->job()->extendWindowByOneDay($this->today->modify('+1 day'));
        self::assertSame(2, $this->rowCount($this->standardId));
    }

    private function job(): InventoryPrepopulationJob
    {
        /** @var InventoryPrepopulationJob $job */
        $job = self::getContainer()->get(InventoryPrepopulationJob::class);

        return $job;
    }

    private function insertRoomType(string $name): int
    {
        $this->connection()->executeStatement(
            "INSERT INTO room_type (hotel_id, name, description, max_occupancy, base_price, amenities, created_at, updated_at) VALUES (:h, :n, 'd', 2, 10000, '[]'::jsonb, now(), now())",
            ['h' => $this->hotelId, 'n' => $name],
        );

        return (int) $this->connection()->lastInsertId();
    }

    private function insertRoom(int $roomTypeId, string $number, string $status): void
    {
        $this->connection()->executeStatement(
            'INSERT INTO room (hotel_id, room_type_id, room_number, status, created_at, updated_at) VALUES (:h, :t, :n, :s, now(), now())',
            ['h' => $this->hotelId, 't' => $roomTypeId, 'n' => $number, 's' => $status],
        );
    }

    private function rowCount(int $roomTypeId): int
    {
        return (int) $this->connection()->fetchOne('SELECT count(*) FROM room_type_inventory WHERE room_type_id = :r', ['r' => $roomTypeId]);
    }

    /** @return list<int> */
    private function capacities(int $roomTypeId): array
    {
        return array_map('intval', $this->connection()->fetchFirstColumn('SELECT DISTINCT total_inventory FROM room_type_inventory WHERE room_type_id = :r', ['r' => $roomTypeId]));
    }

    private function dateBound(int $roomTypeId, string $fn): string
    {
        return (string) $this->connection()->fetchOne(sprintf('SELECT %s(date)::text FROM room_type_inventory WHERE room_type_id = :r', $fn), ['r' => $roomTypeId]);
    }

    private function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return $connection;
    }
}
