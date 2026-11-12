<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\TranslationYamlToolsBundle\Doctrine\PrivateEntityManagerFlushBridge;
use Nowo\TranslationYamlToolsBundle\Doctrine\TranslationYamlToolsEntityManager;
use Nowo\TranslationYamlToolsBundle\Tests\Support\SqliteManagerRegistry;
use Nowo\TranslationYamlToolsBundle\TextOverride\Entity\TranslationOverride;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;

use function dirname;

final class PrivateEntityManagerFlushBridgeTest extends TestCase
{
    #[IgnoreDeprecations]
    public function testFlushesStagedRowsOnlyAfterTheSourceManagerFlushed(): void
    {
        $mapping  = ['Nowo\\TranslationYamlToolsBundle\\TextOverride\\Entity' => dirname(__DIR__, 3) . '/src/TextOverride/Entity'];
        $doctrine = new SqliteManagerRegistry(['default' => $mapping, 'other' => $mapping]);
        $private  = new TranslationYamlToolsEntityManager($doctrine, [TranslationOverride::class]);
        $bridge   = new PrivateEntityManagerFlushBridge([$private]);
        $source   = $private->source();

        // Nothing built yet: nothing to do (and the manager is not created).
        (new PrivateEntityManagerFlushBridge())->postFlush(new PostFlushEventArgs($source));
        $bridge->postFlush(new PostFlushEventArgs($source));
        self::assertFalse($private->isCreated());

        $private->persist(new TranslationOverride('en', 'messages', 'site.a'));
        // Another manager's flush or the private manager's own flush never writes the staged row.
        $other = $doctrine->getManager('other');
        self::assertInstanceOf(EntityManagerInterface::class, $other);
        $bridge->postFlush(new PostFlushEventArgs($other));
        $bridge->postFlush(new PostFlushEventArgs($private->live()));
        self::assertTrue($private->hasPendingWork());

        (new SchemaTool($private->live()))->createSchema([$private->getClassMetadata(TranslationOverride::class)]);
        $bridge->postFlush(new PostFlushEventArgs($source));
        self::assertFalse($private->hasPendingWork(), 'Written after the source manager flushed.');
        self::assertSame(1, (int) $source->getConnection()->fetchOne('SELECT COUNT(*) FROM TranslationOverride'));
    }
}
