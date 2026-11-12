<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Doctrine;

use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;

use function str_starts_with;
use function strrpos;
use function substr;

/**
 * The Doctrine registry the bundle repositories see (1.18.0, REQ-ARCH-001 rule 6, private entity manager only): the
 * bundle entities resolve to {@see TranslationYamlToolsEntityManager}, everything else to the application's registry
 * unchanged. The repositories therefore load and write the bundle rows through the private unit of work.
 *
 * @internal
 */
final readonly class TranslationYamlToolsManagerRegistry implements ManagerRegistry
{
    /** Namespaces of the bundle entities (missing-translation log, text overrides). */
    public const ENTITY_NAMESPACES = ['Nowo\\TranslationYamlToolsBundle\\Entity\\', 'Nowo\\TranslationYamlToolsBundle\\TextOverride\\Entity\\'];

    /**
     * @param ManagerRegistry $registry The application's Doctrine registry
     * @param TranslationYamlToolsEntityManager $entityManager The bundle's private manager
     */
    public function __construct(
        private ManagerRegistry $registry,
        private TranslationYamlToolsEntityManager $entityManager,
    ) {
    }

    public function getDefaultConnectionName(): string
    {
        return $this->registry->getDefaultConnectionName();
    }

    public function getConnection(?string $name = null): object
    {
        return $this->registry->getConnection($name);
    }

    public function getConnections(): array
    {
        return $this->registry->getConnections();
    }

    public function getConnectionNames(): array
    {
        return $this->registry->getConnectionNames();
    }

    public function getDefaultManagerName(): string
    {
        return $this->registry->getDefaultManagerName();
    }

    public function getManager(?string $name = null): ObjectManager
    {
        return $this->registry->getManager($name);
    }

    public function getManagers(): array
    {
        return $this->registry->getManagers();
    }

    /**
     * Resets an application manager on the application's behalf only: the bundle itself never calls it.
     */
    public function resetManager(?string $name = null): ObjectManager
    {
        return $this->registry->resetManager($name);
    }

    public function getManagerNames(): array
    {
        return $this->registry->getManagerNames();
    }

    public function getRepository(string $persistentObject, ?string $persistentManagerName = null): ObjectRepository
    {
        if ($persistentManagerName === null && self::isBundleEntity($persistentObject)) {
            return $this->entityManager->getRepository($persistentObject); // @phpstan-ignore return.type (the repository of that class)
        }

        return $this->registry->getRepository($persistentObject, $persistentManagerName);
    }

    public function getManagerForClass(string $class): ?ObjectManager
    {
        if (self::isBundleEntity($class)) {
            return $this->entityManager;
        }

        return $this->registry->getManagerForClass($class);
    }

    /**
     * Whether a class (or a Doctrine proxy class of it, `…\__CG__\…`) is a bundle entity.
     */
    public static function isBundleEntity(string $class): bool
    {
        $marker = strrpos($class, '\\__CG__\\');
        if ($marker !== false) {
            $class = substr($class, $marker + 8);
        }

        foreach (self::ENTITY_NAMESPACES as $namespace) {
            if (str_starts_with($class, $namespace)) {
                return true;
            }
        }

        return false;
    }
}
