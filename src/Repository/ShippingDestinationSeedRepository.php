<?php

namespace App\Repository;

use App\Entity\ShippingDestinationSeed;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ShippingDestinationSeed>
 */
class ShippingDestinationSeedRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShippingDestinationSeed::class);
    }

    /**
     * Active seed destinations as plain rows, in the same shape the refresh uses
     * for order-history destinations so the two merge cleanly.
     *
     * @return list<array{country: string, region: ?string, city: string, postalCode: string, phone: ?string}>
     */
    public function findActiveDestinations(): array
    {
        $seeds = $this->findBy(['active' => true]);

        return array_map(static fn (ShippingDestinationSeed $s): array => [
            'country'    => $s->getCountry(),
            'region'     => $s->getRegion(),
            'city'       => $s->getCity(),
            'postalCode' => $s->getPostalCode(),
            'phone'      => $s->getPhone(),
        ], $seeds);
    }
}
