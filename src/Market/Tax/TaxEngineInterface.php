<?php

namespace App\Market\Tax;

/**
 * The tax port: how a market computes sales tax.
 *
 * This is the first of the three swappable axes (tax / shipping / payment).
 * The checkout depends on this interface, never on a concrete engine, so
 * entering a market with a different tax model means adding one implementation
 * — not editing the checkout.
 *
 * `supports()` answers "can this engine serve a shop based in this country?",
 * which is how the registry selects the active engine from the home country.
 * `quote()` then computes the tax for a given destination under that market's
 * rules — Canadian tax is destination-based, so the destination region matters.
 */
interface TaxEngineInterface
{
    public function supports(string $homeCountry): bool;

    public function quote(float $subtotal, string $destinationCountry, ?string $destinationRegion): TaxQuote;
}
