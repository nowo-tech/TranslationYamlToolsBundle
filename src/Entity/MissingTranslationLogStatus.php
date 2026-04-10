<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Entity;

/**
 * Workflow for missing-translation rows: pending → added (YAML updated) → validated (reviewed).
 */
enum MissingTranslationLogStatus: string
{
    case Pending = 'pending';

    case Added = 'added';

    case Validated = 'validated';
}
