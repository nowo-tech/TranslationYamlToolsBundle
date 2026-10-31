<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Command;

use InvalidArgumentException;
use Nowo\TranslationYamlToolsBundle\Service\DotKeyTreeAnalyzer;
use Nowo\TranslationYamlToolsBundle\Service\FrameworkTranslationPathsResolver;
use Nowo\TranslationYamlToolsBundle\Service\TranslationDefaultLocaleResolver;
use Nowo\TranslationYamlToolsBundle\Service\TranslationYamlCatalog;
use Nowo\TranslationYamlToolsBundle\Service\TranslationYamlFileHandler;
use Nowo\TranslationYamlToolsBundle\Service\YamlCatalogueTextEditor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

use function array_diff;
use function array_diff_key;
use function array_filter;
use function array_is_list;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_slice;
use function array_unique;
use function array_values;
use function basename;
use function bin2hex;
use function chmod;
use function count;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function fileperms;
use function glob;
use function implode;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function is_scalar;
use function is_string;
use function json_decode;
use function json_encode;
use function ksort;
use function max;
use function min;
use function preg_match;
use function random_bytes;
use function rename;
use function rtrim;
use function sort;
use function sprintf;
use function str_starts_with;
use function stream_get_contents;
use function strlen;
use function substr;
use function trim;
use function unlink;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const SORT_STRING;
use const STDIN;

/**
 * Reads and edits one translation key in every locale catalogue of a domain without opening the YAML files.
 *
 * Edits are textual (each file keeps its quoting, order and comments) and every result is re-parsed and compared with
 * the original before anything is written: all locales change or none does, and no other key may change.
 */
#[AsCommand(name: 'nowo:translation-yaml:key', description: 'Get, list, add, rename or remove a translation key in every locale catalogue of a domain (all locales or none)', help: <<<'TXT'
<info>%command.name% get validators user.email.blank</info>       value in every locale
<info>%command.name% list messages nav</info>                     keys under a prefix (reference locale), flags missing locales
<info>%command.name% add messages nav.inbox '{"en":"Inbox","es":"Bandeja"}'</info>
                                                         every locale required; refuses an existing key unless --replace
<info>%command.name% rename messages nav.inbox nav.mail</info>     moves a value or a group in every locale
<info>%command.name% remove messages nav.mail</info>              deletes a value or a group in every locale
<info>%command.name% missing [domain]</info>                       keys of the reference locale missing in a locale, and extra keys (exit 1)

Edits keep each file's quoting, order and comments, place new keys alphabetically when the siblings are sorted,
and are re-parsed before writing: all locales change or none does. --dry-run prints the changes only.
TXT)]
final class TranslationYamlKeyCommand extends AbstractTranslationYamlCommand
{
    private const ACTIONS = ['get', 'list', 'add', 'rename', 'remove', 'missing'];

    /**
     * @param list<string> $configuredLocales nowo_translation_yaml_tools.key_command.locales
     * @param mixed[] $enabledLocales %kernel.enabled_locales%
     */
    public function __construct(
        TranslationYamlCatalog $catalog,
        FrameworkTranslationPathsResolver $pathsResolver,
        private readonly TranslationYamlFileHandler $fileHandler,
        private readonly DotKeyTreeAnalyzer $analyzer,
        private readonly YamlCatalogueTextEditor $editor,
        private readonly TranslationDefaultLocaleResolver $defaultLocaleResolver,
        private readonly array $configuredLocales = [],
        private readonly array $enabledLocales = [],
        private readonly ?string $bundleDefaultLocaleOverride = null,
        private readonly int $configuredIndent = 4,
    ) {
        parent::__construct($catalog, $pathsResolver);
    }

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, implode(' | ', self::ACTIONS))
            ->addArgument('domain', InputArgument::OPTIONAL, 'Translation domain (messages, validators, …); optional for missing')
            ->addArgument('key', InputArgument::OPTIONAL, 'Dotted key (nav.title); prefix for list')
            ->addArgument('value', InputArgument::OPTIONAL, 'add: JSON object {"en": "…", …}, a JSON file path or "-" for STDIN; rename: the new key')
            ->addOption('replace', null, InputOption::VALUE_NONE, 'add: overwrite the key when it exists')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the line changes per file; write nothing')
            ->addOption('locales', null, InputOption::VALUE_REQUIRED, 'Comma-separated locales (default: key_command.locales, framework.enabled_locales, else the locale files found)')
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Read <domain>.<locale>.yaml from this directory instead of the translator paths');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = (string) $input->getArgument('action');
        if (!in_array($action, self::ACTIONS, true)) {
            return $this->fail($output, sprintf('Unknown action "%s". Use one of: %s.', $action, implode(', ', self::ACTIONS)));
        }

        $domain = $this->stringArgument($input, 'domain');
        $key    = $this->stringArgument($input, 'key');
        $value  = $this->stringArgument($input, 'value');

        try {
            if ($action === 'missing') {
                return $this->missing($input, $output, $domain);
            }
            if ($domain === null) {
                return $this->fail($output, sprintf('The %s action needs a domain.', $action));
            }
            $this->assertDomain($domain);
            if ($action === 'list') {
                return $this->list($input, $output, $domain, $key);
            }
            if ($key === null) {
                return $this->fail($output, sprintf('The %s action needs a key.', $action));
            }

            return match ($action) {
                'get'    => $this->get($input, $output, $domain, $key),
                'add'    => $this->add($input, $output, $domain, $key, $value),
                'rename' => $this->rename($input, $output, $domain, $key, $value),
                default  => $this->remove($input, $output, $domain, $key),
            };
        } catch (InvalidArgumentException $e) {
            return $this->fail($output, $e->getMessage());
        }
    }

    private function get(InputInterface $input, OutputInterface $output, string $domain, string $key): int
    {
        foreach ($this->locales($input, $domain) as $locale) {
            $flat = $this->flatTree($this->path($input, $domain, $locale));
            $output->writeln(sprintf('%s: %s', $locale, array_key_exists($key, $flat) ? $this->json($flat[$key]) : $this->groupOrMissing($flat, $key)));
        }

        return Command::SUCCESS;
    }

    private function list(InputInterface $input, OutputInterface $output, string $domain, ?string $prefix): int
    {
        $locales   = $this->locales($input, $domain);
        $reference = $this->referenceLocale($locales);
        $flat      = [];
        foreach ($locales as $locale) {
            $flat[$locale] = $this->flatTree($this->path($input, $domain, $locale));
        }

        $count = 0;
        foreach ($flat[$reference] as $key => $value) {
            $key = (string) $key;
            if ($prefix !== null && $key !== $prefix && !str_starts_with($key, $prefix . '.')) {
                continue;
            }
            ++$count;
            $absent = array_values(array_filter($locales, static fn (string $l): bool => !array_key_exists($key, $flat[$l])));
            $output->writeln(sprintf('%s = %s%s', OutputFormatter::escape($key), $this->json($value), $absent === [] ? '' : sprintf(' <comment>[missing: %s]</comment>', implode(', ', $absent))));
        }
        $output->writeln(sprintf('<info>%d key(s) in %s.%s%s</info>', $count, $domain, $reference, $prefix === null ? '' : ' under ' . $prefix));

        return Command::SUCCESS;
    }

    private function add(InputInterface $input, OutputInterface $output, string $domain, string $key, ?string $source): int
    {
        if ($source === null) {
            return $this->fail($output, 'The add action needs the values: a JSON object {"en": "…", …}, a JSON file or "-" for STDIN.');
        }
        $json   = $source === '-' ? (string) stream_get_contents($input instanceof StreamableInputInterface && $input->getStream() !== null ? $input->getStream() : STDIN) : (is_file($source) ? (string) file_get_contents($source) : $source);
        $values = json_decode($json, true);
        if (!is_array($values) || $values === [] || array_is_list($values)) {
            return $this->fail($output, 'The values must be a JSON object {"en": "…", …}.');
        }

        $locales = $this->locales($input, $domain);
        $absent  = array_diff($locales, array_map('strval', array_keys($values)));
        if ($absent !== []) {
            return $this->fail($output, sprintf('Missing locales: %s (every locale gets the key, or none does).', implode(', ', $absent)));
        }
        $unknown = array_diff(array_map('strval', array_keys($values)), $locales);
        if ($unknown !== []) {
            return $this->fail($output, sprintf('Unknown locales: %s (known: %s).', implode(', ', $unknown), implode(', ', $locales)));
        }

        $replace = (bool) $input->getOption('replace');
        $changes = [];
        foreach ($locales as $locale) {
            $value = $values[$locale];
            if (!is_string($value)) {
                return $this->fail($output, sprintf('%s: the value must be a string.', $locale));
            }
            $changes[$locale] = $this->change($input, $domain, $locale, static function (string $text, array $before, YamlCatalogueTextEditor $editor, int $indent) use ($key, $value, $replace): array {
                if (!$replace && array_key_exists($key, $before)) {
                    throw new InvalidArgumentException(sprintf('Key "%s" already exists (pass --replace to overwrite).', $key));
                }
                $expected       = $before;
                $expected[$key] = $value;

                return [$editor->set($text, $key, $value, $replace, $indent), $expected];
            });
        }

        return $this->commit($input, $output, $changes, sprintf('%s %s', $replace ? 'set' : 'added', $key));
    }

    private function rename(InputInterface $input, OutputInterface $output, string $domain, string $from, ?string $to): int
    {
        if ($to === null || $to === '') {
            return $this->fail($output, 'The rename action needs the new key.');
        }
        if ($to === $from || str_starts_with($to, $from . '.')) {
            return $this->fail($output, sprintf('Cannot rename "%s" to "%s": the new key must be outside the old one.', $from, $to));
        }

        $changes = [];
        foreach ($this->locales($input, $domain) as $locale) {
            $changes[$locale] = $this->change($input, $domain, $locale, static function (string $text, array $before, YamlCatalogueTextEditor $editor, int $indent) use ($from, $to): array {
                $moved = self::subtree($before, $from);
                if ($moved === []) {
                    throw new InvalidArgumentException(sprintf('Key "%s" does not exist (rename needs it in every locale).', $from));
                }
                if (self::subtree($before, $to) !== [] || self::hasValuePrefix($before, $to)) {
                    throw new InvalidArgumentException(sprintf('Key "%s" already exists.', $to));
                }
                $expected = array_diff_key($before, $moved);
                $text     = $editor->remove($text, $from, $indent);
                foreach ($moved as $oldKey => $value) {
                    if (!is_scalar($value) && $value !== null) {
                        throw new InvalidArgumentException(sprintf('Key "%s" holds a list; rename it by hand.', $oldKey));
                    }
                    $newKey            = $to . substr((string) $oldKey, strlen($from));
                    $text              = $editor->set($text, $newKey, $value, false, $indent);
                    $expected[$newKey] = $value;
                }

                return [$text, $expected];
            });
        }

        return $this->commit($input, $output, $changes, sprintf('renamed %s to %s', $from, $to));
    }

    private function remove(InputInterface $input, OutputInterface $output, string $domain, string $key): int
    {
        $changes = [];
        $absent  = [];
        foreach ($this->locales($input, $domain) as $locale) {
            $flat = $this->flatTree($this->path($input, $domain, $locale));
            if (self::subtree($flat, $key) === []) {
                $absent[] = $locale;
                continue;
            }
            $changes[$locale] = $this->change($input, $domain, $locale, static function (string $text, array $before, YamlCatalogueTextEditor $editor, int $indent) use ($key): array {
                return [$editor->remove($text, $key, $indent), array_diff_key($before, self::subtree($before, $key))];
            });
        }
        if ($changes === []) {
            return $this->fail($output, sprintf('Key "%s" does not exist in any locale.', $key));
        }
        if ($absent !== []) {
            $output->writeln(sprintf('<comment>Key "%s" was already absent in: %s.</comment>', $key, implode(', ', $absent)));
        }

        return $this->commit($input, $output, $changes, sprintf('removed %s', $key));
    }

    private function missing(InputInterface $input, OutputInterface $output, ?string $domain): int
    {
        if ($domain !== null) {
            $this->assertDomain($domain);
        }
        $domains  = $domain !== null ? [$domain] : $this->domains($input);
        $problems = 0;
        foreach ($domains as $current) {
            $locales   = $this->locales($input, $current);
            $reference = $this->referenceLocale($locales);
            $keys      = [];
            foreach ($locales as $locale) {
                $path          = $this->findPath($input, $current, $locale);
                $keys[$locale] = $path === null ? [] : array_map('strval', array_keys($this->flatTree($path)));
            }
            foreach ($locales as $locale) {
                foreach (array_diff($keys[$reference], $keys[$locale]) as $key) {
                    $output->writeln(OutputFormatter::escape(sprintf('%s.%s: missing %s', $current, $locale, $key)));
                    ++$problems;
                }
                if ($locale === $reference) {
                    continue;
                }
                foreach (array_diff($keys[$locale], $keys[$reference]) as $key) {
                    $output->writeln(OutputFormatter::escape(sprintf('%s.%s: not in %s %s', $current, $locale, $reference, $key)));
                    ++$problems;
                }
            }
        }

        if ($problems === 0) {
            $output->writeln('<info>OK all locales have the same keys</info>');

            return Command::SUCCESS;
        }
        $output->writeln(sprintf('<error>FAIL %d difference(s)</error>', $problems));

        return Command::FAILURE;
    }

    /**
     * Applies $edit to one locale file and proves the result: it parses, the edited key(s) read back and no other key
     * changed.
     *
     * @param callable(string, array<string, mixed>, YamlCatalogueTextEditor, int): array{0: string, 1: array<string, mixed>} $edit
     *
     * @return array{path: string, before: string, after: string}
     */
    private function change(InputInterface $input, string $domain, string $locale, callable $edit): array
    {
        $path   = $this->path($input, $domain, $locale);
        $before = $this->flatTree($path);
        $text   = (string) file_get_contents($path);

        try {
            [$after, $expected] = $edit($text, $before, $this->editor, $this->configuredIndent);
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException(sprintf('%s: %s', $locale, $e->getMessage()), 0, $e);
        }

        try {
            $parsed = Yaml::parse($after);
        } catch (ParseException $e) {
            throw new InvalidArgumentException(sprintf('%s: the edit does not parse (%s); nothing written.', $locale, $e->getMessage()), 0, $e);
        }
        $result = $this->analyzer->flatten(is_array($parsed) ? $parsed : []);
        ksort($result, SORT_STRING);
        ksort($expected, SORT_STRING);
        if ($result !== $expected) {
            throw new InvalidArgumentException(sprintf('%s: the edit does not read back (a parent key is a value, the path is ambiguous or the file uses a layout the editor does not handle); nothing written.', $locale));
        }

        return ['path' => $path, 'before' => $text, 'after' => $after];
    }

    /**
     * @param array<string, array{path: string, before: string, after: string}> $changes
     */
    private function commit(InputInterface $input, OutputInterface $output, array $changes, string $summary): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        foreach ($changes as $change) {
            if ($dryRun) {
                $output->writeln(sprintf('<info>%s</info>', $change['path']));
                foreach ($this->lineDiff($change['before'], $change['after']) as $line) {
                    $output->writeln($line);
                }
            }
        }
        if ($dryRun) {
            $output->writeln(sprintf('<comment>Dry-run: would have %s in %d locale(s); nothing written.</comment>', $summary, count($changes)));

            return Command::SUCCESS;
        }

        // Stage every file next to its catalogue first, then swap them in: a failed write leaves no catalogue changed.
        $staged = [];
        foreach ($changes as $change) {
            $tmp = $change['path'] . '.' . bin2hex(random_bytes(6)) . '.tmp';
            if (@file_put_contents($tmp, $change['after']) === false) {
                // @codeCoverageIgnoreStart
                foreach ($staged as $file) {
                    @unlink($file);
                }

                return $this->fail($output, sprintf('Cannot write next to %s; no catalogue changed.', $change['path']));
                // @codeCoverageIgnoreEnd
            }
            $mode = fileperms($change['path']);
            if ($mode !== false) {
                @chmod($tmp, $mode & 0o7777);
            }
            $staged[$change['path']] = $tmp;
        }
        foreach ($staged as $path => $tmp) {
            if (!@rename($tmp, $path)) {
                // @codeCoverageIgnoreStart
                @unlink($tmp);

                return $this->fail($output, sprintf('Cannot replace %s; check the catalogues with the missing action.', $path));
                // @codeCoverageIgnoreEnd
            }
        }
        $output->writeln(sprintf('<info>OK %s in %d locale(s)</info>', $summary, count($changes)));

        return Command::SUCCESS;
    }

    /**
     * Changed region between two texts (common head and tail trimmed).
     *
     * @return list<string>
     */
    private function lineDiff(string $before, string $after): array
    {
        $old  = explode("\n", $before);
        $new  = explode("\n", $after);
        $head = 0;
        $max  = min(count($old), count($new));
        while ($head < $max && $old[$head] === $new[$head]) {
            ++$head;
        }
        $tail = 0;
        while ($tail < $max - $head && $old[count($old) - 1 - $tail] === $new[count($new) - 1 - $tail]) {
            ++$tail;
        }

        $out = [sprintf('@@ line %d', $head + 1)];
        foreach (array_slice($old, $head, max(0, count($old) - $head - $tail)) as $line) {
            $out[] = '<fg=red>- ' . OutputFormatter::escape($line) . '</>';
        }
        foreach (array_slice($new, $head, max(0, count($new) - $head - $tail)) as $line) {
            $out[] = '<fg=green>+ ' . OutputFormatter::escape($line) . '</>';
        }

        return $out;
    }

    /**
     * Leaf keys equal to $key or under it.
     *
     * @param array<string, mixed> $flat
     *
     * @return array<string, mixed>
     */
    private static function subtree(array $flat, string $key): array
    {
        $out = [];
        foreach ($flat as $k => $v) {
            $k = (string) $k;
            if ($k === $key || str_starts_with($k, $key . '.')) {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    /**
     * True when a parent of $key is already a value (nav.title is a value, so nav.title.x cannot exist).
     *
     * @param array<string, mixed> $flat
     */
    private static function hasValuePrefix(array $flat, string $key): bool
    {
        $segments = explode('.', $key);
        for ($i = count($segments) - 1; $i >= 1; --$i) {
            if (array_key_exists(implode('.', array_slice($segments, 0, $i)), $flat)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $flat
     */
    private function groupOrMissing(array $flat, string $key): string
    {
        $count = count(self::subtree($flat, $key));

        return $count === 0 ? '(missing)' : sprintf('(group of %d key(s))', $count);
    }

    /**
     * @return array<string, mixed>
     */
    private function flatTree(string $path): array
    {
        return $this->analyzer->flatten($this->fileHandler->loadFile($path));
    }

    /**
     * Locales of the domain: --locales, key_command.locales, framework.enabled_locales, else the locale files found.
     *
     * @return list<string>
     */
    private function locales(InputInterface $input, string $domain): array
    {
        $option = $input->getOption('locales');
        $source = is_string($option) && trim($option) !== '' ? explode(',', $option) : ($this->configuredLocales !== [] ? $this->configuredLocales : $this->enabledLocales);

        $locales = [];
        foreach ($source as $locale) {
            $locale = is_string($locale) ? trim($locale) : '';
            if ($locale === '') {
                continue;
            }
            if (preg_match('/^[a-zA-Z0-9_-]+$/', $locale) !== 1) {
                throw new InvalidArgumentException(sprintf('Invalid locale "%s".', $locale));
            }
            $locales[] = $locale;
        }
        if ($locales === []) {
            $locales = $this->discoveredLocales($input, $domain);
        }
        if ($locales === []) {
            throw new InvalidArgumentException(sprintf('No locale files found for domain "%s".', $domain));
        }

        return array_values(array_unique($locales));
    }

    /**
     * @param list<string> $locales
     */
    private function referenceLocale(array $locales): string
    {
        $default = $this->defaultLocaleResolver->resolve($this->bundleDefaultLocaleOverride);

        return in_array($default, $locales, true) ? $default : $locales[0];
    }

    /**
     * @return list<string>
     */
    private function discoveredLocales(InputInterface $input, string $domain): array
    {
        $dir = $this->dirOption($input);
        if ($dir === null) {
            return $this->catalog->listLocalesForDomain($domain);
        }

        $locales = [];
        foreach ($this->scanDir($dir) as [$fileDomain, $locale]) {
            if ($fileDomain === $domain) {
                $locales[] = $locale;
            }
        }
        sort($locales, SORT_STRING);

        return array_values(array_unique($locales));
    }

    /**
     * @return list<string>
     */
    private function domains(InputInterface $input): array
    {
        $dir = $this->dirOption($input);
        if ($dir === null) {
            return $this->catalog->listDomains();
        }
        $domains = array_map(static fn (array $file): string => $file[0], $this->scanDir($dir));
        sort($domains, SORT_STRING);

        return array_values(array_unique($domains));
    }

    /**
     * @return list<array{0: string, 1: string}> [domain, locale] per YAML file of $dir
     */
    private function scanDir(string $dir): array
    {
        $out = [];
        foreach ([...(glob($dir . '/*.yaml') ?: []), ...(glob($dir . '/*.yml') ?: [])] as $file) {
            if (preg_match('/^(.+)\.([a-zA-Z0-9_-]+)\.ya?ml$/', basename($file), $m) === 1) {
                $out[] = [$m[1], $m[2]];
            }
        }

        return $out;
    }

    /**
     * Catalogue file of a domain and locale.
     *
     * @throws InvalidArgumentException when the file does not exist
     */
    private function path(InputInterface $input, string $domain, string $locale): string
    {
        return $this->findPath($input, $domain, $locale)
            ?? throw new InvalidArgumentException(sprintf('No catalogue file for domain "%s" and locale "%s" (create %s.%s.yaml first).', $domain, $locale, $domain, $locale));
    }

    private function findPath(InputInterface $input, string $domain, string $locale): ?string
    {
        $dir = $this->dirOption($input);
        if ($dir === null) {
            $path = $this->catalog->resolveFileForDomainLocale($domain, $locale);
        } else {
            $path = null;
            foreach (['yaml', 'yml'] as $ext) {
                if (is_file(sprintf('%s/%s.%s.%s', $dir, $domain, $locale, $ext))) {
                    $path = sprintf('%s/%s.%s.%s', $dir, $domain, $locale, $ext);
                    break;
                }
            }
        }

        return $path;
    }

    private function dirOption(InputInterface $input): ?string
    {
        $dir = $input->getOption('dir');
        if (!is_string($dir) || $dir === '') {
            return null;
        }
        if (!is_dir($dir)) {
            throw new InvalidArgumentException(sprintf('Directory not found: %s', $dir));
        }

        return rtrim($dir, '/');
    }

    private function assertDomain(string $domain): void
    {
        if (preg_match('/^[a-zA-Z0-9_+-]+(?:\.[a-zA-Z0-9_+-]+)*$/', $domain) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid domain "%s".', $domain));
        }
    }

    private function stringArgument(InputInterface $input, string $name): ?string
    {
        $value = $input->getArgument($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function json(mixed $value): string
    {
        return OutputFormatter::escape((string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function fail(OutputInterface $output, string $message): int
    {
        $output->writeln('<error>' . OutputFormatter::escape($message) . '</error>');

        return Command::FAILURE;
    }
}
