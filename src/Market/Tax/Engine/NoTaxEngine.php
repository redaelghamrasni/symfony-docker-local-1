<?php

namespace App\Market\Tax\Engine;

use App\Market\Tax\TaxEngineInterface;
use App\Market\Tax\TaxQuote;

/**
 * Charges no tax. The default fallback so a market with no configured tax
 * engine is still deployable — checkout works, orders simply carry no tax.
 *
 * supports() returns false: this engine is never auto-selected for a home
 * country, only used as the registry's explicit fallback.
 */
final class NoTaxEngine implements TaxEngineInterface
{
    public function supports(string $homeCountry): bool
    {
        return false;
    }

    public function quote(float $subtotal, string $destinationCountry, ?string $destinationRegion): TaxQuote
    {
        return new TaxQuote();
    }
}
