<?php

namespace App\Market\RegionProvider;

use App\Market\Region;

/**
 * A source of sub-national regions for a country.
 *
 * This is the "addressing data" seam, owned by us rather than by a vendor: the
 * default implementation reads the app's own data file, the database one reads
 * admin-managed overrides, and a future one could wrap an external library —
 * all behind this port, so the source is swappable (or removable) without
 * touching RegionCatalog, MarketProfile or the checkout.
 *
 * Returns an empty array for a country it does not know; the catalog decides
 * how providers combine.
 */
interface RegionProviderInterface
{
    /** @return Region[] */
    public function regions(string $country): array;
}
