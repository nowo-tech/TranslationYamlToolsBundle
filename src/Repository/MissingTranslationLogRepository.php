<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Repository;

use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
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

        $em         = $this->getEntityManager();
        $connection = $em->getConnection();
        $now        = new DateTimeImmutable();
        $tableName  = $em->getClassMetadata(MissingTranslationLog::class)->getTableName();
        $qTableName = $connection->quoteIdentifier($tableName);

        foreach ($buffer as $row) {
            $messageId = $row['messageId'];
            $domain    = $row['domain'];
            $locale    = $row['locale'];
            $hits      = $row['hits'];
            $callSite  = $this->normalizeCallSite($row['callSite'] ?? null);
            $seenAt    = $now->format('Y-m-d H:i:s');

            try {
                $connection->insert($tableName, [
                    'message_id'        => $messageId,
                    'domain'            => $domain,
                    'locale'            => $locale,
                    'status'            => MissingTranslationLogStatus::Pending->value,
                    'hit_count'         => $hits,
                    'first_seen_at'     => $seenAt,
                    'last_seen_at'      => $seenAt,
                    'status_changed_at' => null,
                    'notes'             => null,
                    'call_site'         => $callSite,
                ], [
                    'message_id'        => ParameterType::STRING,
                    'domain'            => ParameterType::STRING,
                    'locale'            => ParameterType::STRING,
                    'status'            => ParameterType::STRING,
                    'hit_count'         => ParameterType::INTEGER,
                    'first_seen_at'     => ParameterType::STRING,
                    'last_seen_at'      => ParameterType::STRING,
                    'status_changed_at' => ParameterType::NULL,
                    'notes'             => ParameterType::NULL,
                    'call_site'         => $callSite === null ? ParameterType::NULL : ParameterType::STRING,
                ]);
            } catch (UniqueConstraintViolationException) {
                $sql    = "UPDATE $qTableName SET hit_count = hit_count + :hits, last_seen_at = :lastSeenAt";
                $params = [
                    'hits'       => $hits,
                    'lastSeenAt' => $seenAt,
                    'messageId'  => $messageId,
                    'domain'     => $domain,
                    'locale'     => $locale,
                ];
                $types  = [
                    'hits'       => ParameterType::INTEGER,
                    'lastSeenAt' => ParameterType::STRING,
                    'messageId'  => ParameterType::STRING,
                    'domain'     => ParameterType::STRING,
                    'locale'     => ParameterType::STRING,
                ];

                if ($callSite !== null) {
                    $sql               .= ', call_site = :callSite';
                    $params['callSite'] = $callSite;
                    $types['callSite']  = ParameterType::STRING;
                }

                $sql .= ' WHERE message_id = :messageId AND domain = :domain AND locale = :locale';
                $connection->executeStatement($sql, $params, $types);
            }
        }
    }

    private function normalizeCallSite(?string $callSite): ?string
    {
        if ($callSite === null || $callSite === '') {
            return null;
        }

        return strlen($callSite) <= 1024 ? $callSite : substr($callSite, 0, 1021) . '...';
    }
}
