<?php

namespace App\Market\Tax\Engine;

use App\Market\Tax\TaxEngineInterface;
use App\Market\Tax\TaxLineData;
use App\Market\Tax\TaxQuote;

/**
 * A single tax line at one configured rate — the pragmatic fallback for a
 * market whose precise rules are not yet implemented (a flat national VAT or
 * sales tax). It lets a new country launch with roughly-right tax before a
 * dedicated engine is written.
 *
 * supports() returns false: like NoTaxEngine it is used only as the registry's
 * explicit fallback, selected by configuration rather than by home country.
 * A rate of 0 produces an empty quote, so an unconfigured instance is inert.
 */
final class FlatRateTaxEngine implements TaxEngineInterface
{
    public function __construct(
        private readonly float $rate = 0.0,
        private readonly string $code = 'tax',
        private readonly string $label = 'Tax',
    ) {
    }

    public function supports(string $homeCountry): bool
    {
        return false;
    }

    public function quote(float $subtotal, string $destinationCountry, ?string $destinationRegion): TaxQuote
    {
        if ($this->rate <= 0) {
            return new TaxQuote();
        }

        $amount = round($subtotal * $this->rate, 2);
        if ($amount <= 0) {
            return new TaxQuote();
        }

        return new TaxQuote([
            new TaxLineData(
                code: $this->code,
                label: $this->label,
                rate: number_format($this->rate, 5, '.', ''),
                amount: number_format($amount, 2, '.', ''),
                jurisdiction: $destinationRegion ?: $destinationCountry,
            ),
        ]);
    }
}
