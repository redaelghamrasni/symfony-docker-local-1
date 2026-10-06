<?php

namespace App\Repository;

use App\Entity\Order;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Order>
 */
class OrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Order::class);
    }

    public function findOneByStripePaymentIntentId(string $paymentIntentId): ?Order
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.stripePaymentIntentId = :pi')
            ->setParameter('pi', $paymentIntentId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Atomically claims a pending order for finalization: flips pending → paid
     * in a single conditional UPDATE and reports whether THIS call won the race.
     *
     * This is the hard idempotency guard the webhook relies on. When the webhook
     * and the browser return (or two webhook deliveries) both reach an order, the
     * database lets exactly one UPDATE match `status = 'pending'`; every other
     * caller sees 0 affected rows and must treat the order as already finalized.
     * The caller refreshes the managed entity afterwards — a bulk DQL UPDATE does
     * not touch the identity map.
     */
    public function markPaidIfPending(int $id): bool
    {
        $affected = $this->getEntityManager()
            ->createQuery(
                'UPDATE App\Entity\Order o
                    SET o.status = :paid, o.updatedAt = :now
                  WHERE o.id = :id AND o.status = :pending'
            )
            ->setParameter('paid', 'paid')
            ->setParameter('pending', 'pending')
            ->setParameter('id', $id)
            ->setParameter('now', new \DateTimeImmutable('now', new \DateTimeZone('America/Toronto')))
            ->execute();

        return $affected === 1;
    }

    /**
     * Orders still `pending` and untouched since before the given cutoff — the
     * purge command's candidates for reconciliation against Stripe.
     *
     * @return Order[]
     */
    public function findPendingOlderThan(\DateTimeImmutable $before): array
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.status = :pending')
            ->setParameter('pending', 'pending')
            ->andWhere('o.updatedAt < :before')
            ->setParameter('before', $before)
            ->orderBy('o.updatedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findLastByUser(User $user): ?Order
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.user = :user')
            ->setParameter('user', $user)
            ->orderBy('o.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByUserOrdered($user)
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.user = :user')
            ->setParameter('user', $user)
            ->orderBy('o.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findForAdmin(?string $status = null): array
    {
        $qb = $this->createQueryBuilder('o')
            ->leftJoin('o.user', 'u')
            ->addSelect('u')
            ->orderBy('o.createdAt', 'DESC');

        if ($status) {
            $qb->andWhere('o.status = :status')->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }

    public function countByStatus(): array
    {
        $rows = $this->createQueryBuilder('o')
            ->select('o.status, COUNT(o.id) as cnt')
            ->groupBy('o.status')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['status']] = (int) $row['cnt'];
        }
        return $counts;
    }

    /**
     * Fetches orders by id, preserving the given order (e.g. Meilisearch relevance ranking).
     *
     * @param int[] $ids
     * @return Order[]
     */
    public function findByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $orders = $this->createQueryBuilder('o')
            ->leftJoin('o.user', 'u')
            ->addSelect('u')
            ->andWhere('o.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($orders as $order) {
            $byId[$order->getId()] = $order;
        }

        return array_values(array_filter(array_map(
            static fn (int $id) => $byId[$id] ?? null,
            $ids
        )));
    }
}
