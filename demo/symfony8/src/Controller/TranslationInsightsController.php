<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\TranslationInsightsBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Demo page: translation paths, default locale, enabled locales, YAML locations, and per-domain gaps.
 */
final class TranslationInsightsController extends AbstractController
{
    public function __construct(
        private readonly TranslationInsightsBuilder $translationInsightsBuilder,
    ) {
    }

    #[Route('/', name: 'demo_home')]
    public function index(): Response
    {
        return $this->render('insights/index.html.twig', [
            'report' => $this->translationInsightsBuilder->buildReport(),
            'demo_label' => 'Symfony 8.0',
        ]);
    }
}
