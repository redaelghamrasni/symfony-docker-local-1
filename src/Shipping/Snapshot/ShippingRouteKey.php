<?php

namespace App\Shipping\Snapshot;

/**
 * Normalizes a shipping lookup into a stable snapshot key.
 *
 * The key groups destinations that should share a fallback: a country, a region
 * (province/state) and a weight band. It is deliberately coarser than a live
 * rating — the snapshot is a degraded mode, so an approximate "latest known-good
 * price for Quebec parcels up to 5 kg" is the right granularity, not a per-postal
 * quote. The same computation runs on both sides of the mechanism: the refresh
 * command uses it to decide where to store a snapshot, and the checkout uses it
 * to decide which snapshot to recall — so they always agree.
 *
 * Weight bands are fixed boundaries (kg); a parcel falls into the first band it
 * does not exceed, and anything heavier than the last boundary lands in an open
 * top band. Keep these in sync with the probe profiles in
 * config/shipping/rate_probes.php so every band a customer can hit is pre-warmed.
 */
final class ShippingRouteKey
{
    /**
     * Upper bounds (kg, inclusive) of the closed weight bands. A parcel at or
     * below a boundary belongs to that band; above the last one it belongs to
     * the open top band labelled "<last>+".
     *
     * @var list<float>
     */
    public const WEIGHT_BANDS = [1.0, 5.0, 10.0, 30.0];

    /**
     * @param string      $country destination country (ISO-2)
     * @param string|null $region  destination region/province code, if any
     * @param float       $weight  parcel weight in kg
     */
    public static function for(string $country, ?string $region, float $weight): string
    {
        $country = strtoupper(trim($country));
        $region  = strtoupper(trim((string) $region));

        return sprintf('%s|%s|%s', $country ?: '??', $region ?: '*', self::band($weight));
    }

    /**
     * One representative weight per band, derived from the band boundaries — used
     * by the refresh to re-rate a real destination across every band without any
     * invented parcel data. Each closed band is sampled at its upper bound (the
     * most conservative price inside it, so a fallback never under-quotes); the
     * open top band at twice the last bound.
     *
     * @return list<float>
     */
    public static function sampleWeights(): array
    {
        $bands   = self::WEIGHT_BANDS;
        $weights = $bands;
        $weights[] = $bands[count($bands) - 1] * 2;

        return $weights;
    }

    /** The weight-band label a parcel of this weight falls into. */
    public static function band(float $weight): string
    {
        $weight = max(0.0, $weight);
        $bands  = self::WEIGHT_BANDS;

        foreach ($bands as $bound) {
            if ($weight <= $bound) {
                return '<=' . self::formatKg($bound);
            }
        }

        $last = $bands[count($bands) - 1];

        return self::formatKg($last) . '+';
    }

    private static function formatKg(float $kg): string
    {
        // Render whole numbers without a trailing ".0" so keys read "5kg", not "5.0kg".
        $kg = $kg == (int) $kg ? (string) (int) $kg : (string) $kg;

        return $kg . 'kg';
    }
}
