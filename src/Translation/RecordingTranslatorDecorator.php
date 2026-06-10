<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Translation;

use InvalidArgumentException;
use Nowo\TranslationYamlToolsBundle\MissingTranslationLog\MissingTranslationRecorderInterface;
use Symfony\Component\HttpKernel\CacheWarmer\WarmableInterface;
use Symfony\Component\Translation\MessageCatalogueInterface;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function sprintf;

/**
 * Wraps the Symfony translator and persists rows when a key is missing from the catalogue for the requested locale.
 */
final class RecordingTranslatorDecorator implements TranslatorInterface, TranslatorBagInterface, LocaleAwareInterface, WarmableInterface
{
    public function __construct(
        private TranslatorInterface $inner,
        private readonly MissingTranslationRecorderInterface $recorder,
        private readonly MissingTranslationLogCallSiteBuilder $callSiteBuilder,
        private readonly bool $recordCallSite = true,
        private readonly bool $recordRequestContext = true,
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
            $ctx = $this->callSiteBuilder->buildContext($this->recordCallSite, $this->recordRequestContext);
            $this->recorder->record(
                $id,
                $domain,
                $effectiveLocale,
                $ctx->callSite,
                $ctx->requestRoute,
                $ctx->requestMethod,
                $ctx->requestPath,
            );
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

    /**
     * {@inheritdoc}
     */
    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        if ($this->inner instanceof WarmableInterface) {
            return $this->inner->warmUp($cacheDir, $buildDir);
        }

        return [];
    }

    /**
     * Forwards calls to methods on the decorated translator implementation
     * (e.g. LexikTranslationBundle: getFormats(), removeLocalesCacheFiles()).
     *
     * @param mixed[] $args
     */
    public function __call(string $method, array $args): mixed
    {
        return $this->inner->{$method}(...$args);
    }
}
