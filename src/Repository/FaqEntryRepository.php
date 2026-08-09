<?php

namespace App\Repository;

use App\Entity\FaqEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FaqEntry>
 */
class FaqEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FaqEntry::class);
    }

    /**
     * @return array<string, FaqEntry[]> FAQ entries for the given locale, grouped by category
     */
    public function findByLocaleGroupedByCategory(string $locale): array
    {
        $entries = $this->createQueryBuilder('f')
            ->andWhere('f.locale = :locale')
            ->setParameter('locale', $locale)
            ->orderBy('f.id', 'ASC')
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($entries as $entry) {
            $grouped[$entry->getCategory()][] = $entry;
        }

        return $grouped;
    }

    /**
     * @return array<string, array<string, FaqEntry>> All entries grouped by group key, then keyed by locale
     */
    public function findAllGroupedByGroupKey(): array
    {
        $entries = $this->createQueryBuilder('f')
            ->orderBy('f.groupKey', 'ASC')
            ->addOrderBy('f.locale', 'ASC')
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($entries as $entry) {
            $grouped[$entry->getGroupKey()][$entry->getLocale()] = $entry;
        }

        return $grouped;
    }

    /**
     * @return array{fr: ?FaqEntry, en: ?FaqEntry}
     */
    public function findByGroupKey(string $groupKey): array
    {
        $result = ['fr' => null, 'en' => null];
        foreach ($this->findBy(['groupKey' => $groupKey]) as $entry) {
            $result[$entry->getLocale()] = $entry;
        }

        return $result;
    }
}
