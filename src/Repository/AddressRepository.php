<?php

namespace App\Repository;

use App\Entity\Address;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Address>
 */
class AddressRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Address::class);
    }

    public function findDefaultByUser(int $userId): ?Address
    {
        return $this->createQueryBuilder('a')
            ->where('a.user = :userId AND a.is_default = true')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findDefaultShippingByUser(int $userId): ?Address
    {
        return $this->createQueryBuilder('a')
            ->where('a.user = :userId AND a.is_default = true AND a.type = :type')
            ->setParameter('userId', $userId)
            ->setParameter('type', Address::TYPE_SHIPPING)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
