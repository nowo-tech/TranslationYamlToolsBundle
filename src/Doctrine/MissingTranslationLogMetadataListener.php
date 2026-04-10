<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Doctrine;

use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Nowo\TranslationYamlToolsBundle\Entity\MissingTranslationLog;

/**
 * Applies configurable table name and indexes for MissingTranslationLog (prefix + "missing_log").
 */
final class MissingTranslationLogMetadataListener
{
    public function __construct(
        private readonly string $tablePrefix,
    ) {
    }

    public function loadClassMetadata(LoadClassMetadataEventArgs $args): void
    {
        $class = $args->getClassMetadata();
        if ($class->getName() !== MissingTranslationLog::class) {
            return;
        }

        $tableName = $this->tablePrefix . 'missing_log';
        $suffix    = substr(sha1($tableName), 0, 12);

        $class->setPrimaryTable([
            'name'              => $tableName,
            'uniqueConstraints' => [
                'tyt_mlog_uq_' . $suffix => [
                    'columns' => ['message_id', 'domain', 'locale'],
                ],
            ],
            'indexes' => [
                'tyt_mlog_st_' . $suffix => [
                    'columns' => ['status'],
                ],
            ],
        ]);
    }
}
