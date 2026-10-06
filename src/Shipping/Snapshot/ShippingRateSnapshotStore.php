<?php

namespace App\Shipping\Snapshot;

use App\Entity\ShippingRateSnapshot;
use App\Repository\ShippingRateSnapshotRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Persists and retrieves the known-good shipping-rate snapshots.
 *
 * Two guarantees the roadmap asks for live here:
 *   - `remember()` never overwrites a good snapshot with an empty one, so a
 *     refresh that briefly got no rates cannot wipe the fallback.
 *   - `recall()` matches by route key and, failing an exact region match, falls
 *     back to the country-wide snapshot for the same weight band — so a customer
 *     in a region we never pre-warmed still gets *a* real price rather than none.
 */
class ShippingRateSnapshotStore
{
    public function __construct(
        private readonly ShippingRateSnapshotRepository $repository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Upserts the snapshot for a route. An empty rate set is ignored (never
     * overwrites a previously stored good set); the caller decides what to log.
     *
     * @param array<int, array<string, mixed>> $rates
     *
     * @return bool true if a snapshot was written, false if skipped (empty)
     */
    public function remember(string $country, ?string $region, float $weight, array $rates): bool
    {
        if ($rates === []) {
            return false;
        }

        $band     = ShippingRouteKey::band($weight);
        $routeKey = ShippingRouteKey::for($country, $region, $weight);

        $snapshot = $this->repository->findOneByRouteKey($routeKey) ?? new ShippingRateSnapshot();
        $snapshot
            ->setRouteKey($routeKey)
            ->setCountry(strtoupper(trim($country)))
            ->setRegion($region !== null && trim($region) !== '' ? strtoupper(trim($region)) : null)
            ->setWeightBand($band)
            ->setRates($rates);

        $this->em->persist($snapshot);
        $this->em->flush();

        return true;
    }

    /**
     * The freshest known-good snapshot for a lookup, or null if none applies.
     *
     * Tries the exact region first, then the country-wide ("*" region) snapshot
     * for the same weight band.
     */
    public function recall(string $country, ?string $region, float $weight): ?ShippingRateSnapshot
    {
        $exact = $this->repository->findOneByRouteKey(ShippingRouteKey::for($country, $region, $weight));
        if ($exact !== null) {
            return $exact;
        }

        if ($region !== null && trim($region) !== '') {
            return $this->repository->findOneByRouteKey(ShippingRouteKey::for($country, null, $weight));
        }

        return null;
    }
}
