<?php

namespace App\Tests\Unit\Market\RegionProvider;

use App\Market\RegionProvider\DefaultRegionProvider;
use PHPUnit\Framework\TestCase;

class DefaultRegionProviderTest extends TestCase
{
    private DefaultRegionProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new DefaultRegionProvider(__DIR__ . '/../../../../config/market/regions.php');
    }

    public function testLoadsBundledMarkets(): void
    {
        $this->assertCount(13, $this->provider->regions('CA'));
        $this->assertCount(51, $this->provider->regions('US'));
        $this->assertCount(81, $this->provider->regions('TR'));  // Turkish provinces
        $this->assertCount(7, $this->provider->regions('AE'));   // Emirates
    }

    public function testLookupIsCaseInsensitive(): void
    {
        $this->assertCount(13, $this->provider->regions('ca'));
    }

    public function testRegionsCarryCodeAndName(): void
    {
        $qc = null;
        foreach ($this->provider->regions('CA') as $region) {
            if ($region->code === 'QC') {
                $qc = $region;
            }
        }

        $this->assertNotNull($qc);
        $this->assertSame('Quebec', $qc->name);
    }

    public function testUnknownCountryIsEmpty(): void
    {
        $this->assertSame([], $this->provider->regions('JP'));
    }

    public function testMissingFileIsEmptyNotFatal(): void
    {
        $provider = new DefaultRegionProvider('/no/such/file.php');

        $this->assertSame([], $provider->regions('CA'));
    }
}
