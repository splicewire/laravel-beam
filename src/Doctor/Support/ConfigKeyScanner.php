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
     *   `config('root.rest')` · `Config::get('root.rest')` · `Config::has('root.rest')` ·
     *   `$app['config']->set('root.rest', …)` is NOT matched — an array-access write is a test idiom
     *   for seeding, and the read it must agree with is what this audit compares against.
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

            if (! in_array($token[1], ['config', 'get', 'has'], true)) {
                continue;
            }

            $literal = self::firstStringArgument($tokens, $i);

            if ($literal === null || ! str_contains($literal, '.')) {
                continue;
            }

            // `get`/`has` are only a config read when reached through the Config facade — otherwise
            // every `$collection->get('a.b')` in the estate would be a finding.
            if ($token[1] !== 'config' && ! self::precededByConfigFacade($tokens, $i)) {
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
