<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Security;

/**
 * Access control for missing-translation-log Web UI routes (REQ-UI-002).
 */
interface MissingLogUiAccessCheckerInterface
{
    /**
     * @param object $user Authenticated security user
     */
    public function canAccess(object $user): bool;
}
