<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\TextOverride\Doctrine;

use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Nowo\TranslationYamlToolsBundle\TextOverride\Entity\TranslationOverride;

/**
 * Applies the configurable table name ({@code overrides.table_prefix} + "override") and the
 * (locale, domain, message_key) unique constraint to {@see TranslationOverride}.
 */
final class TranslationOverrideMetadataListener
{
    public function __construct(
        private readonly string $tablePrefix,
    ) {
    }

    public function loadClassMetadata(LoadClassMetadataEventArgs $args): void
    {
        $class = $args->getClassMetadata();
        if ($class->getName() !== TranslationOverride::class) {
            return;
        }

        $tableName = $this->tablePrefix . 'override';
        $suffix    = substr(sha1($tableName), 0, 12);

        $class->setPrimaryTable([
            'name'              => $tableName,
            'uniqueConstraints' => [
                'tyt_ovr_uq_' . $suffix => [
                    'columns' => ['locale', 'domain', 'message_key'],
                ],
            ],
        ]);
    }
}
