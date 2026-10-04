<?php

namespace App\Tests\Unit\Market\Tax;

use App\Market\MarketContext;
use App\Market\Tax\Engine\NoTaxEngine;
use App\Market\Tax\TaxEngineInterface;
use App\Market\Tax\TaxEngineRegistry;
use PHPUnit\Framework\TestCase;

class TaxEngineRegistryTest extends TestCase
{
    public function testResolvesSupportingEngineForHome(): void
    {
        $engine = $this->engineSupporting('CA');

        $registry = new TaxEngineRegistry([$engine], $this->marketWith('CA'), new NoTaxEngine());

        $this->assertSame($engine, $registry->forCountry('CA'));
    }

    public function testFallsBackWhenNoEngineSupportsTheCountry(): void
    {
        $engine = $this->engineSupporting('CA');

        $fallback = new NoTaxEngine();
        $registry = new TaxEngineRegistry([$engine], $this->marketWith('FR'), $fallback);

        $this->assertSame($fallback, $registry->forCountry('FR'));
    }

    public function testActiveUsesTheHomeCountry(): void
    {
        $engine = $this->engineSupporting('CA');

        $registry = new TaxEngineRegistry([$engine], $this->marketWith('CA'), new NoTaxEngine());

        $this->assertSame($engine, $registry->active());
    }

    private function engineSupporting(string $country): TaxEngineInterface
    {
        $engine = $this->createMock(TaxEngineInterface::class);
        $engine->method('supports')->willReturnCallback(
            fn (string $c) => strtoupper($c) === strtoupper($country)
        );

        return $engine;
    }

    private function marketWith(string $country): MarketContext
    {
        $market = $this->createMock(MarketContext::class);
        $market->method('homeCountry')->willReturn($country);

        return $market;
    }
}
