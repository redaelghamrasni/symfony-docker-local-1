<?php

namespace App\Tests\Unit\Market;

use App\Market\MarketContext;
use App\Market\MarketProfile;
use App\Market\RegionCatalog;
use App\Market\RegionProvider\DatabaseRegionProvider;
use App\Market\RegionProvider\DefaultRegionProvider;
use App\Market\Tax\Engine\NoTaxEngine;
use App\Market\Tax\TaxEngineInterface;
use App\Market\Tax\TaxEngineRegistry;
use App\Repository\MarketRegionRepository;
use App\Service\CurrencyService;
use PHPUnit\Framework\TestCase;

class MarketProfileTest extends TestCase
{
    public function testExposesHomeCountryRegionsAndCurrency(): void
    {
        $profile = $this->profileForHome('CA');

        $this->assertSame('CA', $profile->country());
        $this->assertCount(13, $profile->regions());      // defaults to home country
        $this->assertTrue($profile->hasRegions());
        $this->assertSame('CAD', $profile->defaultCurrency());
    }

    public function testRegionsFallBackToFreeTextForUncataloguedCountry(): void
    {
        // Japan is a real country not in the built-in defaults → free-text.
        $profile = $this->profileForHome('JP');

        $this->assertSame([], $profile->regions());
        $this->assertFalse($profile->hasRegions());
    }

    public function testTaxEngineComesFromTheRegistry(): void
    {
        $engine  = $this->createMock(TaxEngineInterface::class);
        $profile = $this->profileForHome('CA', $engine);

        $this->assertSame($engine, $profile->taxEngine());
    }

    private function profileForHome(string $country, ?TaxEngineInterface $engine = null): MarketProfile
    {
        $context = $this->createMock(MarketContext::class);
        $context->method('homeCountry')->willReturn($country);

        // Real registry (it is final). With no registered engines, active()
        // returns the fallback — so the fallback stands in as "the active engine".
        $registry = new TaxEngineRegistry([], $context, $engine ?? new NoTaxEngine());

        $currencies = $this->createMock(CurrencyService::class);
        $currencies->method('default')->willReturn('CAD');

        $repository = $this->createMock(MarketRegionRepository::class);
        $repository->method('findForCountry')->willReturn([]);
        $catalog = new RegionCatalog(
            new DatabaseRegionProvider($repository),
            new DefaultRegionProvider(__DIR__ . '/../../../config/market/regions.php'),
        );

        return new MarketProfile($context, $registry, $catalog, $currencies);
    }
}
