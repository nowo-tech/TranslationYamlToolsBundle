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
        self::assertSelectorExists('[data-testid="tyt-overrides-index"][data-nowo-ui-page="list"]');
        self::assertSelectorTextContains('[data-testid="tyt-overrides-index"] table', self::KEY);
        self::assertSelectorTextNotContains('[data-testid="tyt-overrides-index"] table', 'site.greeting');
        self::assertStringNotContainsString('admin.title', (string) $client->getResponse()->getContent(), 'Only configured prefixes are listed.');

        $client->request('GET', '/_translation_yaml_tools/texts/?q=no-such-text-anywhere');
        self::assertSelectorTextContains('[data-testid="tyt-overrides-index"]', 'No texts match');
        self::assertSelectorExists('[data-testid="tyt-overrides-index"] form[role="search"] input[type="search"][name="q"][value="no-such-text-anywhere"]');
        self::assertSelectorExists('[data-testid="tyt-overrides-index"] form[role="search"] select[name="group"] option[value="site.topline"]');

        // A stale bookmark with an unknown group still filters (to no row) instead of listing everything.
        $client->request('GET', '/_translation_yaml_tools/texts/?group=no.such.group');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="tyt-overrides-index"]', 'No texts match');

        $crawler = $client->request('GET', '/_translation_yaml_tools/texts/edit?key=' . self::KEY);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="tyt-overrides-edit"][data-nowo-ui-page="form"]');
        self::assertSelectorTextContains('[data-testid="tyt-overrides-key"]', self::KEY);
        self::assertSelectorCount(2, '[data-nowo-ui-locale-tabs] [data-testid^="tyt-overrides-panel-"]');
        self::assertSelectorNotExists('[data-testid="tyt-overrides-reset"]', 'No override yet: no restore action.');
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
        self::assertSelectorTextContains('.nowo-ui-flash--success', 'Text saved.');
        self::assertSelectorExists('[data-testid="tyt-overrides-reset"]');
        self::assertSelectorExists('dialog[id^="nowo-ui-confirm-"]', 'Restore asks for confirmation in the UiKit <dialog>.');
        self::assertSelectorTextContains('[data-testid="tyt-overrides-panel-en"] [data-testid="tyt-overrides-overridden"]', 'edited');

        $client->request('GET', '/_translation_yaml_tools/texts/?q=mon');
        self::assertSelectorTextContains('[data-testid="tyt-overrides-count"]', '1 override.');
        self::assertSelectorTextContains('[data-testid="tyt-overrides-index"] table', 'Open Mon–Thu');
        self::assertSelectorTextContains('[data-testid="tyt-overrides-overridden"]', 'EN');

        // Restore without the confirmation marker is refused.
        $crawler = $client->request('GET', '/_translation_yaml_tools/texts/edit?key=' . self::KEY);
        $reset   = $crawler->filter('form[name="translation_override_reset"]')->form();
        self::assertSame('1', $reset->getValues()['translation_override_reset[confirm]'] ?? null);
        $reset['translation_override_reset[confirm]'] = '';
        $client->submit($reset);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('.nowo-ui-flash--error', 'Confirm the restore');
        self::assertCount(1, $this->entityManager()->getRepository(TranslationOverride::class)->findAll());

        $crawler = $client->request('GET', '/_translation_yaml_tools/texts/edit?key=' . self::KEY);
        $client->submit($crawler->filter('form[name="translation_override_reset"]')->form());
        self::assertResponseRedirects();
        self::assertCount(0, $this->entityManager()->getRepository(TranslationOverride::class)->findAll());
        self::assertSame('Open Mon–Fri', $this->translator()->trans(self::KEY, [], 'messages', 'en'));
    }

    public function testPageFormsUseTheFormKitProfileAndRenderNoRawKeys(): void
    {
        $client = $this->client();

        $profiles = self::getContainer()->getParameter('nowo_form_kit.profiles');
        self::assertIsArray($profiles);
        self::assertSame('NowoTranslationYamlToolsBundle', $profiles['translation_yaml_tools']['translation_domain'] ?? null);

        foreach (['/_translation_yaml_tools/texts/', '/_translation_yaml_tools/texts/edit?key=' . self::KEY] as $url) {
            $client->request('GET', $url);
            self::assertResponseIsSuccessful();
            $html = (string) $client->getResponse()->getContent();
            self::assertDoesNotMatchRegularExpression('/\b(overrides\.[a-z_]+|[a-z_]+\.[a-z_]+\.(label|help|placeholder))\b/', $html, $url . ' renders no raw form translation key.');
        }
        self::assertSelectorTextContains('[data-testid="tyt-overrides-form"]', 'Text (EN)');
    }

    public function testListIsPaginated(): void
    {
        $client = $this->client();

        $client->request('GET', '/_translation_yaml_tools/texts/?per_page=25&page=9');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="tyt-overrides-index"] table', 'site.', 'An out-of-range page is clamped to the last page.');
    }

    public function testDomainPrefixedKeysAndMarkupSanitizing(): void
    {
        $client = $this->client();

        $ref = 'ShopBundle:loader.loading';
        $client->request('GET', '/_translation_yaml_tools/texts/?group=' . rawurlencode('ShopBundle:loader'));
        self::assertSelectorTextContains('[data-testid="tyt-overrides-index"] table', $ref);

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

        $client->request('POST', '/_translation_yaml_tools/texts/reset?key=' . self::KEY, ['translation_override_reset' => ['_token' => 'forged', 'confirm' => '1']]);
        self::assertResponseRedirects('/_translation_yaml_tools/texts/edit?key=' . self::KEY);
        $client->followRedirect();
        self::assertSelectorTextContains('.nowo-ui-flash--error', 'Invalid security token');
        $client->request('POST', '/_translation_yaml_tools/texts/reset?key=' . self::KEY, ['_token' => 'legacy-field-names', 'confirm' => '1']);
        self::assertResponseRedirects('/_translation_yaml_tools/texts/edit?key=' . self::KEY);
        $client->request('POST', '/_translation_yaml_tools/texts/edit?key=' . self::KEY, ['texts' => ['_token' => 'forged', 'en' => 'Hacked']]);
        self::assertResponseRedirects('/_translation_yaml_tools/texts/edit?key=' . self::KEY);
        $client->followRedirect();
        self::assertSelectorTextContains('.nowo-ui-flash--error', 'Invalid security token');
        self::assertCount(0, $this->entityManager()->getRepository(TranslationOverride::class)->findAll());

        $crawler           = $client->request('GET', '/_translation_yaml_tools/texts/edit?key=' . self::KEY);
        $form              = $crawler->filter('[data-testid="tyt-overrides-form"]')->form();
        $form['texts[es]'] = str_repeat('x', 5001) . '%url%'; // too long wins over the placeholder error
        $client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('[data-testid="tyt-overrides-panel-es"][data-invalid]');
        self::assertSelectorTextContains('[data-testid="tyt-overrides-panel-es"]', '5000');
        self::assertCount(0, $this->entityManager()->getRepository(TranslationOverride::class)->findAll());
    }

    public function testRejectsOverridesThatAddOrDropPlaceholders(): void
    {
        $client  = $this->client();
        $crawler = $client->request('GET', '/_translation_yaml_tools/texts/edit?key=' . self::KEY);
        $form    = $crawler->filter('[data-testid="tyt-overrides-form"]')->form();

        $form['texts[en]'] = 'Open Mon–Fri — <a href="%url%">details</a>';
        $client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('[data-testid="tyt-overrides-panel-en"][data-invalid]');
        self::assertSelectorTextContains('[data-testid="tyt-overrides-panel-en"]', 'Keep exactly the placeholders of the shipped text');
        self::assertCount(0, $this->entityManager()->getRepository(TranslationOverride::class)->findAll());
    }

    public function testPagesHaveNoInlineHandlersOrConfirm(): void
    {
        $client = $this->client();
        $client->request('GET', '/_translation_yaml_tools/texts/edit?key=' . self::KEY);
        $html = (string) $client->getResponse()->getContent();

        self::assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $html);
        self::assertStringNotContainsString('confirm(', $html);
        self::assertStringNotContainsString('cdn.jsdelivr.net', $html);
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
