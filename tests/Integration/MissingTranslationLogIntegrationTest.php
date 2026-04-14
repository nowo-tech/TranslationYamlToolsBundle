<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\TranslationYamlToolsBundle\Entity\MissingTranslationLog;
use Nowo\TranslationYamlToolsBundle\Entity\MissingTranslationLogStatus;
use Nowo\TranslationYamlToolsBundle\MissingTranslationLog\DoctrineMissingTranslationRecorder;
use Nowo\TranslationYamlToolsBundle\Tests\Kernel\MissingTranslationLogTestKernel;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversNothing]
final class MissingTranslationLogIntegrationTest extends KernelTestCase
{
    /**
     * {@inheritdoc}
     */
    protected static function getKernelClass(): string
    {
        return MissingTranslationLogTestKernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $this->resetMissingLogTable();
    }

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
        parent::tearDown();
    }

    public function testMissingKeyIsRecordedAfterFlush(): void
    {
        $translator = self::getContainer()->get('translator');
        self::assertInstanceOf(LocaleAwareInterface::class, $translator);
        self::assertInstanceOf(TranslatorBagInterface::class, $translator);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        $translator->setLocale('es');
        $translator->trans('app.body', [], 'messages', 'es');

        $recorder = self::getContainer()->get(DoctrineMissingTranslationRecorder::class);
        $recorder->flushBuffer();

        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $rows = $em->getRepository(MissingTranslationLog::class)->findAll();
        self::assertCount(1, $rows);
        self::assertSame('app.body', $rows[0]->getMessageId());
        self::assertSame('messages', $rows[0]->getDomain());
        self::assertSame('es', $rows[0]->getLocale());
        self::assertSame(MissingTranslationLogStatus::Pending, $rows[0]->getStatus());
        $callSite = $rows[0]->getCallSite();
        self::assertNotNull($callSite);
        self::assertStringContainsString('MissingTranslationLogIntegrationTest.php', $callSite);
    }

    public function testTableNameUsesTablePrefix(): void
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::assertSame(
            'nowo_translation_missing_log',
            $em->getClassMetadata(MissingTranslationLog::class)->getTableName(),
        );
    }

    public function testExistingKeyIsNotRecorded(): void
    {
        $translator = self::getContainer()->get('translator');
        self::assertInstanceOf(LocaleAwareInterface::class, $translator);
        $translator->setLocale('es');
        $translator->trans('app.title', [], 'messages', 'es');

        $recorder = self::getContainer()->get(DoctrineMissingTranslationRecorder::class);
        $recorder->flushBuffer();

        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::assertSame(0, $em->getRepository(MissingTranslationLog::class)->count([]));
    }

    public function testSameMissingKeyUpdatesExistingRowInsteadOfInsertingDuplicate(): void
    {
        $translator = self::getContainer()->get('translator');
        self::assertInstanceOf(LocaleAwareInterface::class, $translator);
        $translator->setLocale('es');
        $translator->trans('app.body', [], 'messages', 'es');

        $recorder = self::getContainer()->get(DoctrineMissingTranslationRecorder::class);
        $recorder->flushBuffer();

        $translator->trans('app.body', [], 'messages', 'es');
        $recorder->flushBuffer();

        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $rows = $em->getRepository(MissingTranslationLog::class)->findAll();
        self::assertCount(1, $rows);
        self::assertSame(2, $rows[0]->getHitCount());
    }

    private function resetMissingLogTable(): void
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $tool = new SchemaTool($em);
        $meta = $em->getClassMetadata(MissingTranslationLog::class);
        $tool->dropSchema([$meta]);
        $tool->createSchema([$meta]);
    }
}
