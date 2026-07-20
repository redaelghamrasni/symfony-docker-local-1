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

    /**
     * Fetches categories by id, preserving the given order (e.g. Meilisearch relevance ranking).
     *
     * @param int[] $ids
     * @return Category[]
     */
    public function findByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $categories = $this->createQueryBuilder('c')
            ->andWhere('c.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($categories as $category) {
            $byId[$category->getId()] = $category;
        }

        return array_values(array_filter(array_map(
            static fn (int $id) => $byId[$id] ?? null,
            $ids
        )));
    }
}
