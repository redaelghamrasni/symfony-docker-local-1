<?php

namespace App\Repository;

use App\Entity\Quote;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Quote>
 */
class QuoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Quote::class);
    }

    /**
     * The quote carrying a given provider payment reference — the converter's
     * primary lookup when a payment settles (e.g. Stripe payment_intent.succeeded
     * or a PayPal capture). Provider-neutral by design: the caller passes the
     * provider name and its opaque reference, so no concrete provider is baked
     * into the query. A provider-specific fallback (e.g. a quote id written into
     * Stripe PI metadata) is resolved by the adapter, not here.
     */
    public function findOneByPaymentReference(string $provider, string $reference): ?Quote
    {
        return $this->createQueryBuilder('q')
            ->andWhere('q.paymentProvider = :provider')
            ->andWhere('q.paymentReference = :reference')
            ->setParameter('provider', $provider)
            ->setParameter('reference', $reference)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Atomically records the conversion of a quote: sets convertedOrderId (and
     * status/timestamps) in a single conditional UPDATE and reports whether THIS
     * call won. This is the hard idempotency guard the converter relies on —
     * when the webhook and the browser return (or two webhook deliveries) both
     * convert the same quote, the database lets exactly one UPDATE match
     * `converted_order_id IS NULL`; every other caller sees 0 affected rows and
     * must discard the order it built and return the already-recorded one. A bulk
     * DQL UPDATE does not touch the identity map, so the caller refreshes the
     * managed quote afterwards.
     */
    public function markConvertedIfNot(int $quoteId, int $orderId): bool
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('America/Toronto'));

        $affected = $this->getEntityManager()
            ->createQuery(
                'UPDATE App\Entity\Quote q
                    SET q.status = :converted, q.convertedOrderId = :oid,
                        q.convertedAt = :now, q.updatedAt = :now
                  WHERE q.id = :id AND q.convertedOrderId IS NULL'
            )
            ->setParameter('converted', Quote::STATUS_CONVERTED)
            ->setParameter('oid', $orderId)
            ->setParameter('now', $now)
            ->setParameter('id', $quoteId)
            ->execute();

        return $affected === 1;
    }

    /**
     * All quotes for the back-office list, newest first, optionally filtered by
     * lifecycle status. Mirrors OrderRepository::findForAdmin so the admin Quotes
     * section reads the same way as Orders.
     *
     * @return Quote[]
     */
    public function findForAdmin(?string $status = null): array
    {
        $qb = $this->createQueryBuilder('q')
            ->leftJoin('q.user', 'u')
            ->addSelect('u')
            ->orderBy('q.createdAt', 'DESC');

        if ($status) {
            $qb->andWhere('q.status = :status')->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Quote counts per lifecycle status, for the filter tabs and conversion
     * tracking. @return array<string,int>
     */
    public function countByStatus(): array
    {
        $rows = $this->createQueryBuilder('q')
            ->select('q.status AS status, COUNT(q.id) AS cnt')
            ->groupBy('q.status')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['status']] = (int) $row['cnt'];
        }

        return $counts;
    }

    /**
     * Unconverted quotes (draft/ready) untouched since before the cutoff — the
     * purge command's candidates for abandonment. A quote must be re-checked
     * against Stripe before being marked abandoned (never abandon one whose PI
     * actually succeeded), so this only narrows the field.
     *
     * @return Quote[]
     */
    public function findStaleUnconvertedOlderThan(\DateTimeImmutable $before): array
    {
        return $this->createQueryBuilder('q')
            ->andWhere('q.status IN (:open)')
            ->setParameter('open', [Quote::STATUS_DRAFT, Quote::STATUS_READY])
            ->andWhere('q.updatedAt < :before')
            ->setParameter('before', $before)
            ->orderBy('q.updatedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
