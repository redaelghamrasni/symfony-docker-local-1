<?php

namespace App\Tests\Unit\Service;

use App\Entity\ShippingRateSnapshot;
use App\Service\ShippingService;
use App\Market\MarketContext;
use App\Service\SettingService;
use App\Shipping\Adapter\CarrierAddressAdapter;
use App\Shipping\Adapter\SenderAddressAdapter;
use App\Shipping\Snapshot\ShippingRateSnapshotStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The degraded-mode contract: when the live carrier returns no rates, getRates
 * serves the latest known-good snapshot (flagged, with its capture time) instead
 * of an empty list; a live result is passed through untouched and never consults
 * the fallback.
 */
class ShippingServiceFallbackTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $liveRates
     */
    private function makeService(array $liveRates, ShippingRateSnapshotStore $store, ?LoggerInterface $logger = null): ShippingService
    {
        // SenderAddressAdapter is final (not mockable), but getRates only touches
        // it when the destination carries no country — which these tests never do —
        // so a real instance over mocked settings is enough and is never called.
        $sender = new SenderAddressAdapter(
            $this->createMock(SettingService::class),
            $this->createMock(MarketContext::class),
        );

        $service = $this->getMockBuilder(ShippingService::class)
            ->setConstructorArgs([
                'shippo_test_key',
                $sender,
                $this->createMock(CarrierAddressAdapter::class),
                $logger ?? $this->createMock(LoggerInterface::class),
                $store,
            ])
            ->onlyMethods(['rateLive'])
            ->getMock();

        $service->method('rateLive')->willReturn($liveRates);

        return $service;
    }

    private function makeThrowingService(\Throwable $error, ShippingRateSnapshotStore $store): ShippingService
    {
        $sender = new SenderAddressAdapter(
            $this->createMock(SettingService::class),
            $this->createMock(MarketContext::class),
        );

        $service = $this->getMockBuilder(ShippingService::class)
            ->setConstructorArgs([
                'shippo_test_key',
                $sender,
                $this->createMock(CarrierAddressAdapter::class),
                $this->createMock(LoggerInterface::class),
                $store,
            ])
            ->onlyMethods(['rateLive'])
            ->getMock();

        $service->method('rateLive')->willThrowException($error);

        return $service;
    }

    private function makeSnapshot(string $routeKey, array $rates): ShippingRateSnapshot
    {
        $snapshot = new ShippingRateSnapshot();
        $snapshot->setRouteKey($routeKey)->setCountry('CA')->setRegion('QC')->setWeightBand('<=5kg')->setRates($rates);
        $snapshot->touchCapturedAt();

        return $snapshot;
    }

    public function testLiveRatesArePassedThroughCapturedAndFallbackNotConsulted(): void
    {
        $live  = [['carrier' => 'UPS', 'service' => 'Ground', 'price' => '9.99']];
        $store = $this->createMock(ShippingRateSnapshotStore::class);
        // A successful live rating is captured as the snapshot (opportunistic
        // warming from real traffic) and never reads the fallback.
        $store->expects(self::once())->method('remember')->with('CA', 'QC', 3.0, $live);
        $store->expects(self::never())->method('recall');

        $service = $this->makeService($live, $store);

        $rates = $service->getRates(['country' => 'CA', 'state' => 'QC'], ['weight' => 3.0]);

        self::assertSame($live, $rates);
        self::assertArrayNotHasKey('from_snapshot', $rates[0]);
    }

    public function testCaptureFailureDoesNotBreakLiveRating(): void
    {
        $live  = [['carrier' => 'UPS', 'price' => '9.99']];
        $store = $this->createMock(ShippingRateSnapshotStore::class);
        $store->method('remember')->willThrowException(new \RuntimeException('db down'));

        $service = $this->makeService($live, $store);

        // The customer still gets their live rates even if the capture write fails.
        self::assertSame($live, $service->getRates(['country' => 'CA', 'state' => 'QC'], ['weight' => 3.0]));
    }

    public function testSnapshotServedWhenLiveIsEmpty(): void
    {
        $snapshotRates = [
            ['carrier' => 'UPS', 'service' => 'Ground', 'price' => '12.00'],
            ['carrier' => 'Canada Post', 'service' => 'Regular', 'price' => '14.50'],
        ];
        $snapshot = $this->makeSnapshot('CA|QC|<=5kg', $snapshotRates);

        $store = $this->createMock(ShippingRateSnapshotStore::class);
        $store->expects(self::once())->method('recall')->with('CA', 'QC', 3.0)->willReturn($snapshot);

        $service = $this->makeService([], $store);

        $rates = $service->getRates(['country' => 'CA', 'state' => 'QC'], ['weight' => 3.0]);

        self::assertCount(2, $rates);
        foreach ($rates as $rate) {
            self::assertTrue($rate['from_snapshot']);
            self::assertNotNull($rate['captured_at']);
        }
        // The original price survives the flagging.
        self::assertSame('12.00', $rates[0]['price']);
    }

    public function testEmptyLiveAndNoSnapshotReturnsEmpty(): void
    {
        $store = $this->createMock(ShippingRateSnapshotStore::class);
        $store->method('recall')->willReturn(null);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::atLeastOnce())->method('warning');

        $service = $this->makeService([], $store, $logger);

        self::assertSame([], $service->getRates(['country' => 'US', 'state' => 'NY'], ['weight' => 20.0]));
    }

    public function testSnapshotServedWhenLiveThrows(): void
    {
        $snapshot = $this->makeSnapshot('CA|QC|<=5kg', [['carrier' => 'UPS', 'price' => '12.00']]);

        $store = $this->createMock(ShippingRateSnapshotStore::class);
        $store->method('recall')->willReturn($snapshot);

        $service = $this->makeThrowingService(new \RuntimeException('Shippo unreachable'), $store);

        $rates = $service->getRates(['country' => 'CA', 'state' => 'QC'], ['weight' => 3.0]);

        self::assertCount(1, $rates);
        self::assertTrue($rates[0]['from_snapshot']);
    }

    public function testLiveErrorWithNoSnapshotIsRethrown(): void
    {
        $store = $this->createMock(ShippingRateSnapshotStore::class);
        $store->method('recall')->willReturn(null);

        $service = $this->makeThrowingService(new \RuntimeException('Shippo unreachable'), $store);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Shippo unreachable');

        $service->getRates(['country' => 'US', 'state' => 'NY'], ['weight' => 3.0]);
    }
}
