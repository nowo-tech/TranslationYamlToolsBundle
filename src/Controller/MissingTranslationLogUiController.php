<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Controller;

use Nowo\TranslationYamlToolsBundle\DependencyInjection\Compiler\MissingLogWebUiSecurityPass;
use Nowo\TranslationYamlToolsBundle\Entity\MissingTranslationLog;
use Nowo\TranslationYamlToolsBundle\Entity\MissingTranslationLogStatus;
use Nowo\TranslationYamlToolsBundle\EventSubscriber\MissingLogUiAccessSubscriber;
use Nowo\TranslationYamlToolsBundle\Form\MissingTranslationLogActionType;
use Nowo\TranslationYamlToolsBundle\Repository\MissingTranslationLogRepository;
use Nowo\TranslationYamlToolsBundle\Security\MissingLogUiAccessCheckerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use function assert;
use function is_object;
use function is_string;
use function sprintf;

/**
 * Web UI for the missing-translation log (enable missing_translation_log.web_ui.enabled and import bundle routes).
 *
 * Access is enforced with {@see MissingLogUiAccessCheckerInterface} when wired by
 * {@see MissingLogWebUiSecurityPass},
 * in addition to {@see MissingLogUiAccessSubscriber}.
 *
 * Every POST action is a {@see MissingTranslationLogActionType} form (CSRF on, rendered with
 * {@code form_start} / child loop / {@code form_end}); the form names are
 * {@code missing_log_clear_status}, {@code missing_log_clear_all} and {@code missing_log_mark_added_<id>}.
 */
final class MissingTranslationLogUiController extends AbstractController
{
    public function __construct(
        private readonly MissingTranslationLogRepository $repository,
        private readonly ?MissingLogUiAccessCheckerInterface $accessChecker = null,
    ) {
    }

    #[Route('', name: 'nowo_translation_yaml_tools_missing_log_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyUnlessAllowed();

        $statusParam = (string) $request->query->get('status', MissingTranslationLogStatus::Pending->value);
        $status      = MissingTranslationLogStatus::tryFrom($statusParam) ?? MissingTranslationLogStatus::Pending;
        $rows        = $this->repository->findByStatus($status, 500);

        $markAddedForms = [];
        if ($status === MissingTranslationLogStatus::Pending) {
            foreach ($rows as $row) {
                $id                  = (int) $row->getId();
                $markAddedForms[$id] = $this->markAddedForm($id)->createView();
            }
        }

        return $this->render('@NowoTranslationYamlToolsBundle/missing_translation_log/index.html.twig', [
            'rows'              => $rows,
            'status'            => $status->value,
            'clear_status_form' => $this->clearStatusForm($status->value)->createView(),
            'clear_form'        => $this->clearForm($status->value)->createView(),
            'mark_added_forms'  => $markAddedForms,
        ]);
    }

    #[Route('/{id}/mark-added', name: 'nowo_translation_yaml_tools_missing_log_mark_added', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function markAdded(int $id, Request $request): RedirectResponse
    {
        $this->denyUnlessAllowed();

        $this->assertSubmittedAndValid($this->markAddedForm($id), $request);

        $row = $this->repository->findOneById($id);
        if (!$row instanceof MissingTranslationLog) {
            throw $this->createNotFoundException(sprintf('Missing translation log row %d not found.', $id));
        }

        $row->setStatus(MissingTranslationLogStatus::Added);
        $this->repository->flush();

        $this->addFlash('success', sprintf('Row #%d marked as added.', $id));

        return $this->redirectToRoute('nowo_translation_yaml_tools_missing_log_index', [
            'status' => MissingTranslationLogStatus::Pending->value,
        ]);
    }

    #[Route('/clear', name: 'nowo_translation_yaml_tools_missing_log_clear', methods: ['POST'])]
    public function clear(Request $request): RedirectResponse
    {
        $this->denyUnlessAllowed();

        $form = $this->clearForm(MissingTranslationLogStatus::Pending->value);
        $this->assertSubmittedAndValid($form, $request);

        // @igor-ignore - HTTP handler delegates to services; no controller worker state.
        $deleted = $this->repository->clearAll();
        $this->addFlash('success', sprintf('Cleared %d missing-log row(s).', $deleted));

        $status = $this->submittedStatus($form);

        return $this->redirectToRoute('nowo_translation_yaml_tools_missing_log_index', [
            'status' => $status->value,
        ]);
    }

    #[Route('/clear-status', name: 'nowo_translation_yaml_tools_missing_log_clear_status', methods: ['POST'])]
    public function clearStatus(Request $request): RedirectResponse
    {
        $this->denyUnlessAllowed();

        $form = $this->clearStatusForm(MissingTranslationLogStatus::Pending->value);
        $this->assertSubmittedAndValid($form, $request);

        $status = $this->submittedStatus($form);

        // @igor-ignore - HTTP handler delegates to services; no controller worker state.
        $deleted = $this->repository->clearByStatus($status);
        $this->addFlash('success', sprintf('Cleared %d row(s) with status "%s".', $deleted, $status->value));

        return $this->redirectToRoute('nowo_translation_yaml_tools_missing_log_index', [
            'status' => $status->value,
        ]);
    }

    /**
     * @return FormInterface<mixed>
     */
    private function markAddedForm(int $id): FormInterface
    {
        return $this->actionForm('missing_log_mark_added_' . $id, 'missing_log_mark_added', 'nowo_translation_yaml_tools_missing_log_mark_added', ['id' => $id], null);
    }

    /**
     * @return FormInterface<mixed>
     */
    private function clearForm(string $status): FormInterface
    {
        return $this->actionForm('missing_log_clear_all', 'missing_log_clear', 'nowo_translation_yaml_tools_missing_log_clear', [], $status);
    }

    /**
     * @return FormInterface<mixed>
     */
    private function clearStatusForm(string $status): FormInterface
    {
        return $this->actionForm('missing_log_clear_status', 'missing_log_clear_status', 'nowo_translation_yaml_tools_missing_log_clear_status', [], $status);
    }

    /**
     * @param array<string, int> $routeParameters
     *
     * @return FormInterface<mixed>
     */
    private function actionForm(string $name, string $csrfTokenId, string $route, array $routeParameters, ?string $status): FormInterface
    {
        $factory = $this->container->get('form.factory');
        assert($factory instanceof FormFactoryInterface);

        return $factory->createNamed($name, MissingTranslationLogActionType::class, null, [
            'action'        => $this->generateUrl($route, $routeParameters),
            'csrf_token_id' => $csrfTokenId,
            'status'        => $status,
        ]);
    }

    /**
     * Binds the POST to the action form; a missing or invalid CSRF token is a 403 (as before the Form migration).
     *
     * @param FormInterface<mixed> $form
     */
    private function assertSubmittedAndValid(FormInterface $form, Request $request): void
    {
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function submittedStatus(FormInterface $form): MissingTranslationLogStatus
    {
        $status = $form->get('status')->getData();

        return MissingTranslationLogStatus::tryFrom(is_string($status) ? $status : '') ?? MissingTranslationLogStatus::Pending;
    }

    /**
     * Enforces {@see MissingLogUiAccessCheckerInterface} when SecurityBundle wiring is present
     * (defense in depth vs {@see MissingLogUiAccessSubscriber}). Skips when the checker is not injected
     * ({@code allow_unauthenticated} or empty {@code access_roles} without a custom checker).
     */
    private function denyUnlessAllowed(): void
    {
        if (!$this->accessChecker instanceof MissingLogUiAccessCheckerInterface) {
            return;
        }

        $user = $this->getUser();
        if (!is_object($user) || !$this->accessChecker->canAccess($user)) {
            throw $this->createAccessDeniedException(sprintf('Missing-log UI requires an authenticated user allowed by %s.', MissingLogUiAccessCheckerInterface::class));
        }
    }
}
