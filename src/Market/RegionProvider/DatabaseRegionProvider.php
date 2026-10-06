<?php

namespace App\Market\RegionProvider;

use App\Market\Region;
use App\Repository\MarketRegionRepository;

/**
 * Regions from the admin-managed market_region table.
 *
 * Empty by default; a merchant fills it to add a market the defaults do not
 * cover, or to customise one. It is the override layer RegionCatalog consults
 * before the bundled defaults.
 */
final class DatabaseRegionProvider implements RegionProviderInterface
{
    public function __construct(private readonly MarketRegionRepository $repository)
    {
    }

    public function regions(string $country): array
    {
        $regions = [];
        foreach ($this->repository->findForCountry($country) as $row) {
            $regions[] = new Region($row->getCode(), $row->getName());
        }

        return $regions;
    }
}
