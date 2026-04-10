<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Translation;

use InvalidArgumentException;
use Nowo\TranslationYamlToolsBundle\MissingTranslationLog\MissingTranslationRecorderInterface;
use Symfony\Component\Translation\MessageCatalogueInterface;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function sprintf;

/**
 * Wraps the Symfony translator and persists rows when a key is missing from the catalogue for the requested locale.
 */
final class RecordingTranslatorDecorator implements TranslatorInterface, TranslatorBagInterface, LocaleAwareInterface
{
    public function __construct(
        private TranslatorInterface $inner,
        private readonly MissingTranslationRecorderInterface $recorder,
        private readonly bool $recordCallSite = true,
    ) {
        if (!$inner instanceof TranslatorBagInterface || !$inner instanceof LocaleAwareInterface) {
            throw new InvalidArgumentException(sprintf('The decorated translator must implement %s and %s.', TranslatorBagInterface::class, LocaleAwareInterface::class));
        }
    }

    /**
     * {@inheritdoc}
     */
    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        $domain ??= 'messages';
        $effectiveLocale = $locale ?? $this->inner->getLocale();

        $catalogue = $this->inner->getCatalogue($effectiveLocale);
        if (!$catalogue->defines($id, $domain)) {
            $callSite = $this->recordCallSite ? TranslationCallSiteResolver::resolve() : null;
            $this->recorder->record($id, $domain, $effectiveLocale, $callSite);
        }

        return $this->inner->trans($id, $parameters, $domain, $locale);
    }

    /**
     * {@inheritdoc}
     */
    public function getLocale(): string
    {
        return $this->inner->getLocale();
    }

    /**
     * {@inheritdoc}
     */
    public function setLocale(string $locale): void
    {
        $this->inner->setLocale($locale);
    }

    /**
     * {@inheritdoc}
     */
    public function getCatalogue(?string $locale = null): MessageCatalogueInterface
    {
        return $this->inner->getCatalogue($locale);
    }

    /**
     * {@inheritdoc}
     */
    public function getCatalogues(): array
    {
        return $this->inner->getCatalogues();
    }

    /**
     * {@inheritdoc}
     */
    public function getFallbackLocales(): array
    {
        return $this->inner->getFallbackLocales();
    }
}
