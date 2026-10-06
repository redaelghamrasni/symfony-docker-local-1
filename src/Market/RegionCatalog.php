<?php

namespace App\Market;

use App\Market\RegionProvider\DatabaseRegionProvider;
use App\Market\RegionProvider\DefaultRegionProvider;

/**
 * Resolves the sub-national regions for a country from the available providers.
 *
 * Strategy — "database overrides defaults", per country (the Magento "config
 * with defaults" model): if the admin has defined any region for a country, that
 * set is authoritative; otherwise the bundled defaults apply. A country in
 * neither returns an empty list, and the checkout offers a free-text field.
 *
 * Its public surface (forCountry / hasRegions) is unchanged from the earlier
 * hardcoded version, so MarketProfile and the checkout did not have to change.
 * Adding another source later (e.g. a library adapter) is a new provider plus a
 * line here — not a change to any caller.
 */
class RegionCatalog
{
    public function __construct(
        private readonly DatabaseRegionProvider $overrides,
        private readonly DefaultRegionProvider $defaults,
    ) {
    }

    /** @return Region[] */
    public function forCountry(string $country): array
    {
        $fromDb = $this->overrides->regions($country);

        return $fromDb !== [] ? $fromDb : $this->defaults->regions($country);
    }

    public function hasRegions(string $country): bool
    {
        return $this->forCountry($country) !== [];
    }
}
