<?php

namespace App\Repository;

use App\Entity\Cart;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Cart>
 */
class CartRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Cart::class);
    }

    /**
     * Find cart by ID
     */
    public function findOneById(int $id): ?Cart
    {
        return $this->find($id);
    }

    /**
     * Find active carts (created in last 30 days)
     */
    public function findActiveCarts(): array
    {
        $thirtyDaysAgo = new \DateTimeImmutable('-30 days');

        return $this->createQueryBuilder('c')
            ->where('c.createdAt >= :date')
            ->setParameter('date', $thirtyDaysAgo)
            ->orderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count total items in cart
     */
    public function countItems(Cart $cart): int
    {
        return $this->createQueryBuilder('c')
            ->select('SUM(ci.quantity)')
            ->leftJoin('c.items', 'ci')
            ->where('c.id = :cartId')
            ->setParameter('cartId', $cart->getId())
            ->getQuery()
            ->getSingleScalarResult() ?? 0;
    }
}