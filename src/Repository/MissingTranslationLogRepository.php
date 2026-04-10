<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Repository;

use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TranslationYamlToolsBundle\Entity\MissingTranslationLog;
use Nowo\TranslationYamlToolsBundle\Entity\MissingTranslationLogStatus;

/**
 * @extends ServiceEntityRepository<MissingTranslationLog>
 *
 * Not final so unit tests can mock {@see persistBuffer} via PHPUnit.
 */
class MissingTranslationLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MissingTranslationLog::class);
    }

    /**
     * @return list<MissingTranslationLog>
     */
    public function findByStatus(MissingTranslationLogStatus $status, int $limit = 500): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.status = :status')
            ->setParameter('status', $status)
            ->orderBy('l.lastSeenAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findOneById(int $id): ?MissingTranslationLog
    {
        return $this->find($id);
    }

    /**
     * @param array<string, array{hits: int, messageId: string, domain: string, locale: string, callSite?: ?string}> $buffer keyed by stable hash
     */
    public function persistBuffer(array $buffer): void
    {
        if ($buffer === []) {
            return;
        }

        $em  = $this->getEntityManager();
        $now = new DateTimeImmutable();

        foreach ($buffer as $row) {
            $messageId = $row['messageId'];
            $domain    = $row['domain'];
            $locale    = $row['locale'];
            $hits      = $row['hits'];
            $callSite  = $row['callSite'] ?? null;

            $existing = $this->findOneBy([
                'messageId' => $messageId,
                'domain'    => $domain,
                'locale'    => $locale,
            ]);

            if ($existing instanceof MissingTranslationLog) {
                $existing->registerAdditionalHits($hits, $now, $callSite);
                continue;
            }

            $entity = new MissingTranslationLog($messageId, $domain, $locale, $now, $callSite);
            if ($hits > 1) {
                $entity->registerAdditionalHits($hits - 1, $now, null);
            }
            $em->persist($entity);
        }

        $em->flush();
    }
}
