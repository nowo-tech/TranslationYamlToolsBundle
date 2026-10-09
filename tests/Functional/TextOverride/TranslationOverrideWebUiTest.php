<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Functional\TextOverride;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\TranslationYamlToolsBundle\Tests\Kernel\TextOverrideTestKernel;
use Nowo\TranslationYamlToolsBundle\TextOverride\Controller\TranslationOverrideController;
use Nowo\TranslationYamlToolsBundle\TextOverride\Entity\TranslationOverride;
use Nowo\TranslationYamlToolsBundle\TextOverride\OverridingTranslator;
use Nowo\TranslationYamlToolsBundle\TextOverride\TranslationOverrides;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(TranslationOverrideController::class)]
final class TranslationOverrideWebUiTest extends WebTestCase
{
    private const KEY = 'site.topline.hours';

    protected static function getKernelClass(): string
    {
        return TextOverrideTestKernel::class;
    }

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        parent::setUp();
    }

    public function testOverrideRoundTripThroughTheDesk(): void
    {
        $client = $this->client();

        $client->request('GET', '/_translation_yaml_tools/texts/?group=site.topline');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="tyt-overrides-table"]', self::KEY);
        self::assertSelectorTextNotContains('[data-testid="tyt-overrides-table"]', 'site.greeting');
        self::assertStringNotContainsString('admin.title', (string) $client->getResponse()->getContent(), 'Only configured prefixes are listed.');

        $client->request('GET', '/_translation_yaml_tools/texts/?q=no-such-text-anywhere');
        self::assertSelectorTextContains('[data-testid="tyt-overrides-table"]', 'No texts match');

        $crawler = $client->request('GET', '/_translation_yaml_tools/texts/edit?key=' . self::KEY);
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(2, '.nowo-tyt-tab-panel');
        $form = $crawler->filter('[data-testid="tyt-overrides-form"]')->form();
        self::assertSame('Abierto de lunes a viernes', $form->getValues()['texts[es]'] ?? null);
        $form['texts[en]'] = 'Open Mon–Thu';
        $client->submit($form);
        self::assertResponseRedirects('/_translation_yaml_tools/texts/edit?key=' . self::KEY);

        $rows = $this->entityManager()->getRepository(TranslationOverride::class)->findAll();
        self::assertCount(1, $rows, 'Unchanged locale (es) creates no row.');
        self::assertSame('en', $rows[0]->getLocale());
        self::assertSame('Open Mon–Thu', $this->translator()->trans(self::KEY, [], 'messages', 'en'));
        self::assertSame('Abierto de lunes a viernes', $this->translator()->trans(self::KEY, [], 'messages', 'es'));

        $client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Text saved.');
        self::assertSelectorExists('[data-testid="tyt-overrides-reset"]');

        $client->request('GET', '/_translation_yaml_tools/texts/?q=mon');
        self::assertSelectorTextContains('[data-testid="tyt-overrides-count"]', '1 override.');
        self::assertSelectorTextContains('[data-testid="tyt-overrides-table"]', 'Open Mon–Thu');
        self::assertSelectorTextContains('[data-testid="tyt-overrides-table"] .badge', 'EN');

        // Reset without the confirmation box is refused.
        $crawler = $client->request('GET', '/_translation_yaml_tools/texts/edit?key=' . self::KEY);
        $reset   = $crawler->filter('[data-testid="tyt-overrides-reset"]')->form();
        $confirm = $reset->get('confirm');
        self::assertInstanceOf(ChoiceFormField::class, $confirm);
        $confirm->untick();
        $client->submit($reset);
        self::assertResponseRedirects();
        self::assertCount(1, $this->entityManager()->getRepository(TranslationOverride::class)->findAll());

        $crawler = $client->request('GET', '/_translation_yaml_tools/texts/edit?key=' . self::KEY);
        $reset   = $crawler->filter('[data-testid="tyt-overrides-reset"]')->form();
        $confirm = $reset->get('confirm');
        self::assertInstanceOf(ChoiceFormField::class, $confirm);
        $confirm->tick();
        $client->submit($reset);
        self::assertResponseRedirects();
        self::assertCount(0, $this->entityManager()->getRepository(TranslationOverride::class)->findAll());
        self::assertSame('Open Mon–Fri', $this->translator()->trans(self::KEY, [], 'messages', 'en'));
    }

    public function testDomainPrefixedKeysAndMarkupSanitizing(): void
    {
        $client = $this->client();

        $ref = 'ShopBundle:loader.loading';
        $client->request('GET', '/_translation_yaml_tools/texts/?group=' . rawurlencode('ShopBundle:loader'));
        self::assertSelectorTextContains('[data-testid="tyt-overrides-table"]', $ref);

        $crawler = $client->request('GET', '/_translation_yaml_tools/texts/edit?key=' . rawurlencode($ref));
        self::assertResponseIsSuccessful();
        $form              = $crawler->filter('[data-testid="tyt-overrides-form"]')->form();
        $form['texts[en]'] = 'One <a href="javascript:alert(1)" onclick="x()">moment</a><script>alert(2)</script>';
        $client->submit($form);
        self::assertResponseRedirects();

        $rows = $this->entityManager()->getRepository(TranslationOverride::class)->findAll();
        self::assertCount(1, $rows);
        self::assertSame('ShopBundle', $rows[0]->getDomain());
        self::assertSame('loader.loading', $rows[0]->getMessageKey());
        $text = $this->translator()->trans('loader.loading', [], 'ShopBundle', 'en');
        self::assertStringStartsWith('One <a', $text);
        self::assertStringNotContainsString('javascript:', $text);
        self::assertStringNotContainsString('onclick', $text);
        self::assertStringNotContainsString('script', $text);
    }

    public function testRejectsNonEditableKeysForgedTokensAndTooLongTexts(): void
    {
        $client = $this->client();

        foreach (['admin.title', 'site.does.not.exist', 'ShopBundle:admin.title', 'security:Invalid credentials.'] as $ref) {
            $client->request('GET', '/_translation_yaml_tools/texts/edit?key=' . rawurlencode($ref));
            self::assertResponseStatusCodeSame(404, $ref);
        }

        $client->request('POST', '/_translation_yaml_tools/texts/reset?key=' . self::KEY, ['_token' => 'forged', 'confirm' => '1']);
        self::assertResponseRedirects('/_translation_yaml_tools/texts/edit?key=' . self::KEY);
        $client->request('POST', '/_translation_yaml_tools/texts/edit?key=' . self::KEY, ['_token' => 'forged', 'texts' => ['en' => 'Hacked']]);
        self::assertResponseRedirects('/_translation_yaml_tools/texts/edit?key=' . self::KEY);
        self::assertCount(0, $this->entityManager()->getRepository(TranslationOverride::class)->findAll());

        $crawler           = $client->request('GET', '/_translation_yaml_tools/texts/edit?key=' . self::KEY);
        $form              = $crawler->filter('[data-testid="tyt-overrides-form"]')->form();
        $form['texts[es]'] = str_repeat('x', 5001);
        $client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#nowo-tyt-tab-2[checked]');
        self::assertSelectorTextContains('.invalid-feedback', '5000');
        self::assertCount(0, $this->entityManager()->getRepository(TranslationOverride::class)->findAll());
    }

    public function testInlineStyleCarriesCspNonce(): void
    {
        $client     = $this->client();
        $dispatcher = $client->getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener('kernel.request', static function (RequestEvent $event): void {
            $event->getRequest()->attributes->set('csp_nonce', 'n0nc3');
        });

        $client->request('GET', '/_translation_yaml_tools/texts/edit?key=' . self::KEY);
        self::assertSelectorExists('style[nonce="n0nc3"]');
        self::assertStringNotContainsString('<script', (string) $client->getResponse()->getContent());
    }

    private function client(): KernelBrowser
    {
        $client = self::createClient();
        $em     = $this->entityManager();
        $tool   = new SchemaTool($em);
        $meta   = $em->getClassMetadata(TranslationOverride::class);
        $tool->dropSchema([$meta]);
        $tool->createSchema([$meta]);
        self::assertSame('nowo_translation_override', $meta->getTableName());

        $overrides = self::getContainer()->get(TranslationOverrides::class);
        self::assertInstanceOf(TranslationOverrides::class, $overrides);
        $overrides->invalidate();

        return $client;
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->clear();

        return $em;
    }

    private function translator(): TranslatorInterface
    {
        $translator = self::getContainer()->get('translator');
        self::assertInstanceOf(OverridingTranslator::class, $translator);
        $overrides = self::getContainer()->get(TranslationOverrides::class);
        self::assertInstanceOf(TranslationOverrides::class, $overrides);
        $overrides->reset();

        return $translator;
    }
}
