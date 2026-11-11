<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Symfony\CodeQuality\Rector\Class_\EventSubscriberToAttributeRector;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withPhpVersion(PhpVersion::PHP_82)
    ->withComposerBased(symfony: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
    )
    ->withSkip([
        __DIR__ . '/vendor',
        __DIR__ . '/tests/Fixtures',
        __DIR__ . '/demo',
        // Subscribers wired explicitly in the bundle's service config (the worker-state subscriber gets an iterator of
        // IGNORE_ON_UNINITIALIZED references and subscribes optional Messenger events by name).
        EventSubscriberToAttributeRector::class => [
            __DIR__ . '/src/EventSubscriber/MissingLogUiAccessSubscriber.php',
            __DIR__ . '/src/TextOverride/EventSubscriber/TranslationOverridesClearSubscriber.php',
        ],
    ]);
