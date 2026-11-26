<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\Hotel\Repository\RoomRepository;
use App\Domain\Reservation\Repository\RoomTypeInventoryRepository;
use App\Domain\Reservation\Service\InventoryReconciler;
use App\Infrastructure\Cache\ArrayCacheVersionCounter;
use App\Infrastructure\Cache\CacheScopes;
use App\Infrastructure\Cache\ReadCacheVersionService;
use PHPUnit\Framework\TestCase;

final class InventoryReconcilerTest extends TestCase
{
    public function testReconciliationDoesNotInvalidateUntilExplicitlyRequested(): void
    {
        $rooms = $this->createMock(RoomRepository::class);
        $rooms->expects(self::once())->method('countActiveByHotelAndRoomType')->with(10, 20)->willReturn(3);
        $inventory = $this->createMock(RoomTypeInventoryRepository::class);
        $inventory->expects(self::once())->method('upsertInventoryWindow');
        $versions = new ReadCacheVersionService(new ArrayCacheVersionCounter());
        $scope = CacheScopes::availabilityRead(10, 20);
        $before = $versions->getVersion($scope);
        $reconciler = new InventoryReconciler($rooms, $inventory, $versions);

        $reconciler->reconcileRoomType(10, 20, new \DateTimeImmutable('2026-01-01'));
        self::assertSame($before, $versions->getVersion($scope));
        $reconciler->invalidateAvailability(10, 20);
        self::assertGreaterThan($before, $versions->getVersion($scope));
    }

    public function testFailedReconciliationDoesNotInvalidate(): void
    {
        $rooms = $this->createStub(RoomRepository::class);
        $rooms->method('countActiveByHotelAndRoomType')->willReturn(3);
        $inventory = $this->createStub(RoomTypeInventoryRepository::class);
        $inventory->method('upsertInventoryWindow')->willThrowException(new \RuntimeException('write failed'));
        $versions = new ReadCacheVersionService(new ArrayCacheVersionCounter());
        $scope = CacheScopes::availabilityRead(10, 20);
        $before = $versions->getVersion($scope);
        $reconciler = new InventoryReconciler($rooms, $inventory, $versions);

        try {
            $reconciler->reconcileRoomType(10, 20);
            self::fail('The inventory write failure should propagate.');
        } catch (\RuntimeException $exception) {
            self::assertSame('write failed', $exception->getMessage());
        }

        self::assertSame($before, $versions->getVersion($scope));
    }
}
