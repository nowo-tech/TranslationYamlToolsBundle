<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Repository;

use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
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
        $qTableName = $connection->quoteSingleIdentifier($tableName);

        return (int) $connection->executeStatement("DELETE FROM {$qTableName}");
    }

    public function clearByStatus(MissingTranslationLogStatus $status): int
    {
        $em         = $this->getEntityManager();
        $connection = $em->getConnection();
        $meta       = $em->getClassMetadata(MissingTranslationLog::class);
        $tableName  = $meta->getTableName();
        $cStatus    = $meta->getColumnName('status');
        $qTableName = $connection->quoteSingleIdentifier($tableName);
        $qStatus    = $connection->quoteSingleIdentifier($cStatus);

        return (int) $connection->executeStatement(
            "DELETE FROM {$qTableName} WHERE {$qStatus} = :status",
            ['status' => $status->value],
            ['status' => ParameterType::STRING],
        );
    }

    public function flush(): void
    {
        $this->getEntityManager()->flush();
    }

    public function clearManaged(): void
    {
        $this->getEntityManager()->clear();
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
        $platform   = $connection->getDatabasePlatform();
        $now        = new DateTimeImmutable();
        $meta       = $em->getClassMetadata(MissingTranslationLog::class);
        $tableName  = $meta->getTableName();

        $columns = [
            'messageId'       => $meta->getColumnName('messageId'),
            'domain'          => $meta->getColumnName('domain'),
            'locale'          => $meta->getColumnName('locale'),
            'status'          => $meta->getColumnName('status'),
            'hitCount'        => $meta->getColumnName('hitCount'),
            'firstSeenAt'     => $meta->getColumnName('firstSeenAt'),
            'lastSeenAt'      => $meta->getColumnName('lastSeenAt'),
            'statusChangedAt' => $meta->getColumnName('statusChangedAt'),
            'notes'           => $meta->getColumnName('notes'),
            'callSite'        => $meta->getColumnName('callSite'),
            'requestRoute'    => $meta->getColumnName('requestRoute'),
            'requestMethod'   => $meta->getColumnName('requestMethod'),
            'requestPath'     => $meta->getColumnName('requestPath'),
        ];

        foreach ($buffer as $row) {
            $this->upsertBufferRow(
                $connection,
                $platform,
                $tableName,
                $columns,
                [
                    'messageId'     => $this->normalizeMessageId($row['messageId']),
                    'domain'        => $this->normalizeDomain($row['domain']),
                    'locale'        => $this->normalizeLocale($row['locale']),
                    'hits'          => $row['hits'],
                    'callSite'      => $this->normalizeCallSite($row['callSite'] ?? null),
                    'requestRoute'  => $this->normalizeRequestRoute($row['requestRoute'] ?? null),
                    'requestMethod' => $this->normalizeRequestMethod($row['requestMethod'] ?? null),
                    'requestPath'   => $this->normalizeRequestPath($row['requestPath'] ?? null),
                    'seenAt'        => $now->format('Y-m-d H:i:s'),
                ],
            );
        }
    }

    /**
     * @param array{
     *     messageId: string,
     *     domain: string,
     *     locale: string,
     *     status: string,
     *     hitCount: string,
     *     firstSeenAt: string,
     *     lastSeenAt: string,
     *     statusChangedAt: string,
     *     notes: string,
     *     callSite: string,
     *     requestRoute: string,
     *     requestMethod: string,
     *     requestPath: string
     * } $columns physical column names from Doctrine metadata
     * @param array{
     *     messageId: string,
     *     domain: string,
     *     locale: string,
     *     hits: int,
     *     callSite: ?string,
     *     requestRoute: ?string,
     *     requestMethod: ?string,
     *     requestPath: ?string,
     *     seenAt: string
     * } $row
     */
    private function upsertBufferRow(
        Connection $connection,
        AbstractPlatform $platform,
        string $tableName,
        array $columns,
        array $row,
    ): void {
        if ($platform instanceof AbstractMySQLPlatform) {
            $this->upsertBufferRowMySql($connection, $tableName, $columns, $row);

            return;
        }

        if ($platform instanceof SQLitePlatform || $platform instanceof PostgreSQLPlatform) {
            $this->upsertBufferRowOnConflict($connection, $tableName, $columns, $row);

            return;
        }

        $this->upsertBufferRowFallback($connection, $tableName, $columns, $row);
    }

    /**
     * Native MySQL / MariaDB upsert — no UniqueConstraintViolationException on conflict
     * (avoids noise in tools that report caught SQLSTATE 23000 / errno 1062).
     *
     * @param array<string, string> $columns
     * @param array{messageId: string, domain: string, locale: string, hits: int, callSite: ?string, requestRoute: ?string, requestMethod: ?string, requestPath: ?string, seenAt: string} $row
     */
    private function upsertBufferRowMySql(
        Connection $connection,
        string $tableName,
        array $columns,
        array $row,
    ): void {
        $q = static fn (string $name): string => $connection->quoteSingleIdentifier($name);

        $qTable           = $q($tableName);
        $qMessageId       = $q($columns['messageId']);
        $qDomain          = $q($columns['domain']);
        $qLocale          = $q($columns['locale']);
        $qStatus          = $q($columns['status']);
        $qHitCount        = $q($columns['hitCount']);
        $qFirstSeenAt     = $q($columns['firstSeenAt']);
        $qLastSeenAt      = $q($columns['lastSeenAt']);
        $qStatusChangedAt = $q($columns['statusChangedAt']);
        $qNotes           = $q($columns['notes']);
        $qCallSite        = $q($columns['callSite']);
        $qRequestRoute    = $q($columns['requestRoute']);
        $qRequestMethod   = $q($columns['requestMethod']);
        $qRequestPath     = $q($columns['requestPath']);

        $sql = <<<SQL
            INSERT INTO {$qTable} (
                {$qMessageId}, {$qDomain}, {$qLocale}, {$qStatus}, {$qHitCount},
                {$qFirstSeenAt}, {$qLastSeenAt}, {$qStatusChangedAt}, {$qNotes},
                {$qCallSite}, {$qRequestRoute}, {$qRequestMethod}, {$qRequestPath}
            ) VALUES (
                :messageId, :domain, :locale, :status, :hits,
                :seenAt, :seenAt, NULL, NULL,
                :callSite, :requestRoute, :requestMethod, :requestPath
            )
            ON DUPLICATE KEY UPDATE
                {$qHitCount} = {$qHitCount} + VALUES({$qHitCount}),
                {$qLastSeenAt} = VALUES({$qLastSeenAt}),
                {$qCallSite} = COALESCE(VALUES({$qCallSite}), {$qCallSite}),
                {$qRequestRoute} = COALESCE(VALUES({$qRequestRoute}), {$qRequestRoute}),
                {$qRequestMethod} = COALESCE(VALUES({$qRequestMethod}), {$qRequestMethod}),
                {$qRequestPath} = COALESCE(VALUES({$qRequestPath}), {$qRequestPath})
            SQL;

        $connection->executeStatement($sql, $this->upsertParams($row), $this->upsertTypes($row));
    }

    /**
     * SQLite / PostgreSQL upsert via ON CONFLICT — no exception on duplicate unique key.
     *
     * @param array<string, string> $columns
     * @param array{messageId: string, domain: string, locale: string, hits: int, callSite: ?string, requestRoute: ?string, requestMethod: ?string, requestPath: ?string, seenAt: string} $row
     */
    private function upsertBufferRowOnConflict(
        Connection $connection,
        string $tableName,
        array $columns,
        array $row,
    ): void {
        $q = static fn (string $name): string => $connection->quoteSingleIdentifier($name);

        $qTable           = $q($tableName);
        $qMessageId       = $q($columns['messageId']);
        $qDomain          = $q($columns['domain']);
        $qLocale          = $q($columns['locale']);
        $qStatus          = $q($columns['status']);
        $qHitCount        = $q($columns['hitCount']);
        $qFirstSeenAt     = $q($columns['firstSeenAt']);
        $qLastSeenAt      = $q($columns['lastSeenAt']);
        $qStatusChangedAt = $q($columns['statusChangedAt']);
        $qNotes           = $q($columns['notes']);
        $qCallSite        = $q($columns['callSite']);
        $qRequestRoute    = $q($columns['requestRoute']);
        $qRequestMethod   = $q($columns['requestMethod']);
        $qRequestPath     = $q($columns['requestPath']);

        // excluded / EXCLUDED is the proposed insert row (SQLite + PostgreSQL).
        $sql = <<<SQL
            INSERT INTO {$qTable} (
                {$qMessageId}, {$qDomain}, {$qLocale}, {$qStatus}, {$qHitCount},
                {$qFirstSeenAt}, {$qLastSeenAt}, {$qStatusChangedAt}, {$qNotes},
                {$qCallSite}, {$qRequestRoute}, {$qRequestMethod}, {$qRequestPath}
            ) VALUES (
                :messageId, :domain, :locale, :status, :hits,
                :seenAt, :seenAt, NULL, NULL,
                :callSite, :requestRoute, :requestMethod, :requestPath
            )
            ON CONFLICT ({$qMessageId}, {$qDomain}, {$qLocale}) DO UPDATE SET
                {$qHitCount} = {$qHitCount} + excluded.{$qHitCount},
                {$qLastSeenAt} = excluded.{$qLastSeenAt},
                {$qCallSite} = COALESCE(excluded.{$qCallSite}, {$qCallSite}),
                {$qRequestRoute} = COALESCE(excluded.{$qRequestRoute}, {$qRequestRoute}),
                {$qRequestMethod} = COALESCE(excluded.{$qRequestMethod}, {$qRequestMethod}),
                {$qRequestPath} = COALESCE(excluded.{$qRequestPath}, {$qRequestPath})
            SQL;

        $connection->executeStatement($sql, $this->upsertParams($row), $this->upsertTypes($row));
    }

    /**
     * Portable fallback for other platforms: UPDATE first (common path, no exception),
     * then INSERT; on concurrent insert race, catch and UPDATE again.
     *
     * @param array<string, string> $columns
     * @param array{messageId: string, domain: string, locale: string, hits: int, callSite: ?string, requestRoute: ?string, requestMethod: ?string, requestPath: ?string, seenAt: string} $row
     */
    private function upsertBufferRowFallback(
        Connection $connection,
        string $tableName,
        array $columns,
        array $row,
    ): void {
        $updated = $this->updateExistingBufferRow($connection, $tableName, $columns, $row);
        if ($updated > 0) {
            return;
        }

        try {
            $connection->insert($tableName, [
                $columns['messageId']       => $row['messageId'],
                $columns['domain']          => $row['domain'],
                $columns['locale']          => $row['locale'],
                $columns['status']          => MissingTranslationLogStatus::Pending->value,
                $columns['hitCount']        => $row['hits'],
                $columns['firstSeenAt']     => $row['seenAt'],
                $columns['lastSeenAt']      => $row['seenAt'],
                $columns['statusChangedAt'] => null,
                $columns['notes']           => null,
                $columns['callSite']        => $row['callSite'],
                $columns['requestRoute']    => $row['requestRoute'],
                $columns['requestMethod']   => $row['requestMethod'],
                $columns['requestPath']     => $row['requestPath'],
            ], [
                $columns['messageId']       => ParameterType::STRING,
                $columns['domain']          => ParameterType::STRING,
                $columns['locale']          => ParameterType::STRING,
                $columns['status']          => ParameterType::STRING,
                $columns['hitCount']        => ParameterType::INTEGER,
                $columns['firstSeenAt']     => ParameterType::STRING,
                $columns['lastSeenAt']      => ParameterType::STRING,
                $columns['statusChangedAt'] => ParameterType::NULL,
                $columns['notes']           => ParameterType::NULL,
                $columns['callSite']        => $row['callSite'] === null ? ParameterType::NULL : ParameterType::STRING,
                $columns['requestRoute']    => $row['requestRoute'] === null ? ParameterType::NULL : ParameterType::STRING,
                $columns['requestMethod']   => $row['requestMethod'] === null ? ParameterType::NULL : ParameterType::STRING,
                $columns['requestPath']     => $row['requestPath'] === null ? ParameterType::NULL : ParameterType::STRING,
            ]);
        } catch (UniqueConstraintViolationException) {
            $this->updateExistingBufferRow($connection, $tableName, $columns, $row);
        }
    }

    /**
     * @param array<string, string> $columns
     * @param array{messageId: string, domain: string, locale: string, hits: int, callSite: ?string, requestRoute: ?string, requestMethod: ?string, requestPath: ?string, seenAt: string} $row
     */
    private function updateExistingBufferRow(
        Connection $connection,
        string $tableName,
        array $columns,
        array $row,
    ): int {
        $q = static fn (string $name): string => $connection->quoteSingleIdentifier($name);

        $qTable         = $q($tableName);
        $qHitCount      = $q($columns['hitCount']);
        $qLastSeenAt    = $q($columns['lastSeenAt']);
        $qCallSite      = $q($columns['callSite']);
        $qRequestRoute  = $q($columns['requestRoute']);
        $qRequestMethod = $q($columns['requestMethod']);
        $qRequestPath   = $q($columns['requestPath']);
        $qMessageId     = $q($columns['messageId']);
        $qDomain        = $q($columns['domain']);
        $qLocale        = $q($columns['locale']);

        $sql    = "UPDATE {$qTable} SET {$qHitCount} = {$qHitCount} + :hits, {$qLastSeenAt} = :lastSeenAt";
        $params = [
            'hits'       => $row['hits'],
            'lastSeenAt' => $row['seenAt'],
            'messageId'  => $row['messageId'],
            'domain'     => $row['domain'],
            'locale'     => $row['locale'],
        ];
        $types = [
            'hits'       => ParameterType::INTEGER,
            'lastSeenAt' => ParameterType::STRING,
            'messageId'  => ParameterType::STRING,
            'domain'     => ParameterType::STRING,
            'locale'     => ParameterType::STRING,
        ];

        if ($row['callSite'] !== null) {
            $sql .= ", {$qCallSite} = :callSite";
            $params['callSite'] = $row['callSite'];
            $types['callSite']  = ParameterType::STRING;
        }
        if ($row['requestRoute'] !== null) {
            $sql .= ", {$qRequestRoute} = :requestRoute";
            $params['requestRoute'] = $row['requestRoute'];
            $types['requestRoute']  = ParameterType::STRING;
        }
        if ($row['requestMethod'] !== null) {
            $sql .= ", {$qRequestMethod} = :requestMethod";
            $params['requestMethod'] = $row['requestMethod'];
            $types['requestMethod']  = ParameterType::STRING;
        }
        if ($row['requestPath'] !== null) {
            $sql .= ", {$qRequestPath} = :requestPath";
            $params['requestPath'] = $row['requestPath'];
            $types['requestPath']  = ParameterType::STRING;
        }

        $sql .= " WHERE {$qMessageId} = :messageId AND {$qDomain} = :domain AND {$qLocale} = :locale";

        return (int) $connection->executeStatement($sql, $params, $types);
    }

    /**
     * @param array{messageId: string, domain: string, locale: string, hits: int, callSite: ?string, requestRoute: ?string, requestMethod: ?string, requestPath: ?string, seenAt: string} $row
     *
     * @return array{messageId: string, domain: string, locale: string, status: string, hits: int, seenAt: string, callSite: ?string, requestRoute: ?string, requestMethod: ?string, requestPath: ?string}
     */
    private function upsertParams(array $row): array
    {
        return [
            'messageId'     => $row['messageId'],
            'domain'        => $row['domain'],
            'locale'        => $row['locale'],
            'status'        => MissingTranslationLogStatus::Pending->value,
            'hits'          => $row['hits'],
            'seenAt'        => $row['seenAt'],
            'callSite'      => $row['callSite'],
            'requestRoute'  => $row['requestRoute'],
            'requestMethod' => $row['requestMethod'],
            'requestPath'   => $row['requestPath'],
        ];
    }

    /**
     * @param array{messageId: string, domain: string, locale: string, hits: int, callSite: ?string, requestRoute: ?string, requestMethod: ?string, requestPath: ?string, seenAt: string} $row
     *
     * @return array<string, ParameterType>
     */
    private function upsertTypes(array $row): array
    {
        return [
            'messageId'     => ParameterType::STRING,
            'domain'        => ParameterType::STRING,
            'locale'        => ParameterType::STRING,
            'status'        => ParameterType::STRING,
            'hits'          => ParameterType::INTEGER,
            'seenAt'        => ParameterType::STRING,
            'callSite'      => $row['callSite'] === null ? ParameterType::NULL : ParameterType::STRING,
            'requestRoute'  => $row['requestRoute'] === null ? ParameterType::NULL : ParameterType::STRING,
            'requestMethod' => $row['requestMethod'] === null ? ParameterType::NULL : ParameterType::STRING,
            'requestPath'   => $row['requestPath'] === null ? ParameterType::NULL : ParameterType::STRING,
        ];
    }

    private function normalizeMessageId(string $messageId): string
    {
        return strlen($messageId) <= 500 ? $messageId : substr($messageId, 0, 497) . '...';
    }

    private function normalizeDomain(string $domain): string
    {
        return strlen($domain) <= 180 ? $domain : substr($domain, 0, 177) . '...';
    }

    private function normalizeLocale(string $locale): string
    {
        return strlen($locale) <= 32 ? $locale : substr($locale, 0, 32);
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
