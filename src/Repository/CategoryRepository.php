<?php

namespace App\Repository;

use App\Entity\Category;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Category>
 */
class CategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    /**
     * Returns [categoryId => articleCount] for all categories.
     * Uses a scalar COUNT query so the result is safely cacheable.
     */
    public function findAllWithArticleCounts(): array
    {
        $rows = $this->createQueryBuilder('c')
            ->select('c.id', 'COUNT(a.id) AS cnt')
            ->leftJoin('c.articles', 'a')
            ->groupBy('c.id')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['id']] = (int) $row['cnt'];
        }
        return $counts;
    }
}
