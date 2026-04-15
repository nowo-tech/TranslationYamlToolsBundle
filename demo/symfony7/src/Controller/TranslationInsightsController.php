<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\TranslationInsightsBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Demo page: translation paths, default locale, enabled locales, YAML locations, and per-domain gaps.
 */
final class TranslationInsightsController extends AbstractController
{
    public function __construct(
        private readonly TranslationInsightsBuilder $translationInsightsBuilder,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/', name: 'demo_home')]
    public function index(): Response
    {
        if ('dev' === $this->getParameter('kernel.environment')) {
            // Intentionally absent from YAML — exercises missing_translation_log from PHP (see docs/USAGE.md).
            $this->translator->trans('nowo_demo.missing_from_controller', [], 'messages');
        }

        return $this->render('insights/index.html.twig', [
            'report' => $this->translationInsightsBuilder->buildReport(),
            'demo_label' => 'Symfony 7.0',
        ]);
    }
}
