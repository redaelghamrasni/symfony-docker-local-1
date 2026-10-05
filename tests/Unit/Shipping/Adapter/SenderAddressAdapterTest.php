<?php

namespace App\Tests\Unit\Shipping\Adapter;

use App\Market\MarketContext;
use App\Service\SettingService;
use App\Shipping\Adapter\SenderAddressAdapter;
use PHPUnit\Framework\TestCase;

class SenderAddressAdapterTest extends TestCase
{
    public function testFallsBackToBundledOriginAndHomeCountryWhenUnconfigured(): void
    {
        $adapter = $this->adapter(settings: [], homeCountry: 'CA');

        $origin = $adapter->address();

        // Empty settings → the dev fallback keeps a fresh shop shippable,
        // and the country comes from the home market.
        $this->assertSame('CA', $origin->country);
        $this->assertNotSame('', $origin->street1);
        $this->assertNotSame('', $origin->city);
        $this->assertTrue($origin->hasPhone());
    }

    public function testHomeCountryDrivesTheOriginCountryForANonCanadianShop(): void
    {
        $adapter = $this->adapter(settings: [], homeCountry: 'FR');

        $this->assertSame('FR', $adapter->address()->country);
    }

    public function testAdminSettingsOverrideTheFallbackFieldByField(): void
    {
        $adapter = $this->adapter(settings: [
            'shipping.origin.name'        => 'Acme Warehouse',
            'shipping.origin.street1'     => '500 King St',
            'shipping.origin.city'        => 'Toronto',
            'shipping.origin.state'       => 'ON',
            'shipping.origin.postal_code' => 'M5V1A1',
            'shipping.origin.phone'       => '+14160000000',
        ], homeCountry: 'CA');

        $origin = $adapter->address();

        $this->assertSame('Acme Warehouse', $origin->name);
        $this->assertSame('Toronto', $origin->city);
        $this->assertSame('ON', $origin->state);
        $this->assertSame('M5V1A1', $origin->postalCode);
    }

    public function testAnExplicitOriginCountrySettingWinsOverHomeCountry(): void
    {
        // A CA-based shop fulfilling from a US warehouse.
        $adapter = $this->adapter(settings: [
            'shipping.origin.country' => 'us',
        ], homeCountry: 'CA');

        $this->assertSame('US', $adapter->address()->country);
    }

    /**
     * @param array<string, string> $settings
     */
    private function adapter(array $settings, string $homeCountry): SenderAddressAdapter
    {
        $settingService = $this->createMock(SettingService::class);
        $settingService->method('get')->willReturnCallback(
            fn (string $key, mixed $default = null) => $settings[$key] ?? $default
        );

        $market = $this->createMock(MarketContext::class);
        $market->method('homeCountry')->willReturn($homeCountry);

        return new SenderAddressAdapter($settingService, $market);
    }
}
