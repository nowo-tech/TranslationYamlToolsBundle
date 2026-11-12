<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Doctrine;

use Doctrine\ORM\Event\PostFlushEventArgs;
use Psr\Log\LoggerInterface;
use Throwable;

use function trigger_deprecation;

/**
 * Keeps the pre-1.18 "form bridge" working with the private entity manager (1.18.0, deprecated, removed in 2.0).
 *
 * Before 1.18.0 the bundle rows lived on the application's manager, so a host could stage overrides with
 * `TranslationOverrideEditor::save(..., flush: false)` (or `TranslationOverrides::set(..., flush: false)`) and write
 * them with its own `$entityManager->flush()`. With the private manager that flush no longer sees the bundle rows.
 * This Doctrine `postFlush` listener flushes the bundle rows still pending on the private manager right after the
 * source manager (the one that maps the bundle entities) flushed, so such a host does not silently lose them, and
 * triggers a deprecation: call `TranslationOverrideEditor::flush()` / `TranslationOverrides::flush()` instead (wrap
 * both flushes in `Connection::transactional()` when they must commit together).
 *
 * It never flushes an application manager: it only reacts to the source manager's flush and only flushes the private
 * one, after the application's own commit. Flushes of the private manager itself (it shares the event manager) and of
 * any other manager are ignored; the source manager is recognised by its unit of work, so a decorated application
 * manager matches too. A failure of the bridge flush is logged at error level and never rethrown: the application's
 * transaction is already committed, and an exception escaping postFlush would leave its unit of work uncleaned (its
 * next flush would write its rows twice). The private manager is injected as an iterator of an IGNORE_ON_UNINITIALIZED
 * reference: a flush of the application before any bundle call never builds it.
 *
 * @internal
 */
final class PrivateEntityManagerFlushBridge
{
    /** Re-entrancy guard while the private manager flushes. */
    private bool $flushing = false;

    /**
     * @param iterable<TranslationYamlToolsEntityManager> $entityManagers the private manager, if it was built
     * @param LoggerInterface|null $logger Logs a failed bridge flush (never rethrown, audit ND-M1)
     */
    public function __construct(
        private readonly iterable $entityManagers = [],
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->flushing) {
            return;
        }

        foreach ($this->entityManagers as $private) {
            $manager = $args->getObjectManager();
            // Same unit of work, not the same object: the registry may hand out a decorator of the flushed manager
            // (audit ND-L1).
            if ($private->owns($manager) || !$private->hasPendingWork() || $manager->getUnitOfWork() !== $private->source()->getUnitOfWork()) {
                continue;
            }

            trigger_deprecation('nowo-tech/translation-yaml-tools-bundle', '1.18', 'Bundle rows staged with "flush: false" were written by the application entity manager\'s flush. Since 1.18 the bundle writes through its own entity manager: call TranslationOverrideEditor::flush() or TranslationOverrides::flush() instead; 2.0 no longer flushes them for you.');

            // @igor-ignore - Re-entrancy guard around one flush, always unset in finally.
            $this->flushing = true;
            $pending        = $private->pendingCount();
            try {
                $private->flush();
            } catch (Throwable $exception) {
                // The application's transaction is already committed and its unit of work is cleaned up only after
                // postFlush: rethrowing here would make its next flush (or the user's retry) write its rows again
                // (audit ND-M1). The staged bundle rows are lost; the closed private manager is replaced on next use.
                $this->logger?->error('Bundle rows staged with "flush: false" could not be written after the application flush ({count} change(s)): {message}', ['count' => $pending, 'message' => $exception->getMessage(), 'exception' => $exception]);
            } finally {
                // @igor-ignore - Re-entrancy guard around one flush.
                $this->flushing = false;
            }
        }
    }
}
