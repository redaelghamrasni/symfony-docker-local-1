<?php

namespace App\Market\Tax\Engine;

use App\Market\Tax\TaxEngineInterface;
use App\Market\Tax\TaxLineData;
use App\Market\Tax\TaxQuote;
use App\Service\TaxService;

/**
 * Canadian sales tax: GST (+ PST/QST) or HST, by destination province.
 *
 * The rates come from the tax_rate table via TaxService (editable in the admin,
 * statutory fallback built in). This engine turns those rates into neutral tax
 * lines — the Canada-specific line-building that used to live in the checkout
 * controller now lives here, behind the TaxEngine port.
 *
 * Tax applies only to Canadian destinations: a sale shipped out of the country
 * is an export and is zero-rated, which is why a non-CA destination returns an
 * empty quote rather than Canadian tax.
 */
final class CanadaTaxEngine implements TaxEngineInterface
{
    public function __construct(private readonly TaxService $taxService)
    {
    }

    public function supports(string $homeCountry): bool
    {
        return strtoupper($homeCountry) === 'CA';
    }

    public function quote(float $subtotal, string $destinationCountry, ?string $destinationRegion): TaxQuote
    {
        if (strtoupper($destinationCountry) !== 'CA') {
            return new TaxQuote();
        }

        $province = strtoupper((string) $destinationRegion);
        $rates = $this->taxService->getRateForProvince($province);
        $jurisdiction = $province !== '' ? $province : null;

        $components = [
            ['gst', 'GST', (float) $rates['gst']],
            [$province === 'QC' ? 'qst' : 'pst', $province === 'QC' ? 'QST' : 'PST', (float) $rates['pst']],
            ['hst', 'HST', (float) $rates['hst']],
        ];

        $lines = [];
        foreach ($components as [$code, $label, $rate]) {
            $amount = round($subtotal * $rate, 2);
            if ($amount <= 0) {
                continue;
            }

            $lines[] = new TaxLineData(
                code: $code,
                label: $label,
                rate: $rate > 0 ? number_format($rate, 5, '.', '') : null,
                amount: number_format($amount, 2, '.', ''),
                jurisdiction: $jurisdiction,
            );
        }

        return new TaxQuote($lines);
    }
}
