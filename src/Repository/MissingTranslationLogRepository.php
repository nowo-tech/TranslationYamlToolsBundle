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

use function strlen;

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

    public function clearAll(): int
    {
        $em         = $this->getEntityManager();
        $connection = $em->getConnection();
        $meta       = $em->getClassMetadata(MissingTranslationLog::class);
        $tableName  = $meta->getTableName();
        $qTableName = $connection->quoteIdentifier($tableName);

        return $connection->executeStatement("DELETE FROM {$qTableName}");
    }

    public function clearByStatus(MissingTranslationLogStatus $status): int
    {
        $em         = $this->getEntityManager();
        $connection = $em->getConnection();
        $meta       = $em->getClassMetadata(MissingTranslationLog::class);
        $tableName  = $meta->getTableName();
        $cStatus    = $meta->getColumnName('status');
        $qTableName = $connection->quoteIdentifier($tableName);
        $qStatus    = $connection->quoteIdentifier($cStatus);

        return $connection->executeStatement(
            "DELETE FROM {$qTableName} WHERE {$qStatus} = :status",
            ['status' => $status->value],
            ['status' => ParameterType::STRING],
        );
    }

    /**
     * @param array<string, array{hits: int, messageId: string, domain: string, locale: string, callSite?: ?string, requestRoute?: ?string, requestMethod?: ?string, requestPath?: ?string}> $buffer keyed by stable hash
     */
    public function persistBuffer(array $buffer): void
    {
        if ($buffer === []) {
            return;
        }

        $em         = $this->getEntityManager();
        $connection = $em->getConnection();
        $now        = new DateTimeImmutable();
        $meta       = $em->getClassMetadata(MissingTranslationLog::class);
        $tableName  = $meta->getTableName();
        $qTableName = $connection->quoteIdentifier($tableName);

        $cMessageId       = $meta->getColumnName('messageId');
        $cDomain          = $meta->getColumnName('domain');
        $cLocale          = $meta->getColumnName('locale');
        $cStatus          = $meta->getColumnName('status');
        $cHitCount        = $meta->getColumnName('hitCount');
        $cFirstSeenAt     = $meta->getColumnName('firstSeenAt');
        $cLastSeenAt      = $meta->getColumnName('lastSeenAt');
        $cStatusChangedAt = $meta->getColumnName('statusChangedAt');
        $cNotes           = $meta->getColumnName('notes');
        $cCallSite        = $meta->getColumnName('callSite');
        $cRequestRoute    = $meta->getColumnName('requestRoute');
        $cRequestMethod   = $meta->getColumnName('requestMethod');
        $cRequestPath     = $meta->getColumnName('requestPath');

        $qMessageId       = $connection->quoteIdentifier($cMessageId);
        $qDomain          = $connection->quoteIdentifier($cDomain);
        $qLocale          = $connection->quoteIdentifier($cLocale);
        $qHitCount        = $connection->quoteIdentifier($cHitCount);
        $qLastSeenAt      = $connection->quoteIdentifier($cLastSeenAt);
        $qCallSite        = $connection->quoteIdentifier($cCallSite);
        $qRequestRoute    = $connection->quoteIdentifier($cRequestRoute);
        $qRequestMethod   = $connection->quoteIdentifier($cRequestMethod);
        $qRequestPath     = $connection->quoteIdentifier($cRequestPath);

        foreach ($buffer as $row) {
            $messageId     = $row['messageId'];
            $domain        = $row['domain'];
            $locale        = $row['locale'];
            $hits          = $row['hits'];
            $callSite      = $this->normalizeCallSite($row['callSite'] ?? null);
            $requestRoute  = $this->normalizeRequestRoute($row['requestRoute'] ?? null);
            $requestMethod = $this->normalizeRequestMethod($row['requestMethod'] ?? null);
            $requestPath   = $this->normalizeRequestPath($row['requestPath'] ?? null);
            $seenAt        = $now->format('Y-m-d H:i:s');

            try {
                $connection->insert($tableName, [
                    $cMessageId       => $messageId,
                    $cDomain          => $domain,
                    $cLocale          => $locale,
                    $cStatus          => MissingTranslationLogStatus::Pending->value,
                    $cHitCount        => $hits,
                    $cFirstSeenAt     => $seenAt,
                    $cLastSeenAt      => $seenAt,
                    $cStatusChangedAt => null,
                    $cNotes           => null,
                    $cCallSite        => $callSite,
                    $cRequestRoute    => $requestRoute,
                    $cRequestMethod   => $requestMethod,
                    $cRequestPath     => $requestPath,
                ], [
                    $cMessageId       => ParameterType::STRING,
                    $cDomain          => ParameterType::STRING,
                    $cLocale          => ParameterType::STRING,
                    $cStatus          => ParameterType::STRING,
                    $cHitCount        => ParameterType::INTEGER,
                    $cFirstSeenAt     => ParameterType::STRING,
                    $cLastSeenAt      => ParameterType::STRING,
                    $cStatusChangedAt => ParameterType::NULL,
                    $cNotes           => ParameterType::NULL,
                    $cCallSite        => $callSite === null ? ParameterType::NULL : ParameterType::STRING,
                    $cRequestRoute    => $requestRoute === null ? ParameterType::NULL : ParameterType::STRING,
                    $cRequestMethod   => $requestMethod === null ? ParameterType::NULL : ParameterType::STRING,
                    $cRequestPath     => $requestPath === null ? ParameterType::NULL : ParameterType::STRING,
                ]);
            } catch (UniqueConstraintViolationException) {
                $sql    = "UPDATE {$qTableName} SET {$qHitCount} = {$qHitCount} + :hits, {$qLastSeenAt} = :lastSeenAt";
                $params = [
                    'hits'       => $hits,
                    'lastSeenAt' => $seenAt,
                    'messageId'  => $messageId,
                    'domain'     => $domain,
                    'locale'     => $locale,
                ];
                $types = [
                    'hits'       => ParameterType::INTEGER,
                    'lastSeenAt' => ParameterType::STRING,
                    'messageId'  => ParameterType::STRING,
                    'domain'     => ParameterType::STRING,
                    'locale'     => ParameterType::STRING,
                ];

                if ($callSite !== null) {
                    $sql .= ", {$qCallSite} = :callSite";
                    $params['callSite'] = $callSite;
                    $types['callSite']  = ParameterType::STRING;
                }
                if ($requestRoute !== null) {
                    $sql .= ", {$qRequestRoute} = :requestRoute";
                    $params['requestRoute'] = $requestRoute;
                    $types['requestRoute']  = ParameterType::STRING;
                }
                if ($requestMethod !== null) {
                    $sql .= ", {$qRequestMethod} = :requestMethod";
                    $params['requestMethod'] = $requestMethod;
                    $types['requestMethod']  = ParameterType::STRING;
                }
                if ($requestPath !== null) {
                    $sql .= ", {$qRequestPath} = :requestPath";
                    $params['requestPath'] = $requestPath;
                    $types['requestPath']  = ParameterType::STRING;
                }

                $sql .= " WHERE {$qMessageId} = :messageId AND {$qDomain} = :domain AND {$qLocale} = :locale";
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

    private function normalizeRequestRoute(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return strlen($value) <= 180 ? $value : substr($value, 0, 177) . '...';
    }

    private function normalizeRequestMethod(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return strlen($value) <= 8 ? $value : substr($value, 0, 8);
    }

    private function normalizeRequestPath(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return strlen($value) <= 2048 ? $value : substr($value, 0, 2045) . '...';
    }
}
