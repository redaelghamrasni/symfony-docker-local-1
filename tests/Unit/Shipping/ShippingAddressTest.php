<?php

namespace App\Tests\Unit\Shipping;

use App\Shipping\ShippingAddress;
use PHPUnit\Framework\TestCase;

class ShippingAddressTest extends TestCase
{
    public function testRequiresTheStructuralFields(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ShippingAddress(name: 'Store', street1: '', city: 'Montréal', country: 'CA');
    }

    public function testRejectsANonIsoCountry(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ShippingAddress(name: 'Store', street1: '1 rue', city: 'Montréal', country: 'Canada');
    }

    public function testStateAndPostalCodeAreOptional(): void
    {
        $address = new ShippingAddress(name: 'Store', street1: '1 rue', city: 'Dubai', country: 'AE');

        $this->assertNull($address->state);
        $this->assertNull($address->postalCode);
    }

    public function testDetectsCrossingABorder(): void
    {
        $origin = new ShippingAddress(name: 'Store', street1: '1 rue', city: 'Montréal', country: 'CA');
        $domestic = new ShippingAddress(name: 'A', street1: '2 rue', city: 'Québec', country: 'ca');
        $abroad = new ShippingAddress(name: 'B', street1: '3 st', city: 'Buffalo', country: 'US');

        $this->assertFalse($domestic->isInternationalFrom($origin));  // case-insensitive
        $this->assertTrue($abroad->isInternationalFrom($origin));
    }

    public function testInternationalReadinessNeedsAPhone(): void
    {
        $noPhone = new ShippingAddress(name: 'B', street1: '3 st', city: 'Buffalo', country: 'US');

        $this->expectException(\DomainException::class);
        $noPhone->assertReadyForInternational('recipient');
    }

    public function testInternationalReadinessPassesWithAPhone(): void
    {
        $withPhone = new ShippingAddress(
            name: 'B', street1: '3 st', city: 'Buffalo', country: 'US', phone: '+17160000000',
        );

        $withPhone->assertReadyForInternational('recipient');
        $this->assertTrue($withPhone->hasPhone());
    }
}
