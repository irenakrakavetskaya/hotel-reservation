<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\Reservation\Dto\AvailabilityResponse;
use App\Domain\Reservation\Entity\RoomTypeInventory;
use App\Domain\User\Exception\RoleChangeNotAllowedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReservationValueObjectsTest extends TestCase
{
    /** @return iterable<string, array{int, int}> */
    public static function ceilings(): iterable
    {
        yield 'zero' => [0, 0];
        yield 'one rounds down' => [1, 1];
        yield 'nine rounds down' => [9, 9];
        yield 'ten gets one extra' => [10, 11];
        yield 'nineteen' => [19, 20];
        yield 'hundred' => [100, 110];
    }

    #[DataProvider('ceilings')]
    public function testOverbookingCeilingIsFloorOf110Percent(int $inventory, int $expected): void
    {
        self::assertSame($expected, RoomTypeInventory::overbookingCeiling($inventory));
    }

    public function testInventoryEntityExposesItsStateAndAvailability(): void
    {
        $date = new \DateTimeImmutable('2027-01-01');
        $inventory = new RoomTypeInventory(3, 4, $date, 10);

        self::assertSame(3, $inventory->getHotelId());
        self::assertSame(4, $inventory->getRoomTypeId());
        self::assertSame($date, $inventory->getDate());
        self::assertSame(10, $inventory->getTotalInventory());
        self::assertSame(0, $inventory->getTotalReserved());
        self::assertInstanceOf(\DateTimeImmutable::class, $inventory->getUpdatedAt());
        self::assertSame(11, $inventory->getAvailableCount());
    }

    public function testAvailabilityResponseRoundTripsThroughArray(): void
    {
        $response = new AvailabilityResponse(
            hotelId: 1,
            roomTypeId: 2,
            startDate: '2027-01-01',
            endDate: '2027-01-03',
            roomCount: 1,
            nights: 2,
            canBook: true,
            totalPrice: 20000,
            minAvailableRooms: 5,
            days: [
                ['date' => '2027-01-01', 'totalInventory' => 10, 'totalReserved' => 5, 'availableRooms' => 6, 'price' => 10000],
                ['date' => '2027-01-02', 'totalInventory' => 10, 'totalReserved' => 5, 'availableRooms' => 6, 'price' => null],
            ],
        );

        $array = $response->toArray();

        self::assertSame(1, $array['hotelID']);
        self::assertSame(2, $array['roomTypeID']);
        self::assertEquals($response, AvailabilityResponse::fromArray($array));
    }

    public function testRoleChangeExceptionsCarryDistinctMessages(): void
    {
        self::assertStringContainsString('own admin', RoleChangeNotAllowedException::cannotRemoveOwnAdmin()->getMessage());
        self::assertStringContainsString('last remaining admin', RoleChangeNotAllowedException::lastAdmin()->getMessage());
    }
}
