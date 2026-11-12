<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Doctrine;

use Doctrine\Common\EventManager;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\TranslationYamlToolsBundle\EventSubscriber\EntityManagerBoundarySubscriber;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

use function array_keys;
use function implode;
use function is_array;
use function is_string;
use function sprintf;
use function unserialize;

/**
 * The bundle's private entity manager (1.18.0, REQ-ARCH-001 rule 6), used when `doctrine.entity_manager` is not set.
 *
 * Before 1.18.0 the zero-config path wrote the bundle entities (text overrides, missing-translation log status changes)
 * through the application's default entity manager, so a bundle flush (Web UI save, `TranslationOverrides::set()`,
 * the missing-log commands and "mark added") flushed every change the application had left pending, and a failed
 * bundle flush closed the application's manager. The bundle now writes through a **private** manager created on the
 * connection, configuration (mapping, metadata cache, proxies) and event manager of the manager that maps the bundle
 * entities. Its unit of work holds only bundle rows: a bundle flush never commits an application change, and a failed
 * write closes only this manager. The tables do not move. The connection is shared, so a transaction the application
 * opened around a bundle call includes the bundle's rows.
 *
 * The object is a stable decorator (repositories keep it); the wrapped manager is resolved on every call and created
 * again when:
 *
 * - it was closed (its own failed flush) or the source connection changed;
 * - a rollback (transaction or savepoint) happened on the connection since it last wrote, so its identity map may
 *   describe rows that no longer exist ({@see PrivateEntityManagerMiddleware}). A stale manager that still has
 *   scheduled inserts, updates or deletes is **kept** (replacing it would silently drop them) until that work is
 *   flushed or the next unit-of-work boundary;
 * - {@see reset()} ran: at every outermost unit-of-work boundary ({@see EntityManagerBoundarySubscriber})
 *   and at `kernel.reset`.
 *
 * The SQL filters enabled on the source manager, with their parameters, are mirrored on the private manager on every
 * call, and a filter whose parameters changed is disabled and enabled again (a fresh instance), so a parameter the
 * source no longer has never survives.
 *
 * Same design as SeoKitBundle 1.24 / LegalKitBundle 1.12 / YopassBundle 1.19.
 *
 * @internal
 */
final class TranslationYamlToolsEntityManager extends EntityManagerDecorator implements ResetInterface
{
    private ?EntityManagerInterface $current = null;

    /** A rollback happened on the connection since the private manager was created or last replaced. */
    private bool $stale = false;

    /**
     * @param ManagerRegistry $registry The application's Doctrine registry (the source manager is resolved per call)
     * @param list<class-string> $entityClasses The bundle entities of the enabled features (the first mapped one
     *                                          names the source manager)
     * @param LoggerInterface|null $logger Warns when a boundary discards unflushed bundle work (audit ND-L2)
     */
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly array $entityClasses,
        private readonly ?LoggerInterface $logger = null,
    ) {
        // No wrapped manager yet: every read of $this->wrapped goes through __get() (resolved per call).
        unset($this->{'wrapped'});
    }

    /**
     * The wrapped manager of {@see EntityManagerDecorator} (the only magic property).
     */
    public function __get(string $name): EntityManagerInterface
    {
        if ($name !== 'wrapped') {
            throw new LogicException(sprintf('Undefined property %s::$%s.', self::class, $name));
        }

        return $this->live();
    }

    /**
     * The open private manager, created (again) when needed, with the source's SQL filters.
     */
    public function live(): EntityManagerInterface
    {
        $source  = $this->source();
        $current = $this->current;
        if (!$current instanceof EntityManagerInterface || !$current->isOpen() || $current->getConnection() !== $source->getConnection()
            || ($this->stale && !$this->hasScheduledWork($current))) {
            if ($current instanceof EntityManagerInterface && $current->isOpen()) {
                try {
                    $current->clear();
                } catch (Throwable $exception) {
                    $this->logger?->warning('Could not clear the replaced private entity manager of {class} ({exception_class}).', ['class' => self::class, 'exception_class' => $exception::class]);
                }
            }
            // DoctrineBundle's ContainerAwareEventManager extends EventManager; any other implementation gets a fresh one.
            $eventManager = $source->getEventManager();
            // @igor-ignore - Bundle-owned unit of work: dropped at every boundary, replaced when closed, stale or on another connection.
            $current = $this->current = new EntityManager($source->getConnection(), $source->getConfiguration(), $eventManager instanceof EventManager ? $eventManager : null);
            // @igor-ignore - Bundle-owned flag of the private manager, reset when the manager is replaced.
            $this->stale = false;
        }
        $this->syncFilters($source, $current);

        return $current;
    }

    /**
     * Whether a private manager exists right now (no side effect).
     */
    public function isCreated(): bool
    {
        return $this->current instanceof EntityManagerInterface;
    }

    /**
     * Whether a rollback made the private manager's identity map stale (no side effect).
     */
    public function isStale(): bool
    {
        return $this->stale;
    }

    /**
     * Whether the private manager exists and has scheduled inserts, updates or deletes (no manager is created).
     */
    public function hasPendingWork(): bool
    {
        return $this->pendingCount() > 0;
    }

    /**
     * Scheduled inserts, updates and deletes of the private manager (0 when it does not exist or is closed; no manager
     * is created).
     */
    public function pendingCount(): int
    {
        return $this->current instanceof EntityManagerInterface && $this->current->isOpen() ? PendingWork::count($this->current) : 0;
    }

    /**
     * Whether $manager is the private manager or this decorator.
     */
    public function owns(object $manager): bool
    {
        return $manager === $this || ($this->current instanceof EntityManagerInterface && $manager === $this->current);
    }

    /**
     * A transaction or savepoint was rolled back on the connection (told by {@see PrivateEntityManagerConnection}).
     * The manager is replaced at its next use unless it has scheduled work; it is never cleared here (the rollback
     * may run inside the private manager's own failing flush).
     */
    public function connectionRolledBack(): void
    {
        if ($this->current instanceof EntityManagerInterface) {
            // @igor-ignore - Bundle-owned flag of the private manager, cleared when the manager is replaced.
            $this->stale = true;
        }
    }

    /**
     * Detaches every row from the private manager. Never touches an application manager and never creates the private
     * one.
     */
    public function clear(): void
    {
        if ($this->current instanceof EntityManagerInterface && $this->current->isOpen()) {
            $this->current->clear();
        }
    }

    /**
     * Drops the private manager (an outermost unit-of-work boundary or `kernel.reset`); the next call creates a fresh
     * one. Unflushed bundle changes of the finished unit are discarded, never written later.
     *
     * 1.18.2 (audit NX): the manager is dropped FIRST; the pending-work inspection (which computes change sets) and the
     * clear() run afterwards, each guarded, so a manager whose unit of work throws (a wrongly typed association, a
     * throwing listener) is never kept and the boundary never throws.
     */
    public function reset(): void
    {
        $current = $this->current;
        // @igor-ignore - Boundary drop of the bundle's own manager (no state kept).
        $this->current = null;
        // @igor-ignore - Boundary drop of the bundle's own manager (no state kept).
        $this->stale = false;
        if (!$current instanceof EntityManagerInterface) {
            return;
        }

        try {
            $pending = $current->isOpen() ? PendingWork::count($current) : 0;
            if ($pending > 0) {
                $this->logger?->warning('Discarding {count} unflushed bundle change(s) of {class} at a unit-of-work boundary: stage them with "flush: false" only when you flush them in the same unit of work.', ['count' => $pending, 'class' => self::class]);
            }
        } catch (Throwable $exception) {
            $this->logger?->warning('Could not inspect the unflushed bundle changes of {class} at a unit-of-work boundary ({exception_class}); they are discarded.', ['class' => self::class, 'exception_class' => $exception::class]);
        }

        try {
            if ($current->isOpen()) {
                $current->clear();
            }
        } catch (Throwable $exception) {
            $this->logger?->warning('Could not clear the dropped private entity manager of {class} ({exception_class}).', ['class' => self::class, 'exception_class' => $exception::class]);
        }
    }

    /**
     * The application's manager whose connection, configuration, events and filters the private one shares. Never
     * flushed, cleared, closed or reset by the bundle.
     */
    public function source(): EntityManagerInterface
    {
        foreach ($this->entityClasses as $class) {
            $source = $this->registry->getManagerForClass($class);
            if ($source instanceof EntityManagerInterface) {
                return $source;
            }
        }

        throw new LogicException(sprintf('No Doctrine ORM entity manager maps %s: enable missing_translation_log or overrides with DoctrineBundle (see docs/CONFIGURATION.md).', implode(', ', $this->entityClasses)));
    }

    /**
     * Scheduled inserts, updates or deletes, including changes not computed yet (a managed entity edited in memory).
     */
    private function hasScheduledWork(EntityManagerInterface $manager): bool
    {
        try {
            return PendingWork::count($manager) > 0;
        } catch (Throwable $exception) {
            // A throwing unit of work: treated as stale without work, so live() replaces the manager like reset() (audit NX follow-up, 1.18.3).
            $this->logger?->warning('Could not inspect the pending bundle changes of a stale private manager of {class} ({exception_class}); it is replaced.', ['class' => self::class, 'exception_class' => $exception::class]);

            return false;
        }
    }

    /**
     * Mirrors the SQL filters enabled on the source manager, with their parameters, on the private one, and disables
     * the others. A filter whose serialized parameters differ is disabled and enabled again (a fresh instance) before
     * its parameters are copied, so a parameter the source no longer has (a previous tenant) never survives.
     */
    private function syncFilters(EntityManagerInterface $source, EntityManagerInterface $target): void
    {
        $wanted  = $source->getFilters()->getEnabledFilters();
        $filters = $target->getFilters();
        foreach (array_keys($filters->getEnabledFilters()) as $name) {
            if (!isset($wanted[$name])) {
                $filters->disable($name);
            }
        }
        foreach ($wanted as $name => $filter) {
            $state = (string) $filter;
            if ($filters->isEnabled($name)) {
                if ((string) $filters->getFilter($name) === $state) {
                    continue;
                }
                $filters->disable($name);
            }
            $copy = $filters->enable($name);
            /** @var array<string, array{value: mixed, type: int|string|null, is_list?: bool}> $parameters */
            $parameters = unserialize($state);
            // SQLFilter::setParameter() takes a string type or null: a non-string type was inferred, null infers it again.
            foreach ($parameters as $parameter => $definition) {
                if (($definition['is_list'] ?? false) === true && is_array($definition['value'])) {
                    $copy->setParameterList($parameter, $definition['value'], is_string($definition['type']) ? $definition['type'] : 'string');
                } else {
                    $copy->setParameter($parameter, $definition['value'], is_string($definition['type']) ? $definition['type'] : null);
                }
            }
        }
    }
}
