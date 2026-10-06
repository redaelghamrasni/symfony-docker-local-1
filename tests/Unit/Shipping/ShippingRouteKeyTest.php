<?php

namespace App\Tests\Unit\Shipping;

use App\Shipping\Snapshot\ShippingRouteKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The route key is the contract shared by the refresh command (where it stores a
 * snapshot) and the checkout (where it looks one up): if these two ever disagreed
 * on the key, the fallback would silently never match. These lock the key format
 * and the weight-band boundaries so that cannot drift.
 */
class ShippingRouteKeyTest extends TestCase
{
    public function testKeyNormalizesCountryAndRegion(): void
    {
        self::assertSame('CA|QC|<=5kg', ShippingRouteKey::for('ca', ' qc ', 3.0));
    }

    public function testMissingRegionBecomesWildcard(): void
    {
        self::assertSame('CA|*|<=1kg', ShippingRouteKey::for('CA', null, 0.5));
        self::assertSame('CA|*|<=1kg', ShippingRouteKey::for('CA', '', 0.5));
    }

    #[DataProvider('weightBands')]
    public function testBandBoundaries(float $weight, string $expected): void
    {
        self::assertSame($expected, ShippingRouteKey::band($weight));
    }

    /** @return array<string, array{0: float, 1: string}> */
    public static function weightBands(): array
    {
        return [
            'zero'          => [0.0, '<=1kg'],
            'on 1kg bound'  => [1.0, '<=1kg'],
            'just over 1kg' => [1.001, '<=5kg'],
            'on 5kg bound'  => [5.0, '<=5kg'],
            'mid 10kg'      => [7.5, '<=10kg'],
            'on 30kg bound' => [30.0, '<=30kg'],
            'over top band' => [45.0, '30kg+'],
        ];
    }

    public function testNegativeWeightClampsToFirstBand(): void
    {
        self::assertSame('<=1kg', ShippingRouteKey::band(-5.0));
    }
}
