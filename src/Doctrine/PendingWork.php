<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;

use function count;

/**
 * Counts the scheduled inserts, updates and deletes of an entity manager, including changes not computed yet (a
 * managed entity edited in memory). Used to log bundle work that a boundary or a failed bridge flush discards.
 *
 * @internal
 */
final class PendingWork
{
    public static function count(EntityManagerInterface $manager): int
    {
        $unitOfWork = $manager->getUnitOfWork();
        $unitOfWork->computeChangeSets();

        return count($unitOfWork->getScheduledEntityInsertions())
            + count($unitOfWork->getScheduledEntityUpdates())
            + count($unitOfWork->getScheduledEntityDeletions());
    }
}
