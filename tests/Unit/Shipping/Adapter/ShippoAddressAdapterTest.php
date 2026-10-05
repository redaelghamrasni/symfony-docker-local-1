<?php

namespace App\Tests\Unit\Shipping\Adapter;

use App\Shipping\Adapter\ShippoAddressAdapter;
use App\Shipping\ShippingAddress;
use PHPUnit\Framework\TestCase;

class ShippoAddressAdapterTest extends TestCase
{
    public function testMapsToShippoFieldNames(): void
    {
        $payload = (new ShippoAddressAdapter())->toPayload(new ShippingAddress(
            name:       'Acme',
            street1:    '500 King St',
            city:       'Toronto',
            country:    'ca',
            state:      'ON',
            postalCode: 'M5V1A1',
            phone:      '+14160000000',
            email:      'ship@example.com',
            company:    'Acme Inc',
        ));

        $this->assertSame('M5V1A1', $payload['zip']);   // postalCode → zip
        $this->assertSame('ON', $payload['state']);
        $this->assertSame('CA', $payload['country']);   // uppercased
        $this->assertSame('Acme Inc', $payload['company']);
    }

    public function testNullOptionalFieldsBecomeEmptyStrings(): void
    {
        $payload = (new ShippoAddressAdapter())->toPayload(new ShippingAddress(
            name: 'A', street1: '1 st', city: 'Dubai', country: 'AE',
        ));

        // Shippo wants empty strings, not nulls, for absent fields.
        $this->assertSame('', $payload['state']);
        $this->assertSame('', $payload['zip']);
        $this->assertSame('', $payload['phone']);
        $this->assertSame('', $payload['company']);
    }
}
