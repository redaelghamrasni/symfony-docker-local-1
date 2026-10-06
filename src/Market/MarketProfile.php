<?php

namespace App\Market;

use App\Market\Tax\TaxEngineInterface;
use App\Market\Tax\TaxEngineRegistry;
use App\Service\CurrencyService;

/**
 * The active market — the "country module" the shop runs as.
 *
 * A single facade that composes the market's moving parts (tax engine, regions,
 * default currency) resolved from the home-country setting. One deployment is
 * one market, so there is no registry of profiles: this *is* the active profile.
 *
 * Callers ask the market what it needs instead of hardcoding Canada: the
 * checkout gets its region list here, the tax is computed by the engine here.
 * Enabling a new market is configuration (home country, rates, regions, enabled
 * gateways) plus — only for a new tax model — one TaxEngine implementation.
 */
class MarketProfile
{
    public function __construct(
        private readonly MarketContext $context,
        private readonly TaxEngineRegistry $taxEngines,
        private readonly RegionCatalog $regions,
        private readonly CurrencyService $currencies,
    ) {
    }

    /** ISO 3166-1 alpha-2 home country of the shop. */
    public function country(): string
    {
        return $this->context->homeCountry();
    }

    public function taxEngine(): TaxEngineInterface
    {
        return $this->taxEngines->active();
    }

    public function defaultCurrency(): string
    {
        return $this->currencies->default();
    }

    /**
     * Sub-national regions for a country (defaults to the home country). Empty
     * means the checkout should offer a free-text region field instead of a
     * dropdown.
     *
     * @return Region[]
     */
    public function regions(?string $country = null): array
    {
        return $this->regions->forCountry($country ?? $this->country());
    }

    public function hasRegions(?string $country = null): bool
    {
        return $this->regions->hasRegions($country ?? $this->country());
    }
}
