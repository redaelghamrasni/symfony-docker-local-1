<?php

namespace App\Repository;

use App\Entity\ShippingRateSnapshot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ShippingRateSnapshot>
 */
class ShippingRateSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShippingRateSnapshot::class);
    }

    public function findOneByRouteKey(string $routeKey): ?ShippingRateSnapshot
    {
        return $this->findOneBy(['routeKey' => $routeKey]);
    }
}
