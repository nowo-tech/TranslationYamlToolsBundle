<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Functional\WebUi;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\TranslationYamlToolsBundle\Entity\MissingTranslationLog;
use Nowo\TranslationYamlToolsBundle\Entity\MissingTranslationLogStatus;
use Nowo\TranslationYamlToolsBundle\MissingTranslationLog\DoctrineMissingTranslationRecorder;
use Nowo\TranslationYamlToolsBundle\Tests\Kernel\MissingTranslationLogTestKernel;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[CoversNothing]
final class MissingTranslationLogWebUiTest extends WebTestCase
{
    /**
     * {@inheritdoc}
     */
    protected static function getKernelClass(): string
    {
        return MissingTranslationLogTestKernel::class;
    }

    public function testListAndMarkAdded(): void
    {
        $client = self::createClient();
        $this->resetMissingLogTable();

        $translator = self::getContainer()->get('translator');
        $translator->setLocale('es');
        $translator->trans('app.body', [], 'messages', 'es');
        self::getContainer()->get(DoctrineMissingTranslationRecorder::class)->flushBuffer();

        $client->request('GET', '/_translation_yaml_tools/missing-log/');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('app.body', (string) $client->getResponse()->getContent());

        $crawler = $client->getCrawler();
        $form    = $crawler->selectButton('Mark added')->form();
        $client->submit($form);

        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertResponseIsSuccessful();

        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $row = $em->getRepository(MissingTranslationLog::class)->findOneBy([]);
        self::assertNotNull($row);
        self::assertSame(MissingTranslationLogStatus::Added, $row->getStatus());
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
