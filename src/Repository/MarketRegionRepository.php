<?php

namespace App\Repository;

use App\Entity\MarketRegion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MarketRegion>
 */
class MarketRegionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketRegion::class);
    }

    /** @return MarketRegion[] all rows, ordered for the admin list */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('r')
            ->orderBy('r.country', 'ASC')
            ->addOrderBy('r.position', 'ASC')
            ->addOrderBy('r.code', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return MarketRegion[] overrides for one country, in display order */
    public function findForCountry(string $country): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.country = :c')
            ->setParameter('c', strtoupper($country))
            ->orderBy('r.position', 'ASC')
            ->addOrderBy('r.code', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Distinct country codes that have at least one override. @return string[] */
    public function countriesWithOverrides(): array
    {
        $rows = $this->createQueryBuilder('r')
            ->select('DISTINCT r.country AS country')
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $row) => $row['country'], $rows);
    }
}
