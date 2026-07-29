<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Controller;

use Nowo\TranslationYamlToolsBundle\DependencyInjection\Compiler\MissingLogWebUiSecurityPass;
use Nowo\TranslationYamlToolsBundle\Entity\MissingTranslationLog;
use Nowo\TranslationYamlToolsBundle\Entity\MissingTranslationLogStatus;
use Nowo\TranslationYamlToolsBundle\EventSubscriber\MissingLogUiAccessSubscriber;
use Nowo\TranslationYamlToolsBundle\Repository\MissingTranslationLogRepository;
use Nowo\TranslationYamlToolsBundle\Security\MissingLogUiAccessCheckerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use function is_object;
use function sprintf;

/**
 * Web UI for the missing-translation log (enable missing_translation_log.web_ui.enabled and import bundle routes).
 *
 * Access is enforced with {@see MissingLogUiAccessCheckerInterface} when wired by
 * {@see MissingLogWebUiSecurityPass},
 * in addition to {@see MissingLogUiAccessSubscriber}.
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

        return $this->render('@NowoTranslationYamlToolsBundle/missing_translation_log/index.html.twig', [
            'rows'   => $rows,
            'status' => $status->value,
        ]);
    }

    #[Route('/{id}/mark-added', name: 'nowo_translation_yaml_tools_missing_log_mark_added', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function markAdded(int $id, Request $request): RedirectResponse
    {
        $this->denyUnlessAllowed();

        $token = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid('missing_log_mark_added', $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

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

        $token = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid('missing_log_clear', $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $deleted = $this->repository->clearAll();
        $this->addFlash('success', sprintf('Cleared %d missing-log row(s).', $deleted));

        $statusParam = (string) $request->request->get('status', MissingTranslationLogStatus::Pending->value);
        $status      = MissingTranslationLogStatus::tryFrom($statusParam) ?? MissingTranslationLogStatus::Pending;

        return $this->redirectToRoute('nowo_translation_yaml_tools_missing_log_index', [
            'status' => $status->value,
        ]);
    }

    #[Route('/clear-status', name: 'nowo_translation_yaml_tools_missing_log_clear_status', methods: ['POST'])]
    public function clearStatus(Request $request): RedirectResponse
    {
        $this->denyUnlessAllowed();

        $token = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid('missing_log_clear_status', $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $statusParam = (string) $request->request->get('status', MissingTranslationLogStatus::Pending->value);
        $status      = MissingTranslationLogStatus::tryFrom($statusParam) ?? MissingTranslationLogStatus::Pending;

        $deleted = $this->repository->clearByStatus($status);
        $this->addFlash('success', sprintf('Cleared %d row(s) with status "%s".', $deleted, $status->value));

        return $this->redirectToRoute('nowo_translation_yaml_tools_missing_log_index', [
            'status' => $status->value,
        ]);
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
