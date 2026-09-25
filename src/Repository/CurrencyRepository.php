<?php

namespace App\Repository;

use App\Entity\Currency;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Currency>
 */
class CurrencyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Currency::class);
    }

    /** @return Currency[] */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('c')
            ->orderBy('c.position', 'ASC')
            ->addOrderBy('c.code', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Currencies offered to visitors. The default is always included even if
     * someone unticks "enabled" on it — the shop must always have one usable
     * currency, and prices are authored in this one.
     *
     * @return Currency[]
     */
    public function findEnabled(): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.enabled = true OR c.isDefault = true')
            ->orderBy('c.position', 'ASC')
            ->addOrderBy('c.code', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findDefault(): ?Currency
    {
        return $this->createQueryBuilder('c')
            ->where('c.isDefault = true')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByCode(string $code): ?Currency
    {
        return $this->createQueryBuilder('c')
            ->where('c.code = :code')
            ->setParameter('code', strtoupper($code))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Makes one currency the default and clears the flag everywhere else, in a
     * single transaction — two defaults would make "convert from the default"
     * ambiguous and silently mis-price the catalogue.
     */
    public function makeDefault(Currency $currency): void
    {
        $em = $this->getEntityManager();

        $em->wrapInTransaction(function () use ($em, $currency): void {
            $em->createQuery('UPDATE App\Entity\Currency c SET c.isDefault = false')->execute();

            $currency->setIsDefault(true);
            // The default must be usable, and its rate is the reference point.
            $currency->setEnabled(true);
            $currency->setExchangeRate('1.000000');

            $em->persist($currency);
            $em->flush();
        });
    }
}
