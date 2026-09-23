<?php

declare(strict_types=1);

namespace App\Domain\Rate\Service;

/**
 * Occupancy -> price multiplier. Deliberately simple tiered curve per
 * docs/IMPLEMENTATION_PLAN.md §4 ("start simple, tune later") — swap the
 * TIERS table for something more sophisticated once there's real occupancy
 * data to tune against, without touching RateRecomputeJob.
 */
final class PriceCalculator
{
    /**
     * [occupancy threshold (inclusive lower bound), multiplier], ascending.
     *
     * @var list<array{0: float, 1: float}>
     */
    private const TIERS = [
        [0.0, 0.85],
        [0.5, 1.0],
        [0.75, 1.15],
        [0.9, 1.35],
        [1.0, 1.5],
    ];

    /**
     * @param int   $basePriceCents room_type.base_price, in cents
     * @param float $occupancyRate  total_reserved / total_inventory for the date, clamped to [0, 1]
     *
     * @return int price in cents
     */
    public function calculate(int $basePriceCents, float $occupancyRate): int
    {
        $occupancyRate = max(0.0, min(1.0, $occupancyRate));

        $multiplier = self::TIERS[0][1];
        foreach (self::TIERS as [$threshold, $tierMultiplier]) {
            if ($occupancyRate >= $threshold) {
                $multiplier = $tierMultiplier;
            }
        }

        return (int) round($basePriceCents * $multiplier);
    }
}
