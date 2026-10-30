<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\TextOverride\Controller;

use Nowo\TranslationYamlToolsBundle\Form\TranslationOverrideFilterType;
use Nowo\TranslationYamlToolsBundle\Form\TranslationOverrideResetType;
use Nowo\TranslationYamlToolsBundle\Form\TranslationOverrideTextsType;
use Nowo\TranslationYamlToolsBundle\TextOverride\EditableTranslationKeys;
use Nowo\TranslationYamlToolsBundle\TextOverride\TranslationOverrideEditor;
use Nowo\TranslationYamlToolsBundle\TextOverride\TranslationOverrides;
use Nowo\TranslationYamlToolsBundle\Ui\TranslationOverridePageFactory;
use Nowo\UiKitBundle\View\Pagination;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_key_first;
use function array_map;
use function array_slice;
use function assert;
use function count;
use function implode;
use function is_scalar;
use function strtoupper;

/**
 * Text overrides desk: list editable catalogue messages (group filter + search), edit one message
 * with one tab per locale, reset every locale to the shipped text.
 *
 * Enable with {@code overrides.web_ui.enabled} and import
 * {@code @NowoTranslationYamlToolsBundle/Resources/config/routes/translation_override_ui.yaml}.
 * Access: {@code overrides.web_ui.security.access_roles} (any role), CSRF on every POST.
 *
 * Forms: {@see TranslationOverrideFilterType} (GET, unnamed: query {@code q} / {@code group}),
 * {@see TranslationOverrideTextsType} (named {@code texts}: fields {@code texts[<locale>]}) and
 * {@see TranslationOverrideResetType} (named {@code translation_override_reset}).
 *
 * Pages (REQ-UI-003): UiKit list / form scaffolds fed by {@see TranslationOverridePageFactory} (templates
 * {@code overrides.web_ui.templates.index|edit}, layout {@code overrides.web_ui.layout_template}, null = UiKit).
 */
final class TranslationOverrideController extends AbstractController
{
    public const TRANSLATION_DOMAIN = 'NowoTranslationYamlToolsBundle';

    private const TRUNCATE = 120;

    /** Rows per page of the texts list (query {@code per_page}: one of {@see self::PER_PAGE}). */
    private const DEFAULT_PER_PAGE = 50;

    private const PER_PAGE = [25, 50, 100];

    /**
     * @param list<string> $accessRoles
     */
    public function __construct(
        private readonly TranslationOverrides $overrides,
        private readonly TranslationOverrideEditor $editor,
        private readonly EditableTranslationKeys $keys,
        private readonly TranslatorInterface&TranslatorBagInterface $translator,
        private readonly TranslationOverridePageFactory $pages,
        private readonly array $accessRoles = ['ROLE_ADMIN'],
        private readonly bool $allowUnauthenticated = false,
        private readonly int $maxLength = 5000,
    ) {
    }

    #[Route('', name: 'nowo_translation_yaml_tools_overrides_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyUnlessAllowed();

        $overrides = $this->overrides->all();
        $locales   = $this->editor->locales();

        $groups   = [];
        $messages = [];
        foreach ($this->keys->editableMessages($this->translator->getCatalogue($this->editor->defaultLocale())) as $ref => $text) {
            [$domain, $key]    = $this->keys->parseRef($ref);
            $groupKey          = $this->keys->groupOf($domain, $key);
            $groups[$groupKey] = ($groups[$groupKey] ?? 0) + 1;
            $messages[]        = [$ref, $domain, $key, $groupKey, $text];
        }
        ksort($groups);

        $filter = $this->formFactory()->createNamed('', TranslationOverrideFilterType::class, null, [
            'action' => $this->generateUrl('nowo_translation_yaml_tools_overrides_index'),
            'groups' => $groups,
        ]);
        $filter->handleRequest($request);
        $rawQuery = trim((string) $filter->get('q')->getData());
        $query    = mb_strtolower($rawQuery);
        // View data: an unknown group (stale bookmark) still filters (to no row) instead of being dropped.
        $groupValue = $filter->get('group')->getViewData();
        $group      = trim(is_scalar($groupValue) ? (string) $groupValue : '');

        $rows = [];
        foreach ($messages as [$ref, $domain, $key, $groupKey, $text]) {
            if ($group !== '' && $groupKey !== $group) {
                continue;
            }
            $current = $overrides[$this->editor->defaultLocale()][$domain][$key] ?? $text;
            if ($query !== '' && !str_contains(mb_strtolower($ref . ' ' . $current), $query)) {
                continue;
            }

            $rows[] = [
                'key'        => $ref,
                'text'       => mb_strlen($current) > self::TRUNCATE ? mb_substr($current, 0, self::TRUNCATE - 1) . '…' : $current,
                'overridden' => array_values(array_filter(
                    $locales,
                    static fn (string $locale): bool => isset($overrides[$locale][$domain][$key]),
                )),
            ];
        }

        $overrideCount = 0;
        foreach ($overrides as $domains) {
            foreach ($domains as $domain => $texts) {
                foreach (array_keys($texts) as $key) {
                    if ($this->keys->isEditable($domain, (string) $key)) {
                        ++$overrideCount;
                    }
                }
            }
        }

        $pagination = Pagination::fromRequest($request, count($rows), self::DEFAULT_PER_PAGE, self::PER_PAGE, 'nowo_translation_yaml_tools_overrides_index');
        $pageRows   = array_slice($rows, $pagination->offset(), $pagination->limit());
        $filterView = $filter->createView();

        return $this->render($this->pages->template('index'), $this->pages->context([
            'page'           => $this->pages->index($pageRows, $filterView, $pagination, $this->editor->defaultLocale(), $rawQuery !== '' || $group !== ''),
            'filter_form'    => $filterView,
            'rows'           => $pageRows,
            'groups'         => $groups,
            'group'          => $group,
            'query'          => $rawQuery,
            'default_locale' => $this->editor->defaultLocale(),
            'override_count' => $overrideCount,
        ]));
    }

    #[Route('/edit', name: 'nowo_translation_yaml_tools_overrides_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        $this->denyUnlessAllowed();

        $ref            = $request->query->getString('key');
        [$domain, $key] = $this->resolveEditable($ref);
        $shipped        = $this->editor->shippedTexts($domain, $key);
        $texts          = $this->editor->currentTexts($domain, $key);
        $locales        = $this->editor->locales();
        $errors         = [];

        $form = $this->formFactory()->createNamed('texts', TranslationOverrideTextsType::class, $texts, [
            'action'        => $this->generateUrl('nowo_translation_yaml_tools_overrides_edit', ['key' => $ref]),
            'locales'       => $locales,
            'max_length'    => $this->maxLength,
            'csrf_token_id' => $this->csrfId('edit', $ref),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            // Without symfony/validator the only root-level error is the CSRF one.
            if ($form->getErrors()->count() > 0) {
                $this->addFlash('error', $this->t('overrides.flash.invalid_token'));

                return $this->redirectToRoute('nowo_translation_yaml_tools_overrides_edit', ['key' => $ref]);
            }

            foreach ($locales as $locale) {
                $value          = $form->get($locale)->getData();
                $texts[$locale] = is_scalar($value) ? (string) $value : '';
                if (mb_strlen($texts[$locale]) > $this->maxLength) {
                    $errors[$locale] = $this->t('overrides.error.too_long', ['%max%' => $this->maxLength]);
                    $form->get($locale)->addError(new FormError($errors[$locale]));
                }
            }

            if ($errors === [] && $form->isValid()) {
                $this->editor->save($domain, $key, $texts, true, $this->userIdentifier());
                $this->addFlash('success', $this->t('overrides.flash.saved'));

                return $this->redirectToRoute('nowo_translation_yaml_tools_overrides_edit', ['key' => $ref]);
            }
        }

        $overridden = $this->editor->overriddenLocales($domain, $key);
        $group      = $this->keys->groupOf($domain, $key);
        $formView   = $form->createView();
        $resetView  = $overridden === [] ? null : $this->resetForm($ref, $overridden)->createView();

        return $this->render($this->pages->template('edit'), $this->pages->context([
            'page'           => $this->pages->edit($ref, $group, $formView, $resetView, $overridden),
            'key'            => $ref,
            'group'          => $group,
            'locales'        => $locales,
            'default_locale' => $this->editor->defaultLocale(),
            'active_locale'  => $errors !== [] ? array_key_first($errors) : ($locales[0] ?? null),
            'texts'          => $texts,
            'shipped'        => $shipped,
            'errors'         => $errors,
            'max_length'     => $this->maxLength,
            'overridden'     => $overridden,
            'form'           => $formView,
            'reset_form'     => $resetView,
        ]), new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/reset', name: 'nowo_translation_yaml_tools_overrides_reset', methods: ['POST'])]
    public function reset(Request $request): RedirectResponse
    {
        $this->denyUnlessAllowed();

        $ref            = $request->query->getString('key');
        [$domain, $key] = $this->resolveEditable($ref);

        $form = $this->resetForm($ref, $this->editor->overriddenLocales($domain, $key));
        $form->handleRequest($request);

        if (!$form->isSubmitted() || $form->getErrors()->count() > 0) {
            $this->addFlash('error', $this->t('overrides.flash.invalid_token'));
        } elseif ($form->get('confirm')->getData() !== TranslationOverrideResetType::CONFIRMED) {
            $this->addFlash('error', $this->t('overrides.flash.confirm_required'));
        } else {
            $this->editor->reset($domain, $key);
            $this->addFlash('success', $this->t('overrides.flash.reset'));
        }

        return $this->redirectToRoute('nowo_translation_yaml_tools_overrides_edit', ['key' => $ref]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveEditable(string $ref): array
    {
        [$domain, $key] = $this->keys->parseRef($ref);
        if (!$this->keys->isEditable($domain, $key) || !$this->translator->getCatalogue($this->editor->defaultLocale())->has($key, $domain)) {
            throw new NotFoundHttpException('Unknown or non-editable translation key.');
        }

        return [$domain, $key];
    }

    /**
     * @param list<string> $overridden
     *
     * @return FormInterface<mixed>
     */
    private function resetForm(string $ref, array $overridden): FormInterface
    {
        return $this->formFactory()->create(TranslationOverrideResetType::class, null, [
            'action'             => $this->generateUrl('nowo_translation_yaml_tools_overrides_reset', ['key' => $ref]),
            'csrf_token_id'      => $this->csrfId('reset', $ref),
            'overridden_locales' => implode(', ', array_map(strtoupper(...), $overridden)),
        ]);
    }

    private function formFactory(): FormFactoryInterface
    {
        $factory = $this->container->get('form.factory');
        assert($factory instanceof FormFactoryInterface);

        return $factory;
    }

    private function csrfId(string $action, string $ref): string
    {
        return 'nowo_tyt_override_' . $action . '_' . $ref;
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private function t(string $id, array $parameters = []): string
    {
        return $this->translator->trans($id, $parameters, self::TRANSLATION_DOMAIN);
    }

    private function denyUnlessAllowed(): void
    {
        if ($this->allowUnauthenticated) {
            return;
        }

        if (!$this->container->has('security.authorization_checker')) {
            throw $this->createAccessDeniedException('Translation overrides UI requires SecurityBundle (or overrides.web_ui.security.allow_unauthenticated in dev).');
        }

        foreach ($this->accessRoles as $role) {
            if ($this->isGranted($role)) {
                return;
            }
        }

        throw $this->createAccessDeniedException('Translation overrides UI: none of the configured access roles is granted.');
    }

    private function userIdentifier(): ?string
    {
        if (!$this->container->has('security.token_storage')) {
            return null;
        }

        return $this->getUser()?->getUserIdentifier();
    }
}
