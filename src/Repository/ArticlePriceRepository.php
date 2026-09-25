<?php

namespace App\Repository;

use App\Entity\ArticlePrice;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ArticlePrice>
 */
class ArticlePriceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ArticlePrice::class);
    }

    /**
     * How many articles carry a hand-set price in each currency, so the admin
     * can see coverage rather than guess which currencies are still running on
     * converted amounts.
     *
     * @return array<string, int> currency code => number of priced articles
     */
    public function countByCurrency(): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('c.code AS code, COUNT(p.id) AS total')
            ->join('p.currency', 'c')
            ->groupBy('c.code')
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'total', 'code');
    }
}
