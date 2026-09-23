<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\Rate\Service\PriceCalculator;
use PHPUnit\Framework\TestCase;

final class PriceCalculatorTest extends TestCase
{
    public function testItAppliesTheHighestMatchingOccupancyTier(): void
    {
        $calculator = new PriceCalculator();

        self::assertSame(8_500, $calculator->calculate(10_000, 0.0));
        self::assertSame(10_000, $calculator->calculate(10_000, 0.5));
        self::assertSame(11_500, $calculator->calculate(10_000, 0.75));
        self::assertSame(13_500, $calculator->calculate(10_000, 0.9));
        self::assertSame(15_000, $calculator->calculate(10_000, 1.3));
    }
}
