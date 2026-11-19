<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\TextOverride\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TranslationYamlToolsBundle\Concurrency\TranslationYamlToolsWriteUnit;
use Nowo\TranslationYamlToolsBundle\Doctrine\TranslationYamlToolsManagerRegistry;
use Nowo\TranslationYamlToolsBundle\TextOverride\Entity\TranslationOverride;

/**
 * @extends ServiceEntityRepository<TranslationOverride>
 *
 * Not final so unit tests can mock it. The entity manager is resolved per call (and a closed one
 * replaced) so the repository stays usable in FrankenPHP worker mode.
 *
 * Since 1.18.0 the registry it gets is {@see TranslationYamlToolsManagerRegistry}
 * by default, so the manager is the bundle's private one: {@see self::flush()} never flushes the application's manager.
 */
class TranslationOverrideRepository extends ServiceEntityRepository
{
    private readonly ManagerRegistry $managerRegistry;

    /**
     * @param string|null $managerName `doctrine.entity_manager` (1.19: tells a dedicated manager from the default one
     *                                 without building every manager of the registry)
     */
    public function __construct(ManagerRegistry $registry, private readonly ?string $managerName = null)
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

    /**
     * Writes the pending override rows as one write unit (1.19, REQ-RUNTIME-003 rule 1: {@see self::transactional()}).
     *
     * @throws TransientStorageFailure when the storage failed transiently (deadlock, lock wait timeout, serialization)
     */
    public function flush(): void
    {
        $this->transactional(static fn (): null => null);
    }

    /**
     * Runs `$work` (reads, {@see self::persist()}, {@see self::delete()}, entity changes) and the flush of the manager
     * that maps the overrides as one write unit (1.19, REQ-RUNTIME-003 rules 1–3, {@see TranslationYamlToolsWriteUnit}):
     * one transaction (a savepoint inside yours), the nesting level repaired after a failure, the bundle's own manager
     * cleaned up after a storage failure, the application's manager never cleared or reset while open.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @throws TransientStorageFailure when the storage failed transiently (the DBAL error as previous)
     *
     * @return T
     */
    public function transactional(callable $work): mixed
    {
        return TranslationYamlToolsWriteUnit::run($this->entityManager(), $this->managerRegistry, $work, true, $this->managerName);
    }

    /**
     * Whether the overrides live on the bundle's own manager (private or dedicated) and it has no pending change
     * (1.19, audit UQ-1): only then may {@see TranslationOverrides::write()} run a unit again after the first-save
     * race, since the failed unit cleared or replaced that manager.
     */
    public function isCleanBundleManager(): bool
    {
        return TranslationYamlToolsWriteUnit::isCleanBundleManager($this->entityManager(), $this->managerRegistry, $this->managerName);
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = $this->managerRegistry->getManagerForClass(TranslationOverride::class);
        if (!$em instanceof EntityManagerInterface) {
            throw new LogicException('No Doctrine ORM entity manager manages ' . TranslationOverride::class . '.');
        }
        if (!$em->isOpen()) {
            // 1.17.0: reset this manager by name; resetManager() without a name reset the host's default manager.
            $this->resetManager($em);
            $em = $this->managerRegistry->getManagerForClass(TranslationOverride::class);
            if (!$em instanceof EntityManagerInterface) {
                throw new LogicException('No Doctrine ORM entity manager manages ' . TranslationOverride::class . '.');
            }
        }

        return $em;
    }

    private function resetManager(EntityManagerInterface $closed): void
    {
        foreach ($this->managerRegistry->getManagers() as $name => $manager) {
            if ($manager === $closed) {
                $this->managerRegistry->resetManager($name);

                return;
            }
        }
    }
}
