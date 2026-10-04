<?php

namespace App\Market\Tax;

/**
 * One line of a tax quote — the neutral, vendor-free shape a TaxEngineInterface returns.
 *
 * Deliberately not the OrderTaxLine entity: an engine computes, it does not
 * persist. The checkout maps these onto OrderTaxLine rows when it saves the
 * order, so the engine stays free of Doctrine.
 */
final readonly class TaxLineData
{
    public function __construct(
        public string $code,
        public string $label,
        public ?string $rate,
        public string $amount,
        public ?string $jurisdiction = null,
    ) {
    }
}
