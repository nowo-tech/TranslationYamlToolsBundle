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
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\QuoteStrategy;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use LogicException;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TranslationYamlToolsBundle\Concurrency\TranslationYamlToolsWriteUnit;
use Nowo\TranslationYamlToolsBundle\Doctrine\TranslationYamlToolsManagerRegistry;
use Nowo\TranslationYamlToolsBundle\Entity\MissingTranslationLog;
use Nowo\TranslationYamlToolsBundle\Entity\MissingTranslationLogStatus;
use SortDirection;

use function array_chunk;
use function array_keys;
use function max;
use function sprintf;
use function strlen;
use function usort;

/**
 * @extends ServiceEntityRepository<MissingTranslationLog>
 *
 * Not final so unit tests can mock {@see persistBuffer} via PHPUnit.
 *
 * Bundle methods resolve the entity manager per call and replace a closed one, and reads refresh managed rows
 * ({@see Query::HINT_REFRESH}) because hit counts and deletions are written with DBAL behind the identity map.
 * After DBAL deletes, managed {@see MissingTranslationLog} instances are detached.
 * This keeps the Web UI correct in FrankenPHP worker mode even when {@code kernel.reset} does not run.
 *
 * Since 1.18.0 the registry it gets is {@see TranslationYamlToolsManagerRegistry}
 * by default, so the manager is the bundle's private one: {@see self::flush()} and {@see self::clearManaged()} never
 * flush or clear the application's manager.
 */
class MissingTranslationLogRepository extends ServiceEntityRepository
{
    /** Buffer rows per transaction of {@see self::persistBuffer()} (REQ-RUNTIME-003 rule 9: small transactions). */
    public const PERSIST_CHUNK = 200;

    private readonly ManagerRegistry $managerRegistry;

    public function __construct(ManagerRegistry $registry)
    {
        $this->managerRegistry = $registry;
        parent::__construct($registry, MissingTranslationLog::class);
    }

    /**
     * Rows of one status, most recently seen first ({@code $offset}: paging of the Web UI list).
     *
     * @return list<MissingTranslationLog>
     */
    public function findByStatus(MissingTranslationLogStatus $status, int $limit = 500, int $offset = 0): array
    {
        /** @var list<MissingTranslationLog> $rows */
        $rows = $this->entityManager()->createQueryBuilder()
            ->select('l')
            ->from(MissingTranslationLog::class, 'l')
            ->andWhere('l.status = :status')
            ->setParameter('status', $status)
            ->orderBy('l.lastSeenAt', SortDirection::Descending)
            ->addOrderBy('l.id', SortDirection::Descending)
            ->setFirstResult(max(0, $offset))
            ->setMaxResults($limit)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();

        return $rows;
    }

    /**
     * Number of rows of one status (pagination of the Web UI list).
     */
    public function countByStatus(MissingTranslationLogStatus $status): int
    {
        return (int) $this->entityManager()->createQueryBuilder()
            ->select('COUNT(l.id)')
            ->from(MissingTranslationLog::class, 'l')
            ->andWhere('l.status = :status')
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findOneById(int $id): ?MissingTranslationLog
    {
        /** @var MissingTranslationLog|null $row */
        $row = $this->entityManager()->createQueryBuilder()
            ->select('l')
            ->from(MissingTranslationLog::class, 'l')
            ->andWhere('l.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $row;
    }

    public function clearAll(): int
    {
        $em         = $this->entityManager();
        $connection = $em->getConnection();
        $meta       = $em->getClassMetadata(MissingTranslationLog::class);
        $qTableName = $this->quoteStrategy($em)->getTableName($meta, $connection->getDatabasePlatform());

        $deleted = (int) TranslationYamlToolsWriteUnit::run($em, $this->managerRegistry, static fn (): int|string => $connection->executeStatement("DELETE FROM {$qTableName}"), false);
        $this->detachManagedMissingLogs($em);

        return $deleted;
    }

    public function clearByStatus(MissingTranslationLogStatus $status): int
    {
        $em         = $this->entityManager();
        $connection = $em->getConnection();
        $meta       = $em->getClassMetadata(MissingTranslationLog::class);
        $platform   = $connection->getDatabasePlatform();
        $qTableName = $this->quoteStrategy($em)->getTableName($meta, $platform);
        $qStatus    = $this->quoteStrategy($em)->getColumnName('status', $meta, $platform);

        $deleted = (int) TranslationYamlToolsWriteUnit::run($em, $this->managerRegistry, static fn (): int|string => $connection->executeStatement(
            "DELETE FROM {$qTableName} WHERE {$qStatus} = :status",
            ['status' => $status->value],
            ['status' => ParameterType::STRING],
        ), false);
        $this->detachManagedMissingLogs($em);

        return $deleted;
    }

    /**
     * Writes the pending changes of the bundle's manager (a status change) as one write unit (1.19, REQ-RUNTIME-003
     * rules 1–3, {@see TranslationYamlToolsWriteUnit}): one transaction (a savepoint inside yours), the nesting level
     * repaired after a failure; a closed manager is replaced so the next request of a long-running worker can use the
     * ORM again, an open application manager is never cleared or reset.
     *
     * @throws TransientStorageFailure when the storage failed transiently (the DBAL error as previous)
     */
    public function flush(): void
    {
        TranslationYamlToolsWriteUnit::run($this->entityManager(), $this->managerRegistry, static fn (): null => null);
    }

    /**
     * Clears the whole entity manager this entity belongs to. Not called by the bundle at runtime.
     */
    public function clearManaged(): void
    {
        $this->entityManager()->clear();
    }

    /**
     * Adds a buffer of missing keys to the log: one atomic upsert per key (hit count `+ n`, never read-modify-write),
     * in a fixed key order, {@see self::PERSIST_CHUNK} keys per transaction (1.19). A deadlock or lock wait timeout
     * replays the chunk in place (up to {@see TranslationYamlToolsWriteUnit::REPLAY_ATTEMPTS} attempts) unless you
     * opened the transaction around the call; then it is rethrown.
     *
     * @param array<string, array{hits: int, messageId: string, domain: string, locale: string, callSite?: ?string, requestRoute?: ?string, requestMethod?: ?string, requestPath?: ?string}> $buffer keyed by stable hash
     *
     * @throws TransientStorageFailure when a chunk still met a lock conflict after its replays
     */
    public function persistBuffer(array $buffer): void
    {
        if ($buffer === []) {
            return;
        }

        $em         = $this->entityManager();
        $connection = $em->getConnection();
        $platform   = $connection->getDatabasePlatform();
        $now        = new DateTimeImmutable();
        $meta       = $em->getClassMetadata(MissingTranslationLog::class);
        // 1.19: the ORM's quote strategy names the table and columns as the schema created them (quoted only where the
        // mapping says so). Quoting every name broke PostgreSQL with a mixed-case naming strategy ("hitCount").
        $quotes    = $this->quoteStrategy($em);
        $tableName = $quotes->getTableName($meta, $platform);

        $columns = [
            'messageId'       => $quotes->getColumnName('messageId', $meta, $platform),
            'domain'          => $quotes->getColumnName('domain', $meta, $platform),
            'locale'          => $quotes->getColumnName('locale', $meta, $platform),
            'status'          => $quotes->getColumnName('status', $meta, $platform),
            'hitCount'        => $quotes->getColumnName('hitCount', $meta, $platform),
            'firstSeenAt'     => $quotes->getColumnName('firstSeenAt', $meta, $platform),
            'lastSeenAt'      => $quotes->getColumnName('lastSeenAt', $meta, $platform),
            'statusChangedAt' => $quotes->getColumnName('statusChangedAt', $meta, $platform),
            'notes'           => $quotes->getColumnName('notes', $meta, $platform),
            'callSite'        => $quotes->getColumnName('callSite', $meta, $platform),
            'requestRoute'    => $quotes->getColumnName('requestRoute', $meta, $platform),
            'requestMethod'   => $quotes->getColumnName('requestMethod', $meta, $platform),
            'requestPath'     => $quotes->getColumnName('requestPath', $meta, $platform),
        ];

        $rows = [];
        foreach ($buffer as $row) {
            $rows[] = [
                'messageId'     => $this->normalizeMessageId($row['messageId']),
                'domain'        => $this->normalizeDomain($row['domain']),
                'locale'        => $this->normalizeLocale($row['locale']),
                'hits'          => $row['hits'],
                'callSite'      => $this->normalizeCallSite($row['callSite'] ?? null),
                'requestRoute'  => $this->normalizeRequestRoute($row['requestRoute'] ?? null),
                'requestMethod' => $this->normalizeRequestMethod($row['requestMethod'] ?? null),
                'requestPath'   => $this->normalizeRequestPath($row['requestPath'] ?? null),
                'seenAt'        => $now->format('Y-m-d H:i:s'),
            ];
        }
        // 1.19 (REQ-RUNTIME-003 rule 4): rows in a fixed order, so two buffers upserting the same keys lock them in the
        // same order (no deadlock between them).
        usort($rows, static fn (array $a, array $b): int => [$a['locale'], $a['domain'], $a['messageId']] <=> [$b['locale'], $b['domain'], $b['messageId']]);

        // One small transaction per chunk (rules 1 and 9), replayed in place after a deadlock or lock wait timeout:
        // an attempt is rolled back whole, so a replay never counts a hit twice.
        foreach (array_chunk($rows, self::PERSIST_CHUNK) as $chunk) {
            // @igor-ignore - The closure runs synchronously inside the unit; nothing is kept on the repository.
            TranslationYamlToolsWriteUnit::runReplayingTransients($em, $this->managerRegistry, function () use ($connection, $platform, $tableName, $columns, $chunk): void {
                foreach ($chunk as $row) {
                    $this->upsertBufferRow($connection, $platform, $tableName, $columns, $row);
                }
            }, false);
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

        $qTable           = $tableName;
        $qMessageId       = $columns['messageId'];
        $qDomain          = $columns['domain'];
        $qLocale          = $columns['locale'];
        $qStatus          = $columns['status'];
        $qHitCount        = $columns['hitCount'];
        $qFirstSeenAt     = $columns['firstSeenAt'];
        $qLastSeenAt      = $columns['lastSeenAt'];
        $qStatusChangedAt = $columns['statusChangedAt'];
        $qNotes           = $columns['notes'];
        $qCallSite        = $columns['callSite'];
        $qRequestRoute    = $columns['requestRoute'];
        $qRequestMethod   = $columns['requestMethod'];
        $qRequestPath     = $columns['requestPath'];

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

        $qTable           = $tableName;
        $qMessageId       = $columns['messageId'];
        $qDomain          = $columns['domain'];
        $qLocale          = $columns['locale'];
        $qStatus          = $columns['status'];
        $qHitCount        = $columns['hitCount'];
        $qFirstSeenAt     = $columns['firstSeenAt'];
        $qLastSeenAt      = $columns['lastSeenAt'];
        $qStatusChangedAt = $columns['statusChangedAt'];
        $qNotes           = $columns['notes'];
        $qCallSite        = $columns['callSite'];
        $qRequestRoute    = $columns['requestRoute'];
        $qRequestMethod   = $columns['requestMethod'];
        $qRequestPath     = $columns['requestPath'];

        // excluded / EXCLUDED is the proposed insert row (SQLite + PostgreSQL). 1.19: the existing row's columns are
        // qualified with the table name; PostgreSQL refused the bare names as ambiguous, so it never logged a key.
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
                {$qHitCount} = {$qTable}.{$qHitCount} + excluded.{$qHitCount},
                {$qLastSeenAt} = excluded.{$qLastSeenAt},
                {$qCallSite} = COALESCE(excluded.{$qCallSite}, {$qTable}.{$qCallSite}),
                {$qRequestRoute} = COALESCE(excluded.{$qRequestRoute}, {$qTable}.{$qRequestRoute}),
                {$qRequestMethod} = COALESCE(excluded.{$qRequestMethod}, {$qTable}.{$qRequestMethod}),
                {$qRequestPath} = COALESCE(excluded.{$qRequestPath}, {$qTable}.{$qRequestPath})
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

        $qTable         = $tableName;
        $qHitCount      = $columns['hitCount'];
        $qLastSeenAt    = $columns['lastSeenAt'];
        $qCallSite      = $columns['callSite'];
        $qRequestRoute  = $columns['requestRoute'];
        $qRequestMethod = $columns['requestMethod'];
        $qRequestPath   = $columns['requestPath'];
        $qMessageId     = $columns['messageId'];
        $qDomain        = $columns['domain'];
        $qLocale        = $columns['locale'];

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

    private function entityManager(): EntityManagerInterface
    {
        $em = $this->managerRegistry->getManagerForClass(MissingTranslationLog::class);
        if ($em instanceof EntityManagerInterface && !$em->isOpen()) {
            $this->resetClosedManager($em);
            $em = $this->managerRegistry->getManagerForClass(MissingTranslationLog::class);
        }

        if (!$em instanceof EntityManagerInterface) {
            throw new LogicException(sprintf('No Doctrine ORM entity manager is configured for "%s".', MissingTranslationLog::class));
        }

        return $em;
    }

    private function quoteStrategy(EntityManagerInterface $em): QuoteStrategy
    {
        return $em->getConfiguration()->getQuoteStrategy();
    }

    private function resetClosedManager(ObjectManager $closed): void
    {
        foreach (array_keys($this->managerRegistry->getManagerNames()) as $name) {
            if ($this->managerRegistry->getManager($name) === $closed) {
                $this->managerRegistry->resetManager($name);

                return;
            }
        }
    }

    /**
     * Detaches managed {@see MissingTranslationLog} instances after a DBAL DELETE so a long-lived
     * FrankenPHP worker identity map does not retain rows that no longer exist (Doctrine ORM 3 has no per-class clear).
     */
    private function detachManagedMissingLogs(EntityManagerInterface $em): void
    {
        /** @var array<class-string, array<string, object>> $identityMap */
        $identityMap = $em->getUnitOfWork()->getIdentityMap();
        foreach ($identityMap[MissingTranslationLog::class] ?? [] as $entity) {
            $em->detach($entity);
        }
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
