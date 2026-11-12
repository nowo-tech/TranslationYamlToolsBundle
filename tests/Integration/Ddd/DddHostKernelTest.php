<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Integration\Ddd;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TranslationYamlToolsBundle\Entity\MissingTranslationLog;
use Nowo\TranslationYamlToolsBundle\Tests\Fixtures\Ddd\Domain\Page;
use Nowo\TranslationYamlToolsBundle\Tests\Kernel\DddHostTestKernel;
use Nowo\TranslationYamlToolsBundle\TextOverride\Entity\TranslationOverride;
use Nowo\TranslationYamlToolsBundle\TextOverride\TranslationOverrideEditor;
use Nowo\TranslationYamlToolsBundle\TextOverride\TranslationOverrideProviderInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * REQ-ARCH-001, DDD / hexagonal host: XML-mapped plain-PHP domain on the host's default manager, the bundle on its own
 * "translations" manager. The kernel boots, the host writes and reads texts through the bundle ports by translation
 * key, the bundle never flushes or clears the host's manager, and it clears its own manager at the boundaries.
 */
final class DddHostKernelTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return DddHostTestKernel::class;
    }

    public function testDomainOnXmlAndBundleOnItsOwnManagerThroughThePorts(): void
    {
        self::bootKernel(['debug' => false]);
        $container = self::getContainer();
        $doctrine  = $container->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $host  = $doctrine->getManager('default');
        $texts = $doctrine->getManager('translations');
        self::assertInstanceOf(EntityManagerInterface::class, $host);
        self::assertInstanceOf(EntityManagerInterface::class, $texts);
        self::assertSame($host, $doctrine->getManagerForClass(Page::class));
        self::assertSame($texts, $doctrine->getManagerForClass(TranslationOverride::class));
        self::assertSame($texts, $doctrine->getManagerForClass(MissingTranslationLog::class));
        self::assertTrue($host->getMetadataFactory()->isTransient(TranslationOverride::class), 'No bundle mapping on the host domain manager.');
        (new SchemaTool($host))->createSchema($host->getMetadataFactory()->getAllMetadata());
        (new SchemaTool($texts))->createSchema($texts->getMetadataFactory()->getAllMetadata());

        // The domain stages a page (pending) and its editor saves the page title text by key.
        $page = new Page('home', 'site.greeting');
        $host->persist($page);
        $editor = $container->get('test.ddd.editor');
        self::assertInstanceOf(TranslationOverrideEditor::class, $editor);
        $editor->save('messages', $page->titleKey(), ['en' => 'Welcome %name%', 'es' => 'Bienvenida %name%'], updatedBy: 'editor-7');

        self::assertTrue($host->getUnitOfWork()->isScheduledForInsert($page), 'The bundle never flushes the host manager.');
        self::assertSame(0, (int) $host->getConnection()->fetchOne('SELECT COUNT(*) FROM ddd_page'));

        $provider = $container->get('test.ddd.texts');
        self::assertInstanceOf(TranslationOverrideProviderInterface::class, $provider);
        self::assertSame('Welcome %name%', $provider->get('en', 'messages', $page->titleKey()));
        $translator = $container->get('test.ddd.translator');
        self::assertInstanceOf(TranslatorInterface::class, $translator);
        self::assertSame('Bienvenida Ana', $translator->trans($page->titleKey(), ['%name%' => 'Ana'], 'messages', 'es'));

        // The boundary clears the bundle's own manager, never the host's.
        $row = $texts->getRepository(TranslationOverride::class)->findOneBy(['locale' => 'en']);
        self::assertInstanceOf(TranslationOverride::class, $row);
        self::assertTrue($texts->contains($row));
        $kernel = $container->get('kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $kernel);
        $dispatcher = $container->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->dispatch(new TerminateEvent($kernel, new Request(), new Response()), KernelEvents::TERMINATE);
        self::assertFalse($texts->contains($row), 'kernel.terminate cleared the bundle manager.');
        self::assertTrue($host->contains($page), 'The host manager is the host\'s to clear.');

        $host->flush();
        self::assertSame(1, (int) $host->getConnection()->fetchOne('SELECT COUNT(*) FROM ddd_page'));
    }
}
