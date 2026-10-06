<?php

namespace App\Tests\Unit\Shipping\Customs;

use App\Shipping\Customs\CustomsItem;
use PHPUnit\Framework\TestCase;

class CustomsItemTest extends TestCase
{
    public function testHoldsTheDeclaredLine(): void
    {
        $item = new CustomsItem(
            description: 'Wireless mouse',
            quantity: 2,
            netWeightKg: 1.0,
            valueAmount: 59.98,
            valueCurrency: 'CAD',
            originCountry: 'CA',
            sku: 'MOUSE-1',
        );

        $this->assertSame('Wireless mouse', $item->description);
        $this->assertSame(2, $item->quantity);
        $this->assertSame('CA', $item->originCountry);
        $this->assertNull($item->hsCode);
    }

    public function testRejectsAnEmptyDescription(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CustomsItem(description: ' ', quantity: 1, netWeightKg: 0.5, valueAmount: 1.0, valueCurrency: 'CAD', originCountry: 'CA');
    }

    public function testRejectsAZeroQuantity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CustomsItem(description: 'Item', quantity: 0, netWeightKg: 0.5, valueAmount: 1.0, valueCurrency: 'CAD', originCountry: 'CA');
    }

    public function testRejectsANonIsoOriginCountry(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CustomsItem(description: 'Item', quantity: 1, netWeightKg: 0.5, valueAmount: 1.0, valueCurrency: 'CAD', originCountry: 'Canada');
    }
}
