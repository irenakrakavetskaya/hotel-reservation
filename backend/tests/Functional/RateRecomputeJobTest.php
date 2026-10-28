<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\Rate\Service\PriceCalculator;
use App\Domain\Rate\Service\RateRecomputeJob;
use App\Domain\Reservation\Service\InventoryPrepopulationJob;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RateRecomputeJobTest extends KernelTestCase
{
    private const BASE_PRICE = 10000;

    private int $hotelId;
    private int $roomTypeId;
    private \DateTimeImmutable $today;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->today = new \DateTimeImmutable('today');

        $db = $this->connection();
        $db->executeStatement('TRUNCATE TABLE room_type_rate, room_type_inventory, reservation, room, room_type, hotel, app_user RESTART IDENTITY CASCADE');
        $db->executeStatement("INSERT INTO hotel (name, address, city, country, star_rating, description, amenities, created_at, updated_at) VALUES ('H', 'a', 'c', 'x', 3, 'd', '[]'::jsonb, now(), now())");
        $this->hotelId = (int) $db->lastInsertId();
        $db->executeStatement(
            "INSERT INTO room_type (hotel_id, name, description, max_occupancy, base_price, amenities, created_at, updated_at) VALUES (:h, 'Standard', 'd', 2, :p, '[]'::jsonb, now(), now())",
            ['h' => $this->hotelId, 'p' => self::BASE_PRICE],
        );
        $this->roomTypeId = (int) $db->lastInsertId();
    }

    public function testSkipsRoomTypesWithoutACompleteInventoryWindow(): void
    {
        $this->seedInventory(10, days: 100);

        $this->job()->run($this->today);

        self::assertSame(0, $this->rateCount());
    }

    public function testWritesOneRatePerDayAndPricesFollowOccupancy(): void
    {
        $this->seedInventory(10);
        $calculator = new PriceCalculator();
        $low = $this->today->modify('+1 day');
        $full = $this->today->modify('+2 days');
        $this->setReserved($low, 0);
        $this->setReserved($full, 10);

        $this->job()->run($this->today);

        self::assertSame(InventoryPrepopulationJob::WINDOW_DAYS + 1, $this->rateCount());
        self::assertSame($calculator->calculate(self::BASE_PRICE, 0.0), $this->rateFor($low));
        self::assertSame($calculator->calculate(self::BASE_PRICE, 1.0), $this->rateFor($full));
        self::assertGreaterThan($this->rateFor($low), $this->rateFor($full));
    }

    public function testRerunReflectsNewOccupancyWithoutDuplicatingRates(): void
    {
        $this->seedInventory(10);
        $day = $this->today->modify('+5 days');

        $this->job()->run($this->today);
        $before = $this->rateFor($day);

        $this->setReserved($day, 10);
        $this->job()->run($this->today);

        self::assertSame(InventoryPrepopulationJob::WINDOW_DAYS + 1, $this->rateCount());
        self::assertGreaterThan($before, $this->rateFor($day));
        self::assertSame((new PriceCalculator())->calculate(self::BASE_PRICE, 1.0), $this->rateFor($day));
    }

    public function testZeroCapacityDaysArePricedAsEmpty(): void
    {
        $this->seedInventory(0);

        $this->job()->run($this->today);

        self::assertSame((new PriceCalculator())->calculate(self::BASE_PRICE, 0.0), $this->rateFor($this->today));
    }

    private function seedInventory(int $capacity, int $days = InventoryPrepopulationJob::WINDOW_DAYS): void
    {
        $this->connection()->executeStatement(
            <<<'SQL'
                INSERT INTO room_type_inventory (hotel_id, room_type_id, date, total_inventory, total_reserved, updated_at)
                SELECT :h, :r, d::date, :c, 0, now()
                FROM generate_series(CAST(:s AS date), CAST(:s AS date) + CAST(:n AS integer), interval '1 day') AS d
                SQL,
            ['h' => $this->hotelId, 'r' => $this->roomTypeId, 'c' => $capacity, 's' => $this->today->format('Y-m-d'), 'n' => $days],
        );
    }

    private function setReserved(\DateTimeImmutable $date, int $reserved): void
    {
        $this->connection()->executeStatement(
            'UPDATE room_type_inventory SET total_reserved = :v WHERE room_type_id = :r AND date = :d',
            ['v' => $reserved, 'r' => $this->roomTypeId, 'd' => $date->format('Y-m-d')],
        );
    }

    private function rateCount(): int
    {
        return (int) $this->connection()->fetchOne('SELECT count(*) FROM room_type_rate WHERE room_type_id = :r', ['r' => $this->roomTypeId]);
    }

    private function rateFor(\DateTimeImmutable $date): int
    {
        return (int) $this->connection()->fetchOne(
            'SELECT price FROM room_type_rate WHERE room_type_id = :r AND date = :d',
            ['r' => $this->roomTypeId, 'd' => $date->format('Y-m-d')],
        );
    }

    private function job(): RateRecomputeJob
    {
        /** @var RateRecomputeJob $job */
        $job = self::getContainer()->get(RateRecomputeJob::class);

        return $job;
    }

    private function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return $connection;
    }
}
