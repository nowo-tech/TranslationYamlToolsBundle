<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Concurrency;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;
use Nowo\TranslationYamlToolsBundle\Doctrine\PendingWork;
use Nowo\TranslationYamlToolsBundle\Doctrine\TranslationYamlToolsEntityManager;
use Throwable;

use function mt_rand;
use function usleep;

/**
 * One write unit of the bundle (1.19, REQ-RUNTIME-003 rules 1–3), through transaction-doctrine's runner: one
 * transaction (a savepoint inside a transaction you opened), the connection's nesting level repaired after a failure,
 * and a deadlock, lock wait timeout or serialization failure rethrown as {@see TransientStorageFailure} with the
 * storage error as previous (anything else unchanged).
 *
 * The runner depends on who owns the manager that maps the bundle entities:
 *
 * - **the private manager** ({@see TranslationYamlToolsEntityManager}, the default) and a **dedicated manager**
 *   (`doctrine.entity_manager` names one other than the default) are the bundle's own:
 *   {@see DoctrineTransactionRunner::forEntityManager()} flushes them before the commit and, after a storage failure,
 *   resets the dedicated one through the registry or clears the private one (a closed private manager is replaced on
 *   its next use);
 * - **the application's default manager** (the deprecated `private_entity_manager: false`, or `entity_manager` naming
 *   the default) gets the DBAL runner ({@see DoctrineTransactionRunner::forConnection()}) and the unit flushes it
 *   itself, as the bundle did before (that is what those deprecated modes mean). An open manager is never cleared or
 *   reset (rule 2); one a failed flush closed is replaced through the registry. `forHostEntityManager()` is not used:
 *   in those modes rows staged with `flush: false` are pending on that manager on purpose, and it refuses pending
 *   changes.
 *
 * DBAL-only units (`$flush` false: the missing-log upserts and deletes) always take the DBAL runner and flush no
 * manager. Stateless (static methods only).
 *
 * @internal
 */
final class TranslationYamlToolsWriteUnit
{
    /** Attempts of {@see self::runReplayingTransients()} (the first one included). */
    public const REPLAY_ATTEMPTS = 3;

    /**
     * Runs `$work` as one unit on the connection of `$manager` (see the class description).
     *
     * @template T
     *
     * @param callable(): T $work
     * @param bool $flush true: an ORM unit (the manager is flushed before the commit); false: a DBAL-only unit
     * @param string|null $managerName `doctrine.entity_manager` (null: the private manager, or the default one)
     *
     * @throws TransientStorageFailure when the storage failed transiently (the DBAL error as previous)
     *
     * @return T
     */
    public static function run(EntityManagerInterface $manager, ManagerRegistry $registry, callable $work, bool $flush = true, ?string $managerName = null): mixed
    {
        if (!$flush) {
            return DoctrineTransactionRunner::forConnection($manager->getConnection())->run($work);
        }

        if ($manager instanceof TranslationYamlToolsEntityManager) {
            return DoctrineTransactionRunner::forEntityManager($manager)->run($work);
        }

        if (self::isDedicated($registry, $managerName)) {
            return DoctrineTransactionRunner::forEntityManager($manager, $registry, $managerName)->run($work);
        }

        try {
            // @igor-ignore - The closure runs synchronously inside the runner; nothing is kept on a service.
            return DoctrineTransactionRunner::forConnection($manager->getConnection())->run(static function () use ($manager, $work): mixed {
                $result = $work();
                $manager->flush();

                return $result;
            });
        } catch (Throwable $failure) {
            // Rule 2: only a manager the failed flush closed is replaced; an open application manager is never touched.
            $default = $registry->getDefaultManagerName();
            if (!$manager->isOpen() && $registry->getManager($default) === $manager) {
                $registry->resetManager($default);
            }

            throw $failure;
        }
    }

    /**
     * {@see self::run()} replayed in place after a transient failure, up to {@see self::REPLAY_ATTEMPTS} attempts with
     * a short random pause, when the unit is the outermost transaction (a replay inside a transaction you opened is
     * yours to decide: the failure is rethrown at once). `$work` must be safe to run again: a failed attempt was rolled
     * back whole.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @throws TransientStorageFailure when every attempt failed transiently, or the first one inside your transaction
     *
     * @return T
     */
    public static function runReplayingTransients(EntityManagerInterface $manager, ManagerRegistry $registry, callable $work, bool $flush = true, ?string $managerName = null): mixed
    {
        $outermost = $manager->getConnection()->getTransactionNestingLevel() === 0;
        for ($attempt = 1;; ++$attempt) {
            try {
                return self::run($manager, $registry, $work, $flush, $managerName);
            } catch (TransientStorageFailure $failure) {
                if (!$outermost || $attempt >= self::REPLAY_ATTEMPTS) {
                    throw $failure;
                }
                usleep(mt_rand(5_000, 25_000) * $attempt);
            }
        }
    }

    /**
     * Whether `$failure` or one of its previous exceptions is a unique-constraint violation (an expected "only one"
     * race the caller resolves itself; never a conflict).
     */
    public static function isUniqueViolation(Throwable $failure): bool
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof UniqueConstraintViolationException) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the manager is the bundle's own: the private manager, or a dedicated one (`doctrine.entity_manager` naming
     * a manager other than the default). Never true for the application's default manager.
     */
    public static function ownsManager(EntityManagerInterface $manager, ManagerRegistry $registry, ?string $managerName): bool
    {
        return $manager instanceof TranslationYamlToolsEntityManager || self::isDedicated($registry, $managerName);
    }

    /**
     * Whether the bundle's manager is its own and has no pending change (1.19, audit UQ-1): only then may a unit that
     * failed (its manager cleared, reset or replaced) be run again without dropping work staged before it. A unit of
     * work that cannot even compute its change sets counts as pending.
     */
    public static function isCleanBundleManager(EntityManagerInterface $manager, ManagerRegistry $registry, ?string $managerName): bool
    {
        if (!self::ownsManager($manager, $registry, $managerName)) {
            return false;
        }

        try {
            return PendingWork::count($manager) === 0;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @phpstan-assert-if-true string $managerName
     */
    private static function isDedicated(ManagerRegistry $registry, ?string $managerName): bool
    {
        return $managerName !== null && $managerName !== $registry->getDefaultManagerName();
    }
}
