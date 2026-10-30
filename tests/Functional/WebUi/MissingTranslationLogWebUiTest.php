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
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

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

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        parent::setUp();
    }

    public function testListAndMarkAdded(): void
    {
        $client = self::createClient();
        $this->resetMissingLogTable();

        $translator = self::getContainer()->get('translator');
        self::assertInstanceOf(LocaleAwareInterface::class, $translator);
        self::assertInstanceOf(TranslatorInterface::class, $translator);
        $translator->setLocale('es');
        $translator->trans('app.body', [], 'messages', 'es');
        $recorder = self::getContainer()->get(DoctrineMissingTranslationRecorder::class);
        self::assertInstanceOf(DoctrineMissingTranslationRecorder::class, $recorder);
        $recorder->flushBuffer();

        $client->request('GET', '/_translation_yaml_tools/missing-log/');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('app.body', (string) $client->getResponse()->getContent());

        $crawler = $client->getCrawler();
        $form    = $crawler->selectButton('Mark added')->form();
        $client->submit($form);

        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertResponseIsSuccessful();

        self::assertSelectorTextContains('.nowo-ui-flash--success', 'marked as added');

        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $row = $em->getRepository(MissingTranslationLog::class)->findOneBy([]);
        self::assertNotNull($row);
        self::assertSame(MissingTranslationLogStatus::Added, $row->getStatus());
    }

    public function testPageContractStatusTabsPaginationAndClearActions(): void
    {
        $client = self::createClient();
        $this->resetMissingLogTable();

        $translator = self::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorInterface::class, $translator);
        for ($i = 1; $i <= 30; ++$i) {
            $translator->trans('app.missing_' . $i, [], 'messages', 'es');
        }
        $recorder = self::getContainer()->get(DoctrineMissingTranslationRecorder::class);
        self::assertInstanceOf(DoctrineMissingTranslationRecorder::class, $recorder);
        $recorder->flushBuffer();

        $crawler = $client->request('GET', '/_translation_yaml_tools/missing-log/?status=pending&per_page=25');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertSelectorExists('[data-testid="tyt-missing-log"][data-nowo-ui-page="list"][data-status="pending"]');
        self::assertSelectorTextContains('[data-testid="tyt-missing-log-status-added"]', 'Added');
        self::assertSelectorCount(25, '[data-testid="tyt-missing-log"] tbody tr');
        self::assertSelectorExists('[data-nowo-ui-region="pagination"]');
        self::assertSelectorExists('dialog[id^="nowo-ui-confirm-"]');
        self::assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $html);
        self::assertStringNotContainsString('confirm(', $html);
        self::assertStringNotContainsString('cdn.jsdelivr.net', $html);

        $client->request('GET', '/_translation_yaml_tools/missing-log/?status=pending&per_page=25&page=2');
        self::assertSelectorCount(5, '[data-testid="tyt-missing-log"] tbody tr');

        // Clear current status (pending): the form keeps the status in a hidden field.
        $client->submit($crawler->filter('form[name="missing_log_clear_status"]')->form());
        self::assertResponseRedirects('/_translation_yaml_tools/missing-log/?status=pending');
        $client->followRedirect();
        self::assertSelectorTextContains('.nowo-ui-flash--success', 'Cleared 30 rows with status "pending".');
        self::assertSelectorTextContains('[data-testid="tyt-missing-log"]', 'No rows with status "pending".');

        $translator->trans('app.again', [], 'messages', 'es');
        $recorder->flushBuffer();
        $crawler = $client->request('GET', '/_translation_yaml_tools/missing-log/?status=added');
        self::assertSelectorExists('[data-testid="tyt-missing-log"][data-status="added"]');
        $client->submit($crawler->filter('form[name="missing_log_clear_all"]')->form());
        self::assertResponseRedirects('/_translation_yaml_tools/missing-log/?status=added');
        $client->followRedirect();
        self::assertSelectorTextContains('.nowo-ui-flash--success', 'Cleared 1 missing-log row.');
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
