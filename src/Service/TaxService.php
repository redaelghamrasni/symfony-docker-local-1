<?php

namespace App\Service;

use App\Repository\TaxRateRepository;

/**
 * Resolves and applies Canadian sales tax.
 *
 * Rates now live in the tax_rate table (managed at /admin/taxes). The constant
 * below is kept only as the seed for that table and as a last-resort fallback
 * if the table is empty (fresh install, or a deploy that has not run
 * migrations) — checkout must never fail for lack of a tax row.
 *
 * These are the current statutory rates as of 2026 (note Nova Scotia's HST is
 * 14%, down from 15% in April 2025 — the old CheckoutController copy still had
 * 15% and overcharged NS until this consolidation).
 */
class TaxService
{
    private const FALLBACK_RATES = [
        'AB' => ['name' => 'Alberta',                   'gst' => 0.05,   'pst' => 0.00,    'hst' => 0.00],
        'BC' => ['name' => 'British Columbia',          'gst' => 0.05,   'pst' => 0.07,    'hst' => 0.00],
        'MB' => ['name' => 'Manitoba',                  'gst' => 0.05,   'pst' => 0.07,    'hst' => 0.00],
        'NB' => ['name' => 'New Brunswick',             'gst' => 0.00,   'pst' => 0.00,    'hst' => 0.15],
        'NL' => ['name' => 'Newfoundland and Labrador', 'gst' => 0.00,   'pst' => 0.00,    'hst' => 0.15],
        'NS' => ['name' => 'Nova Scotia',               'gst' => 0.00,   'pst' => 0.00,    'hst' => 0.14],
        'NT' => ['name' => 'Northwest Territories',     'gst' => 0.05,   'pst' => 0.00,    'hst' => 0.00],
        'NU' => ['name' => 'Nunavut',                   'gst' => 0.05,   'pst' => 0.00,    'hst' => 0.00],
        'ON' => ['name' => 'Ontario',                   'gst' => 0.00,   'pst' => 0.00,    'hst' => 0.13],
        'PE' => ['name' => 'Prince Edward Island',      'gst' => 0.00,   'pst' => 0.00,    'hst' => 0.15],
        'QC' => ['name' => 'Quebec',                    'gst' => 0.05,   'pst' => 0.09975, 'hst' => 0.00],
        'SK' => ['name' => 'Saskatchewan',              'gst' => 0.05,   'pst' => 0.06,    'hst' => 0.00],
        'YT' => ['name' => 'Yukon',                     'gst' => 0.05,   'pst' => 0.00,    'hst' => 0.00],
    ];

    /** Request-scoped memo so a page that resolves several times hits the DB once. */
    private ?array $byProvince = null;

    public function __construct(private readonly TaxRateRepository $taxRates)
    {
    }

    /** The seed set, exposed for the migration that first populates the table. */
    public static function seedRates(): array
    {
        return self::FALLBACK_RATES;
    }

    /**
     * The applicable rates for a province, from the database, falling back to
     * the statutory defaults when no row exists. An unknown province gets 5%
     * GST — the federal floor that applies everywhere in Canada.
     */
    public function getRateForProvince(string $provinceCode): array
    {
        $province = strtoupper($provinceCode);
        $rates = $this->rates()[$province] ?? self::FALLBACK_RATES[$province] ?? ['gst' => 0.05, 'pst' => 0.00, 'hst' => 0.00];

        return [
            'province'   => $province,
            'gst'        => (float) $rates['gst'],
            'pst'        => (float) $rates['pst'],
            'hst'        => (float) $rates['hst'],
            'applicable' => (float) $rates['gst'] + (float) $rates['pst'] + (float) $rates['hst'],
            'type'       => (float) $rates['hst'] > 0 ? 'hst' : 'gst_pst',
        ];
    }

    /** GST/PST/HST amounts for a subtotal — what the checkout stores on the order. */
    public function calculateTax(float $amount, string $provinceCode): array
    {
        $rates = $this->getRateForProvince($provinceCode);

        $gstAmount = round($amount * $rates['gst'], 2);
        $pstAmount = round($amount * $rates['pst'], 2);
        $hstAmount = round($amount * $rates['hst'], 2);
        $total     = round($amount * $rates['applicable'], 2);

        return [
            'subtotal'     => $amount,
            'gst_rate'     => $rates['gst'],
            'pst_rate'     => $rates['pst'],
            'hst_rate'     => $rates['hst'],
            'gst_amount'   => $gstAmount,
            'pst_amount'   => $pstAmount,
            'hst_amount'   => $hstAmount,
            'tax_total'    => $total,
            'grand_total'  => round($amount + $total, 2),
            'type'         => $rates['type'],
            'province'     => $rates['province'],
        ];
    }

    /** @return array<string, array{gst: float, pst: float, hst: float}> */
    private function rates(): array
    {
        if ($this->byProvince === null) {
            $this->byProvince = [];
            foreach ($this->taxRates->findAllOrdered() as $row) {
                $this->byProvince[$row->getProvince()] = [
                    'gst' => (float) $row->getGst(),
                    'pst' => (float) $row->getPst(),
                    'hst' => (float) $row->getHst(),
                ];
            }
        }

        return $this->byProvince;
    }
}
