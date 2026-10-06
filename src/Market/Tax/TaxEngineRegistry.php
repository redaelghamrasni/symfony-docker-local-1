<?php

namespace App\Market\Tax;

use App\Market\MarketContext;

/**
 * Selects the tax engine for the shop's market.
 *
 * The active engine is chosen from the home country (MarketContext): the first
 * registered engine that supports it wins; if none does, the configured
 * fallback is used (NoTaxEngine by default), so every country is deployable
 * without a dedicated engine.
 *
 * This is the market layer's first registry; CarrierGateway and PaymentGateway
 * will follow the same shape.
 */
final class TaxEngineRegistry
{
    /** @param iterable<TaxEngineInterface> $engines */
    public function __construct(
        private readonly iterable $engines,
        private readonly MarketContext $market,
        private readonly TaxEngineInterface $fallback,
    ) {
    }

    public function active(): TaxEngineInterface
    {
        return $this->forCountry($this->market->homeCountry());
    }

    public function forCountry(string $homeCountry): TaxEngineInterface
    {
        foreach ($this->engines as $engine) {
            if ($engine->supports($homeCountry)) {
                return $engine;
            }
        }

        return $this->fallback;
    }
}
