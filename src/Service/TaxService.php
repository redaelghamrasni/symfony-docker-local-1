<?php

namespace App\Service;

class TaxService
{
    // Canadian tax rates by province code
    private const RATES = [
        'AB' => ['gst' => 0.05,   'pst' => 0.00,    'hst' => 0.00],
        'BC' => ['gst' => 0.05,   'pst' => 0.07,    'hst' => 0.00],
        'MB' => ['gst' => 0.05,   'pst' => 0.07,    'hst' => 0.00],
        'NB' => ['gst' => 0.00,   'pst' => 0.00,    'hst' => 0.15],
        'NL' => ['gst' => 0.00,   'pst' => 0.00,    'hst' => 0.15],
        'NS' => ['gst' => 0.00,   'pst' => 0.00,    'hst' => 0.14],
        'NT' => ['gst' => 0.05,   'pst' => 0.00,    'hst' => 0.00],
        'NU' => ['gst' => 0.05,   'pst' => 0.00,    'hst' => 0.00],
        'ON' => ['gst' => 0.00,   'pst' => 0.00,    'hst' => 0.13],
        'PE' => ['gst' => 0.00,   'pst' => 0.00,    'hst' => 0.15],
        'QC' => ['gst' => 0.05,   'pst' => 0.09975, 'hst' => 0.00],
        'SK' => ['gst' => 0.05,   'pst' => 0.06,    'hst' => 0.00],
        'YT' => ['gst' => 0.05,   'pst' => 0.00,    'hst' => 0.00],
    ];

    /**
     * Retourne le taux de taxe applicable pour une province canadienne
     */
    public function getRateForProvince(string $provinceCode): array
    {
        $province = strtoupper($provinceCode);
        $rates    = self::RATES[$province] ?? ['gst' => 0.05, 'pst' => 0.00, 'hst' => 0.00];

        return [
            'province'   => $province,
            'gst'        => $rates['gst'],
            'pst'        => $rates['pst'],
            'hst'        => $rates['hst'],
            'applicable' => $rates['gst'] + $rates['pst'] + $rates['hst'],
            'type'       => $rates['hst'] > 0 ? 'hst' : 'gst_pst',
        ];
    }

    /**
     * Calcule les taxes sur un montant donné
     */
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
}
