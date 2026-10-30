<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Support;

use DateTimeImmutable;
use Nowo\TranslationYamlToolsBundle\Entity\MissingTranslationLog;
use Nowo\TranslationYamlToolsBundle\Entity\MissingTranslationLogStatus;
use Nowo\TranslationYamlToolsBundle\Form\MissingTranslationLogActionType;
use Nowo\TranslationYamlToolsBundle\Form\TranslationOverrideFilterType;
use Nowo\TranslationYamlToolsBundle\Form\TranslationOverrideResetType;
use Nowo\TranslationYamlToolsBundle\Form\TranslationOverrideTextsType;
use Nowo\TranslationYamlToolsBundle\Ui\MissingTranslationLogPageFactory;
use Nowo\TranslationYamlToolsBundle\Ui\TranslationOverridePageFactory;
use Nowo\TranslationYamlToolsBundle\Ui\WebUiPages;
use Nowo\UiKitBundle\View\Pagination;
use ReflectionProperty;
use Symfony\Component\Form\Extension\Csrf\CsrfExtension;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\FormView;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

use function http_build_query;
use function str_replace;

/**
 * Template contexts of the Web UI pages, built like the controllers build them (REQ-UI-003 contract test and page
 * tests): real CSRF FormViews, the bundle page factories, a path-only URL generator.
 */
final class WebUiPageFixtures
{
    /**
     * Missing-translation log context (status pending: one Mark added form per row).
     *
     * @return array<string, mixed>
     */
    public static function missingLogIndex(MissingTranslationLogStatus $status = MissingTranslationLogStatus::Pending, bool $empty = false, ?WebUiPages $pages = null): array
    {
        $forms = self::forms();
        $rows  = $empty ? [] : self::missingLogRows();
        $mark  = [];
        if ($status === MissingTranslationLogStatus::Pending) {
            foreach ($rows as $row) {
                $id        = (int) $row->getId();
                $mark[$id] = self::actionForm($forms, 'missing_log_mark_added_' . $id, 'missing_log_mark_added', '/missing-log/' . $id . '/mark-added', null);
            }
        }
        $clearStatus = self::actionForm($forms, 'missing_log_clear_status', 'missing_log_clear_status', '/missing-log/clear-status', $status->value);
        $clear       = self::actionForm($forms, 'missing_log_clear_all', 'missing_log_clear', '/missing-log/clear', $status->value);
        $pagination  = new Pagination(1, 50, $empty ? 0 : 120, null, MissingTranslationLogPageFactory::INDEX_ROUTE, ['status' => $status->value]);

        $pages ??= new WebUiPages(WebUiPages::MISSING_LOG);
        $factory = new MissingTranslationLogPageFactory(self::urls(), $pages);

        return $factory->context([
            'page'              => $factory->index($rows, $status, $pagination, $clearStatus, $clear, $mark),
            'rows'              => $rows,
            'status'            => $status->value,
            'clear_status_form' => $clearStatus,
            'clear_form'        => $clear,
            'mark_added_forms'  => $mark,
        ]);
    }

    /**
     * Texts list context.
     *
     * @return array<string, mixed>
     */
    public static function overridesIndex(bool $empty = false, ?WebUiPages $pages = null): array
    {
        $rows = $empty ? [] : [
            ['key' => 'site.topline.hours', 'text' => 'Open Mon–Fri', 'overridden' => ['en', 'es']],
            ['key' => 'ShopBundle:loader.loading', 'text' => 'Loading…', 'overridden' => []],
        ];
        $groups = ['ShopBundle:loader' => 1, 'site.topline' => 1];
        $filter = self::forms()->createNamed('', TranslationOverrideFilterType::class, null, ['action' => '/texts/', 'groups' => $groups]);
        $filter->submit($empty ? ['q' => 'nothing'] : []);
        $view       = $filter->createView();
        $pagination = new Pagination(1, 50, $empty ? 0 : 2, null, TranslationOverridePageFactory::INDEX_ROUTE);
        $factory    = new TranslationOverridePageFactory(self::urls(), $pages ?? new WebUiPages(WebUiPages::OVERRIDES));

        return $factory->context([
            'page'           => $factory->index($rows, $view, $pagination, 'en', $empty),
            'filter_form'    => $view,
            'rows'           => $rows,
            'groups'         => $groups,
            'group'          => '',
            'query'          => $empty ? 'nothing' : '',
            'default_locale' => 'en',
            'override_count' => $empty ? 0 : 2,
        ]);
    }

    /**
     * Edit context (with overrides: the Restore shipped text action; with an error on the "es" text).
     *
     * @return array<string, mixed>
     */
    public static function overridesEdit(bool $overridden = true, bool $invalid = false, ?WebUiPages $pages = null): array
    {
        $forms   = self::forms();
        $locales = ['en', 'es'];
        $ref     = 'site.topline.hours';
        $texts   = ['en' => 'Open Mon–Thu', 'es' => 'Abierto de lunes a viernes'];
        $form    = $forms->createNamed('texts', TranslationOverrideTextsType::class, $texts, [
            'action'        => '/texts/edit?key=' . $ref,
            'locales'       => $locales,
            'max_length'    => 5000,
            'csrf_token_id' => 'nowo_tyt_override_edit_' . $ref,
        ]);
        $errors = [];
        if ($invalid) {
            $form->submit(['en' => $texts['en'], 'es' => 'Too long', '_token' => 'token']);
            $errors['es'] = 'This text is too long (maximum 5000 characters).';
            $form->get('es')->addError(new FormError($errors['es']));
        }
        $overriddenLocales = $overridden ? ['en'] : [];
        $reset             = $overridden ? $forms->create(TranslationOverrideResetType::class, null, [
            'action'        => '/texts/reset?key=' . $ref,
            'csrf_token_id' => 'nowo_tyt_override_reset_' . $ref,
        ])->createView() : null;
        $view    = $form->createView();
        $factory = new TranslationOverridePageFactory(self::urls(), $pages ?? new WebUiPages(WebUiPages::OVERRIDES));

        return $factory->context([
            'page'           => $factory->edit($ref, 'site.topline', $view, $reset, $overriddenLocales),
            'key'            => $ref,
            'group'          => 'site.topline',
            'locales'        => $locales,
            'default_locale' => 'en',
            'active_locale'  => $invalid ? 'es' : 'en',
            'texts'          => $texts,
            'shipped'        => ['en' => 'Open Mon–Fri', 'es' => 'Abierto de lunes a viernes'],
            'errors'         => $errors,
            'max_length'     => 5000,
            'overridden'     => $overriddenLocales,
            'form'           => $view,
            'reset_form'     => $reset,
        ]);
    }

    /**
     * @return list<MissingTranslationLog>
     */
    public static function missingLogRows(): array
    {
        $seen = new DateTimeImmutable('2026-10-29 09:30:00');
        $a    = new MissingTranslationLog('checkout.summary.heading', 'messages', 'es', $seen, 'templates/checkout/summary.html.twig:12', 'app_checkout', 'GET', '/es/checkout');
        $a->registerAdditionalHits(4, $seen->modify('+5 minutes'));
        $b = new MissingTranslationLog('Invalid credentials.', 'security', 'de', $seen);
        foreach ([$a, $b] as $i => $row) {
            (new ReflectionProperty(MissingTranslationLog::class, 'id'))->setValue($row, $i + 1);
        }

        return [$a, $b];
    }

    public static function urls(): UrlGeneratorInterface
    {
        return new class implements UrlGeneratorInterface {
            private RequestContext $context;

            public function __construct()
            {
                $this->context = new RequestContext();
            }

            public function setContext(RequestContext $context): void
            {
                $this->context = $context;
            }

            public function getContext(): RequestContext
            {
                return $this->context;
            }

            /**
             * @param array<string, mixed> $parameters
             */
            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                $query = http_build_query($parameters);

                return '/' . str_replace('_', '-', $name) . ($query !== '' ? '?' . $query : '');
            }
        };
    }

    public static function forms(): FormFactoryInterface
    {
        $tokens = new class implements CsrfTokenManagerInterface {
            public function getToken(string $tokenId): CsrfToken
            {
                return new CsrfToken($tokenId, 'token-' . $tokenId);
            }

            public function refreshToken(string $tokenId): CsrfToken
            {
                return $this->getToken($tokenId);
            }

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function isTokenValid(CsrfToken $token): bool
            {
                return true;
            }
        };

        return Forms::createFormFactoryBuilder()->addExtension(new CsrfExtension($tokens))->getFormFactory();
    }

    private static function actionForm(FormFactoryInterface $forms, string $name, string $tokenId, string $action, ?string $status): FormView
    {
        return $forms->createNamed($name, MissingTranslationLogActionType::class, null, [
            'action'        => $action,
            'csrf_token_id' => $tokenId,
            'status'        => $status,
        ])->createView();
    }
}
