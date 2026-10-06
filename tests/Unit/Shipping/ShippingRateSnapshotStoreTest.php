<?php

namespace App\Tests\Unit\Shipping;

use App\Entity\ShippingRateSnapshot;
use App\Repository\ShippingRateSnapshotRepository;
use App\Shipping\Snapshot\ShippingRateSnapshotStore;
use App\Shipping\Snapshot\ShippingRouteKey;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * The two guarantees the roadmap pins on the store: a refresh that got no rates
 * never wipes a good snapshot, and a lookup with no exact region match falls back
 * to the country-wide snapshot for the same weight band rather than giving up.
 */
class ShippingRateSnapshotStoreTest extends TestCase
{
    public function testRememberSkipsEmptyRatesAndDoesNotTouchTheDatabase(): void
    {
        $repo = $this->createMock(ShippingRateSnapshotRepository::class);
        $repo->expects(self::never())->method('findOneByRouteKey');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');

        $store = new ShippingRateSnapshotStore($repo, $em);

        self::assertFalse($store->remember('CA', 'QC', 3.0, []));
    }

    public function testRememberUpsertsAnExistingSnapshot(): void
    {
        $existing = new ShippingRateSnapshot();
        $key      = ShippingRouteKey::for('CA', 'QC', 3.0);

        $repo = $this->createMock(ShippingRateSnapshotRepository::class);
        $repo->method('findOneByRouteKey')->with($key)->willReturn($existing);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')->with($existing);
        $em->expects(self::once())->method('flush');

        $store = new ShippingRateSnapshotStore($repo, $em);

        $rates = [['carrier' => 'UPS', 'price' => '12.00']];
        self::assertTrue($store->remember('CA', 'QC', 3.0, $rates));

        self::assertSame($key, $existing->getRouteKey());
        self::assertSame('CA', $existing->getCountry());
        self::assertSame('QC', $existing->getRegion());
        self::assertSame('<=5kg', $existing->getWeightBand());
        self::assertSame($rates, $existing->getRates());
    }

    public function testRecallReturnsExactRegionMatch(): void
    {
        $snapshot = new ShippingRateSnapshot();
        $exactKey = ShippingRouteKey::for('CA', 'QC', 3.0);

        $repo = $this->createMock(ShippingRateSnapshotRepository::class);
        $repo->method('findOneByRouteKey')->willReturnCallback(
            fn (string $k) => $k === $exactKey ? $snapshot : null
        );

        $store = new ShippingRateSnapshotStore($repo, $this->createMock(EntityManagerInterface::class));

        self::assertSame($snapshot, $store->recall('CA', 'QC', 3.0));
    }

    public function testRecallFallsBackToCountryWideWhenRegionMisses(): void
    {
        $countryWide = new ShippingRateSnapshot();
        $exactKey    = ShippingRouteKey::for('CA', 'YT', 3.0);
        $wildcardKey = ShippingRouteKey::for('CA', null, 3.0);

        $repo = $this->createMock(ShippingRateSnapshotRepository::class);
        $repo->method('findOneByRouteKey')->willReturnCallback(
            fn (string $k) => match ($k) {
                $exactKey    => null,
                $wildcardKey => $countryWide,
                default      => null,
            }
        );

        $store = new ShippingRateSnapshotStore($repo, $this->createMock(EntityManagerInterface::class));

        self::assertSame($countryWide, $store->recall('CA', 'YT', 3.0));
    }

    public function testRecallReturnsNullWhenNothingMatches(): void
    {
        $repo = $this->createMock(ShippingRateSnapshotRepository::class);
        $repo->method('findOneByRouteKey')->willReturn(null);

        $store = new ShippingRateSnapshotStore($repo, $this->createMock(EntityManagerInterface::class));

        self::assertNull($store->recall('US', 'NY', 3.0));
    }
}
