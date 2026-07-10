<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Translation;

use Symfony\Component\HttpFoundation\RequestStack;

use function is_string;
use function strlen;
use function substr;

/**
 * Builds structured context for missing translation rows: backtrace in {@see MissingTranslationRecordContext::$callSite},
 * HTTP route / method / path in dedicated fields when {@see $includeRequest} is true.
 */
class MissingTranslationLogCallSiteBuilder
{
    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    public function buildContext(bool $includeBacktrace, bool $includeRequest): MissingTranslationRecordContext
    {
        $callSite = null;
        if ($includeBacktrace) {
            $site = TranslationCallSiteResolver::resolve();
            if ($site !== null && $site !== '') {
                $callSite = $this->truncate($site, 1024);
            }
        }

        $requestRoute  = null;
        $requestMethod = null;
        $requestPath   = null;
        if ($includeRequest) {
            $request = $this->requestStack->getCurrentRequest();
            if ($request instanceof \Symfony\Component\HttpFoundation\Request) {
                $route = $request->attributes->get('_route');
                if (is_string($route) && $route !== '') {
                    $requestRoute = $this->truncate($route, 180);
                }
                $requestMethod = $this->truncate($request->getMethod(), 8);
                $path          = $request->getPathInfo();
                if ($path !== '') {
                    $requestPath = $this->truncate($path, 2048);
                }
            }
        }

        return new MissingTranslationRecordContext($callSite, $requestRoute, $requestMethod, $requestPath);
    }

    private function truncate(string $value, int $maxLength): string
    {
        if (strlen($value) <= $maxLength) {
            return $value;
        }

        return substr($value, 0, $maxLength - 3) . '...';
    }
}
