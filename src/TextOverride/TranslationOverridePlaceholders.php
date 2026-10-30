<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\TextOverride;

use function array_unique;
use function array_values;
use function preg_match_all;
use function sort;

/**
 * Message parameters of a translation text (REQ-SEC-004 L4): Symfony `%name%` placeholders and ICU `{name}` /
 * `{name, plural, …}` arguments. Parameters are substituted **after** the override is sanitized, so an override may
 * only use the parameters of the shipped text: an editor must not add `%url%` (or `{name}`) to a message whose host
 * passes a request value for it and prints the result with `|raw`. Interval / plural selectors (`{0}`, `{1}`) are not
 * parameters.
 */
final class TranslationOverridePlaceholders
{
    private const SYMFONY = '/%[A-Za-z_][A-Za-z0-9_.\-]*%/';

    private const ICU = '/\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*[,}]/';

    /**
     * Sorted unique placeholders of a text, e.g. ['%count%', '{name}'].
     *
     * @return list<string>
     */
    public static function of(string $text): array
    {
        $found = [];
        if (preg_match_all(self::SYMFONY, $text, $symfony) > 0) {
            $found = $symfony[0];
        }
        if (preg_match_all(self::ICU, $text, $icu) > 0) {
            foreach ($icu[1] as $name) {
                $found[] = '{' . $name . '}';
            }
        }
        $found = array_values(array_unique($found));
        sort($found);

        return $found;
    }

    /**
     * Whether the override uses exactly the placeholders of the shipped text.
     */
    public static function match(string $shipped, string $override): bool
    {
        return self::of($shipped) === self::of($override);
    }
}
