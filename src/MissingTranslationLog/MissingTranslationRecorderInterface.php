<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\MissingTranslationLog;

/**
 * Records translation lookups where the message is not defined for the requested locale catalogue.
 */
interface MissingTranslationRecorderInterface
{
    /**
     * @param non-empty-string $id Message id / key
     * @param non-empty-string $domain Translation domain (e.g. messages)
     * @param non-empty-string $locale Requested locale
     * @param non-empty-string|null $callSite optional **`file:line`** from **`debug_backtrace`** when **`record_call_site`** is enabled (see **`MissingTranslationLogCallSiteBuilder`**)
     * @param non-empty-string|null $requestRoute HTTP **`_route`** when **`record_request_context`** is enabled
     * @param non-empty-string|null $requestMethod HTTP method (truncated) when request context is recorded
     * @param non-empty-string|null $requestPath **`Request::getPathInfo()`** when non-empty and request context is recorded
     */
    public function record(
        string $id,
        string $domain,
        string $locale,
        ?string $callSite = null,
        ?string $requestRoute = null,
        ?string $requestMethod = null,
        ?string $requestPath = null,
    ): void;
}
