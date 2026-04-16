<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Controller;

use Nowo\TranslationYamlToolsBundle\Entity\MissingTranslationLogStatus;
use Nowo\TranslationYamlToolsBundle\Repository\MissingTranslationLogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use function sprintf;

/**
 * Web UI for the missing-translation log (enable missing_translation_log.web_ui.enabled and import bundle routes).
 */
final class MissingTranslationLogUiController extends AbstractController
{
    public function __construct(
        private readonly MissingTranslationLogRepository $repository,
    ) {
    }

    #[Route('', name: 'nowo_translation_yaml_tools_missing_log_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $statusParam = (string) $request->query->get('status', MissingTranslationLogStatus::Pending->value);
        $status      = MissingTranslationLogStatus::tryFrom($statusParam) ?? MissingTranslationLogStatus::Pending;
        $rows        = $this->repository->findByStatus($status, 500);

        return $this->render('@NowoTranslationYamlToolsBundle/missing_translation_log/index.html.twig', [
            'rows'   => $rows,
            'status' => $status->value,
        ]);
    }

    #[Route('/{id}/mark-added', name: 'nowo_translation_yaml_tools_missing_log_mark_added', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function markAdded(int $id, Request $request): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $token = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid('missing_log_mark_added', $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $row = $this->repository->findOneById($id);
        if (!$row instanceof \Nowo\TranslationYamlToolsBundle\Entity\MissingTranslationLog) {
            throw $this->createNotFoundException(sprintf('Missing translation log row %d not found.', $id));
        }

        $row->setStatus(MissingTranslationLogStatus::Added);
        $this->repository->getEntityManager()->flush();

        $this->addFlash('success', sprintf('Row #%d marked as added.', $id));

        return $this->redirectToRoute('nowo_translation_yaml_tools_missing_log_index', [
            'status' => MissingTranslationLogStatus::Pending->value,
        ]);
    }

    #[Route('/clear', name: 'nowo_translation_yaml_tools_missing_log_clear', methods: ['POST'])]
    public function clear(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse
    {
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
    public function clearStatus(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse
    {
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
}
