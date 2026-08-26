<?php

namespace Splicewire\Beam\Doctor\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Splicewire\Beam\Doctor\DeadConfigKeyAudit;

/**
 * Finds the config ROOTS a codebase literally reads, for {@see DeadConfigKeyAudit}.
 *
 * **Token-level, not regex-level**, for the reason {@see FacadeReferenceScanner}'s docblock already
 * established for the facade regime: a `config('beam-mdx.content_path')` inside a docblock explaining the
 * old key is prose, not a read, and a naive text match reports the file that *documents* the fix as the
 * file that needs it. `token_get_all()` draws the line where PHP does. It matters more here than there —
 * a rename leaves migration notes behind by definition, so the comment population is guaranteed
 * non-empty on the exact keys this audit hunts.
 *
 * Only a **literal first argument** is read. `config($key)` and `config("beam.{$domain}.path")` are
 * invisible, which is a deliberate false-negative: a dynamic key cannot be statically resolved, and
 * guessing at one would produce the false alarms that get an audit's floor bumped and then deleted.
 */
class ConfigKeyScanner
{
    /** Directories never walked. `tests/` is absent by design — see {@see DeadConfigKeyAudit}. */
    public const PRUNED = ['vendor', 'node_modules', '.git', 'storage', 'build', 'dist'];

    /**
     * Config roots read across these files, mapped to the display paths that read them.
     *
     * @param  list<string>  $files
     * @return array<string, list<string>> root => sites, both sorted for a byte-identical re-run
     */
    public static function rootsIn(array $files): array
    {
        $roots = [];

        foreach ($files as $path) {
            $source = @file_get_contents($path);

            if ($source === false || ! str_contains($source, 'config')) {
                continue;
            }

            foreach (self::rootsInSource($source) as $root => $lines) {
                foreach ($lines as $line) {
                    $roots[$root][] = FacadeConformanceScope::displayPath($path).':'.$line;
                }
            }
        }

        foreach ($roots as $root => $sites) {
            $sites = array_values(array_unique($sites));
            sort($sites);
            $roots[$root] = $sites;
        }

        ksort($roots);

        return $roots;
    }

    /**
     * The roots read in one file's source, mapped to the lines reading them.
     *
     * Recognized call shapes, all requiring a literal string first argument:
     *   `config('root.rest')` · `Config::get|has|set('root.rest', …)` ·
     *   `config()->get|has|set('root.rest', …)` · `$app['config']->set('root.rest', …)`
     *
     * The WRITE shapes used to be excluded, on the reasoning that a seeding write is a test idiom and
     * the read it must agree with is what the audit compares against. api-surface-coherence 53 is the
     * counter-specimen: beam-taxonomy's suite seeded `beam-taxonomy.models.tag` while the provider read
     * `beam.taxonomy.models.tag`, so the READ half was correct and produced no finding, while the write
     * fell on the floor and three tests asserted the package's own shipped default instead of the
     * host binding they claimed to exercise. A dead write is dead the same way a dead read is, and it
     * is the more dangerous half — a dead read fails visibly at runtime; a dead write turns an
     * assertion green.
     *
     * A bare `config('root')` with no dot is skipped: reading a whole root back is a legitimate way to
     * ask whether a package is installed, and it is not the shape that goes silently null on a rename.
     *
     * @return array<string, list<int>>
     */
    public static function rootsInSource(string $source): array
    {
        $tokens = @token_get_all($source);
        $found = [];

        foreach ($tokens as $i => $token) {
            if (! is_array($token) || $token[0] !== T_STRING) {
                continue;
            }

            if (! in_array($token[1], ['config', 'get', 'has', 'set'], true)) {
                continue;
            }

            $literal = self::firstStringArgument($tokens, $i);

            if ($literal === null || ! str_contains($literal, '.')) {
                continue;
            }

            // `get`/`has`/`set` only touch config when reached through the Config facade or a config
            // repository receiver — otherwise every `$collection->get('a.b')` and every
            // `$model->set('a.b')` in the estate would be a finding.
            if ($token[1] !== 'config'
                && ! self::precededByConfigFacade($tokens, $i)
                && ! self::precededByConfigRepository($tokens, $i)) {
                continue;
            }

            $found[strtok($literal, '.')][] = $token[2];
        }

        foreach ($found as $root => $lines) {
            $lines = array_values(array_unique($lines));
            sort($lines);
            $found[$root] = $lines;
        }

        return $found;
    }

    /**
     * The literal string passed as the first argument to the call at `$i`, or null when the next
     * meaningful token is not `(` immediately followed by a quoted string — which covers `config()`,
     * `config($key)`, and any interpolated `"…{$x}…"` (PHP tokenizes those as several tokens, never as
     * one `T_CONSTANT_ENCAPSED_STRING`).
     *
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function firstStringArgument(array $tokens, int $i): ?string
    {
        $next = self::nextMeaningful($tokens, $i);

        if ($next === null || $tokens[$next] !== '(') {
            return null;
        }

        $arg = self::nextMeaningful($tokens, $next);

        if ($arg === null || ! is_array($tokens[$arg]) || $tokens[$arg][0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }

        return trim($tokens[$arg][1], "'\"");
    }

    /**
     * Whether the call at `$i` is `Config::` / `\Config::` — the facade, not an arbitrary object's
     * `get()`. Walks back over the `::` and the class name only, so `$repo->get('a.b')` never qualifies.
     *
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function precededByConfigFacade(array $tokens, int $i): bool
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            $token = $tokens[$j];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            if ($token !== '::' && ! (is_array($token) && $token[0] === T_DOUBLE_COLON)) {
                return false;
            }

            $class = self::previousMeaningful($tokens, $j);

            return $class !== null
                && is_array($tokens[$class])
                && str_ends_with(ltrim($tokens[$class][1], '\\'), 'Config');
        }

        return false;
    }

    /**
     * Whether the call at `$i` is reached through a config REPOSITORY receiver rather than the facade —
     * `config()->set('a.b', …)` or `$app['config']->set('a.b', …)` (equally `$this->app['config']`).
     * Both are the estate's test-seeding idioms, and both go silently nowhere on a renamed root.
     *
     * Deliberately narrow: the receiver must be one of those two literal shapes. `$repo->set('a.b')`
     * on some variable that HAPPENS to hold a repository is invisible here, which is the same
     * false-negative bargain {@see rootsInSource} already takes on dynamic keys — never guess.
     *
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function precededByConfigRepository(array $tokens, int $i): bool
    {
        $arrow = self::previousMeaningful($tokens, $i);

        if ($arrow === null || ! is_array($tokens[$arrow]) || $tokens[$arrow][0] !== T_OBJECT_OPERATOR) {
            return false;
        }

        $close = self::previousMeaningful($tokens, $arrow);

        if ($close === null) {
            return false;
        }

        // `config()->` — an empty argument list, so the `(` sits directly behind the `)`.
        if ($tokens[$close] === ')') {
            $open = self::previousMeaningful($tokens, $close);
            $name = $open !== null && $tokens[$open] === '(' ? self::previousMeaningful($tokens, $open) : null;

            return $name !== null
                && is_array($tokens[$name])
                && $tokens[$name][0] === T_STRING
                && $tokens[$name][1] === 'config';
        }

        // `['config']->` — the array-access read of the container's config binding.
        if ($tokens[$close] === ']') {
            $key = self::previousMeaningful($tokens, $close);

            return $key !== null
                && is_array($tokens[$key])
                && $tokens[$key][0] === T_CONSTANT_ENCAPSED_STRING
                && trim($tokens[$key][1], "'\"") === 'config';
        }

        return false;
    }

    /** @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens */
    private static function nextMeaningful(array $tokens, int $from): ?int
    {
        return self::scan($tokens, $from, 1);
    }

    /** @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens */
    private static function previousMeaningful(array $tokens, int $from): ?int
    {
        return self::scan($tokens, $from, -1);
    }

    /** @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens */
    private static function scan(array $tokens, int $from, int $step): ?int
    {
        for ($j = $from + $step; isset($tokens[$j]); $j += $step) {
            $token = $tokens[$j];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $j;
        }

        return null;
    }

    /**
     * Every `.php` / `.php.stub` under these roots, pruning {@see PRUNED} during traversal. Sorted and
     * realpath-deduplicated so a re-run with no code change produces a byte-identical finding list —
     * the same reasoning (and the same double-counting hazard through symlinked vendor roots)
     * {@see FacadeConformanceScope::files()} documents.
     *
     * @param  list<string>  $roots
     * @return list<string>
     */
    public static function filesIn(array $roots): array
    {
        $seen = [];

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            );

            foreach ($iterator as $file) {
                $path = (string) $file;

                if ($file->isDir()) {
                    continue;
                }

                if (self::isPruned($root, $path) || ! FacadeConformanceScope::isScannable($path)) {
                    continue;
                }

                $real = realpath($path);
                $seen[$real === false ? $path : $real] = true;
            }
        }

        $files = array_keys($seen);
        sort($files);

        return $files;
    }

    /** Whether a path sits under a pruned directory, relative to the root it was found beneath. */
    private static function isPruned(string $root, string $path): bool
    {
        $relative = str_replace('\\', '/', ltrim(substr($path, strlen($root)), '/'));

        foreach (self::PRUNED as $fragment) {
            if (str_starts_with($relative, $fragment.'/') || str_contains($relative, '/'.$fragment.'/')) {
                return true;
            }
        }

        return false;
    }
}
