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
    private const SCENARIO_TWIG = 'twig';
    private const SCENARIO_DOMAIN_LOCALE = 'domain-locale';
    private const SCENARIO_REPEAT_HITS = 'repeat-hits';

    public function __construct(
        private readonly TranslationInsightsBuilder $translationInsightsBuilder,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/', name: 'demo_home')]
    public function index(): Response
    {
        $this->runHomeProbesIfDev();

        return $this->render('insights/index.html.twig', [
            'report' => $this->translationInsightsBuilder->buildReport(),
            'demo_label' => 'Symfony 8.0',
        ]);
    }

    #[Route('/missing-log/probes/{scenario}', name: 'demo_missing_log_probes', requirements: ['scenario' => 'twig|domain-locale|repeat-hits'])]
    public function missingLogProbes(string $scenario): Response
    {
        $title = match ($scenario) {
            self::SCENARIO_TWIG => 'Twig calls and trans tag',
            self::SCENARIO_DOMAIN_LOCALE => 'Custom domains and forced locales',
            self::SCENARIO_REPEAT_HITS => 'Repeat hits and counters',
            default => 'Missing-log probes',
        };

        if ('dev' === $this->getParameter('kernel.environment')) {
            if ($scenario === self::SCENARIO_DOMAIN_LOCALE) {
                $this->translator->trans('nowo_demo.probe_domain_locale.controller.messages', ['%sku%' => 'B-20'], 'messages');
                $this->translator->trans('nowo_demo.probe_domain_locale.controller.custom_domain', [], 'demo_runtime');
                $this->translator->trans('nowo_demo.probe_domain_locale.controller.forced_es', [], 'messages', 'es');
                $this->translator->trans('nowo_demo.probe_domain_locale.controller.forced_fr', [], 'messages', 'fr');
            } elseif ($scenario === self::SCENARIO_REPEAT_HITS) {
                for ($i = 0; $i < 4; ++$i) {
                    $this->translator->trans('nowo_demo.probe_repeat.controller_key', [], 'messages');
                }
                for ($i = 0; $i < 2; ++$i) {
                    $this->translator->trans('nowo_demo.probe_repeat.controller_custom_domain', [], 'demo_runtime');
                }
            }
        }

        return $this->render('insights/missing_log_playground.html.twig', [
            'scenario' => $scenario,
            'scenario_title' => $title,
            'demo_label' => 'Symfony 8.0',
        ]);
    }

    private function runHomeProbesIfDev(): void
    {
        if ('dev' !== $this->getParameter('kernel.environment')) {
            return;
        }

        // Intentionally absent from YAML — exercises missing_translation_log from PHP (see docs/USAGE.md).
        $this->translator->trans('nowo_demo.missing_from_controller', [], 'messages');
        $this->translator->trans('nowo_demo.missing_from_controller_with_params', ['%sku%' => 'A-42'], 'messages');
        $this->translator->trans('nowo_demo.missing_in_custom_domain', [], 'demo_runtime');
        $this->translator->trans('nowo_demo.missing_forced_locale', [], 'messages', 'fr');
    }
}
