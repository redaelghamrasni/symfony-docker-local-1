<?php

namespace App\Market\Tax;

/**
 * The result of a tax calculation: zero or more tax lines.
 *
 * A Canadian order quotes GST + QST; a German order a single VAT line; a
 * US order state + city lines; a tax-free market an empty quote. The checkout
 * stores the lines on the order and uses the total for the grand total.
 */
final readonly class TaxQuote
{
    /** @param TaxLineData[] $lines */
    public function __construct(public array $lines = [])
    {
    }

    public function total(): string
    {
        $total = 0.0;
        foreach ($this->lines as $line) {
            $total += (float) $line->amount;
        }

        return number_format($total, 2, '.', '');
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }
}
