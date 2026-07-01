<?php

namespace App\Repository;

use App\Entity\Article;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Article>
 */
class ArticleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Article::class);
    }

    public function findPaginated(int $limit, int $offset, ?string $categorySlug = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if ($categorySlug !== null) {
            $qb->join('a.category', 'c')
               ->andWhere('c.slug = :slug')
               ->setParameter('slug', $categorySlug);
        }

        return $qb->getQuery()->getResult();
    }

    public function countAll(?string $categorySlug = null): int
    {
        $qb = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)');

        if ($categorySlug !== null) {
            $qb->join('a.category', 'c')
               ->andWhere('c.slug = :slug')
               ->setParameter('slug', $categorySlug);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
