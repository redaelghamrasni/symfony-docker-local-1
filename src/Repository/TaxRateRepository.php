<?php

namespace App\Repository;

use App\Entity\TaxRate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TaxRate>
 */
class TaxRateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TaxRate::class);
    }

    /** @return TaxRate[] */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('t')
            ->orderBy('t.province', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByProvince(string $province): ?TaxRate
    {
        return $this->createQueryBuilder('t')
            ->where('t.province = :p')
            ->setParameter('p', strtoupper($province))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
