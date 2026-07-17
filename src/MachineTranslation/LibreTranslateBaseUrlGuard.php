<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\MachineTranslation;

use InvalidArgumentException;

use function implode;
use function is_string;
use function parse_url;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function strtolower;

/**
 * Restricts LibreTranslate base URLs to an allowlist of hosts (SSRF mitigation).
 */
final class LibreTranslateBaseUrlGuard
{
    /**
     * @param list<string> $allowedHosts Hostnames (lowercase), e.g. libretranslate.com
     */
    public function __construct(
        private readonly array $allowedHosts,
        private readonly bool $allowHttp = false,
    ) {
    }

    public function assertAllowed(string $baseUrl): void
    {
        $parts = parse_url($baseUrl);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || !is_string($parts['host'])) {
            throw new InvalidArgumentException(sprintf('Invalid LibreTranslate base URL: %s', $baseUrl));
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme === 'https') {
            // ok
        } elseif ($scheme === 'http' && $this->allowHttp) {
            // ok for local/dev when explicitly enabled
        } else {
            throw new InvalidArgumentException(sprintf('LibreTranslate base URL must use https%s: %s', $this->allowHttp ? ' (or http when libretranslate_allow_http is true)' : '', $baseUrl));
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('LibreTranslate base URL must not contain userinfo.');
        }

        $host = strtolower($parts['host']);
        if ($host === '' || str_contains($host, '..')) {
            throw new InvalidArgumentException(sprintf('Invalid LibreTranslate host in URL: %s', $baseUrl));
        }

        if ($this->allowedHosts === []) {
            throw new InvalidArgumentException('libretranslate_allowed_hosts is empty; add at least one hostname (e.g. libretranslate.com).');
        }

        foreach ($this->allowedHosts as $allowed) {
            $allowed = strtolower($allowed);
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return;
            }
        }

        throw new InvalidArgumentException(sprintf('LibreTranslate host "%s" is not in libretranslate_allowed_hosts (%s).', $host, implode(', ', $this->allowedHosts)));
    }
}
