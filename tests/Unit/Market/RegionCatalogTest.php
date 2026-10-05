<?php

namespace App\Tests\Unit\Market;

use App\Entity\MarketRegion;
use App\Market\RegionCatalog;
use App\Market\RegionProvider\DatabaseRegionProvider;
use App\Market\RegionProvider\DefaultRegionProvider;
use App\Repository\MarketRegionRepository;
use PHPUnit\Framework\TestCase;

class RegionCatalogTest extends TestCase
{
    private const DATA_FILE = __DIR__ . '/../../../config/market/regions.php';

    public function testUsesBuiltInDefaultsWhenNoOverride(): void
    {
        $catalog = $this->catalog();

        $this->assertCount(13, $catalog->forCountry('CA'));   // Canadian provinces
        $this->assertCount(51, $catalog->forCountry('US'));   // states + DC
        $this->assertTrue($catalog->hasRegions('FR'));        // France now has defaults
    }

    public function testUncataloguedCountryHasNoRegions(): void
    {
        $catalog = $this->catalog();

        $this->assertSame([], $catalog->forCountry('JP'));
        $this->assertFalse($catalog->hasRegions('JP'));
    }

    public function testDatabaseOverridesDefaultsForThatCountry(): void
    {
        $override = (new MarketRegion())->setCountry('CA')->setCode('XX')->setName('Custom');

        // The admin defined one region for CA → it replaces the 13 defaults.
        $catalog = $this->catalog(['CA' => [$override]]);

        $regions = $catalog->forCountry('CA');
        $this->assertCount(1, $regions);
        $this->assertSame('XX', $regions[0]->code);

        // A country without an override still uses its defaults.
        $this->assertCount(51, $catalog->forCountry('US'));
    }

    /** @param array<string, MarketRegion[]> $dbRowsByCountry */
    private function catalog(array $dbRowsByCountry = []): RegionCatalog
    {
        $repository = $this->createMock(MarketRegionRepository::class);
        $repository->method('findForCountry')->willReturnCallback(
            fn (string $c) => $dbRowsByCountry[strtoupper($c)] ?? []
        );

        return new RegionCatalog(
            new DatabaseRegionProvider($repository),
            new DefaultRegionProvider(self::DATA_FILE),
        );
    }
}
