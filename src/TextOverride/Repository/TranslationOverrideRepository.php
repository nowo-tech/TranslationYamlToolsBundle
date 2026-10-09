<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\TextOverride\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\TranslationYamlToolsBundle\TextOverride\Entity\TranslationOverride;

/**
 * @extends ServiceEntityRepository<TranslationOverride>
 *
 * Not final so unit tests can mock it. The entity manager is resolved per call (and a closed one
 * replaced) so the repository stays usable in FrankenPHP worker mode.
 */
class TranslationOverrideRepository extends ServiceEntityRepository
{
    private readonly ManagerRegistry $managerRegistry;

    public function __construct(ManagerRegistry $registry)
    {
        $this->managerRegistry = $registry;
        parent::__construct($registry, TranslationOverride::class);
    }

    /**
     * @return array<string, array<string, array<string, string>>> locale => domain => key => text
     */
    public function loadMap(): array
    {
        /** @var list<array{locale: string, domain: string, messageKey: string, value: string}> $rows */
        $rows = $this->entityManager()->createQueryBuilder()
            ->select('o.locale', 'o.domain', 'o.messageKey', 'o.value')
            ->from(TranslationOverride::class, 'o')
            ->getQuery()
            ->getArrayResult();

        $map = [];
        foreach ($rows as $row) {
            $map[$row['locale']][$row['domain']][$row['messageKey']] = $row['value'];
        }

        return $map;
    }

    public function findOne(string $locale, string $domain, string $key): ?TranslationOverride
    {
        $row = $this->entityManager()->createQueryBuilder()
            ->select('o')
            ->from(TranslationOverride::class, 'o')
            ->andWhere('o.locale = :locale')
            ->andWhere('o.domain = :domain')
            ->andWhere('o.messageKey = :key')
            ->setParameter('locale', $locale)
            ->setParameter('domain', $domain)
            ->setParameter('key', $key)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $row instanceof TranslationOverride ? $row : null;
    }

    public function persist(TranslationOverride $override): void
    {
        $this->entityManager()->persist($override);
    }

    public function delete(TranslationOverride $override): void
    {
        $this->entityManager()->remove($override);
    }

    public function flush(): void
    {
        $this->entityManager()->flush();
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = $this->managerRegistry->getManagerForClass(TranslationOverride::class);
        if (!$em instanceof EntityManagerInterface) {
            throw new LogicException('No Doctrine ORM entity manager manages ' . TranslationOverride::class . '.');
        }
        if (!$em->isOpen()) {
            $this->managerRegistry->resetManager();
            $em = $this->managerRegistry->getManagerForClass(TranslationOverride::class);
            if (!$em instanceof EntityManagerInterface) {
                throw new LogicException('No Doctrine ORM entity manager manages ' . TranslationOverride::class . '.');
            }
        }

        return $em;
    }
}
