<?php

declare(strict_types=1);

namespace Splicewire\Beam\Doctor\Support;

/**
 * The shared, purely-static read both family-Tailwind audits sit on: which CSS entries a host has,
 * which family packages actually RESOLVE in its `node_modules`, which `@source` globs are declared,
 * and whether the derivation plugin is wired.
 *
 * ## Why this exists at all
 *
 * Tailwind v4 ignores symlinked `node_modules`. Every family package resolves as a symlink onto
 * workspace source, so a utility class used only inside a package's built `dist` is never generated:
 * correct markup, absent classes, HTTP 200. No PHP suite, JS suite, `tsc` or existing doctor audit
 * can see it. The compensating control was a hand-written `@source` per package, which drifts — one
 * host was repaired by hand and had drifted back to 8 globs against 28 resolving packages.
 *
 * ## Four measured traps this class encodes
 *
 * 1. **Read `node_modules` ON DISK, never `package.json`.** One host declares 11 family packages and
 *    resolves 28; transitive family deps are exactly the ones with no direct import and they still
 *    ship classes the host renders.
 * 2. **PATH-MATCH files, do not name-match packages.** One host's `dist/*.js` glob reaches 22 of 23
 *    dist files; a set difference keyed on package name calls that covered while `dist/blockdoc/` is
 *    unscanned.
 * 3. **Resolve a glob against the CSS FILE's directory, lexically.** `ui/src/index.css`'s
 *    `../../node_modules` is the APP ROOT, not `ui/`. Getting this wrong reports every glob dead when
 *    every one is live.
 * 4. **The "inside the host" exclusion must exempt `node_modules`.** Under pnpm every package
 *    realpaths to `<root>/node_modules/.pnpm/.../<name>` — inside the root. A naive prefix test
 *    excludes EVERY package and the scan reports nothing, silently.
 *
 * Mirrors `@schemastud/seam`'s `familyDistSources()` (the Vite derivation plugin) deliberately: the
 * audit's pre-plugin branch has to agree with what the plugin would emit, or landing the plugin would
 * look like a regression to the audit.
 */
final class FamilyTailwindScan
{
    /** The family npm scopes. Matches `NpmPackageSkewAudit` and the seam plugin. */
    public const SCOPES = ['@schemastud', '@splicewire'];

    /** Directory names never descended when looking for a Tailwind entry. */
    private const SKIP_DIRS = ['node_modules', 'vendor', 'dist', 'build', 'public', 'storage', '.git', 'coverage'];

    /** Extensions Tailwind can extract a class name from, for the coverage path-match. */
    private const SCANNABLE = ['js', 'mjs', 'cjs', 'jsx', 'ts', 'tsx', 'html', 'vue', 'svelte'];

    /** @var list<string>|null */
    private ?array $entries = null;

    /** @var list<array{name: string, dist: string, real: string}>|null */
    private ?array $packages = null;

    /**
     * @param  array<string, mixed>  $config  the resolved `beam.core.tailwind` config
     */
    public function __construct(
        private readonly string $base,
        private readonly array $config = [],
    ) {}

    /**
     * The host's Tailwind **v4** CSS entries, absolute.
     *
     * v4 is identified by `@import "tailwindcss"` — a v3 host writes `@tailwind base` and is out of
     * this audit's population entirely, because `@source` does not exist there.
     *
     * @return list<string>
     */
    public function entries(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }

        $roots = $this->list('css_roots', ['resources', 'ui', 'src']);
        $found = [];

        foreach ($roots as $root) {
            foreach ($this->cssFiles($this->path($root)) as $file) {
                $contents = $this->read($file);

                if ($contents !== null && preg_match('/@import\s+["\']tailwindcss/', $contents) === 1) {
                    $found[$file] = true;
                }
            }
        }

        return $this->entries = array_values(array_keys($found));
    }

    /**
     * Every family-scoped package that RESOLVES on disk and carries a `dist` — the population the
     * host's Tailwind must be able to see.
     *
     * `dist` is the node_modules-form path (the form the seam plugin emits and hand-written globs
     * use); `real` is its realpath, kept only for dedupe and for matching a glob a host wrote in
     * realpath form.
     *
     * @return list<array{name: string, dist: string, real: string}>
     */
    public function packages(): array
    {
        if ($this->packages !== null) {
            return $this->packages;
        }

        $root = $this->normalize($this->base);
        $seen = [];
        $packages = [];

        foreach (self::SCOPES as $scope) {
            $scopeDir = $this->path('node_modules/'.$scope);

            if (! is_dir($scopeDir)) {
                continue;
            }

            $names = @scandir($scopeDir) ?: [];
            sort($names);

            foreach ($names as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }

                $pkg = $scopeDir.'/'.$name;
                $dist = $pkg.'/dist';

                if (! is_dir($dist)) {
                    continue;
                }

                $realPkg = realpath($pkg);
                $realDist = realpath($dist);

                if ($realPkg === false || $realDist === false) {
                    continue;
                }

                // Inside the host's OWN SOURCE (a self-symlink onto `ui/`, or a workspace-local
                // package) — already covered by the host's own globs. The `node_modules` exemption is
                // load-bearing: under pnpm every package realpaths INSIDE the root and a naive prefix
                // test would exclude the entire population, silently.
                $insideHost = $realPkg === $root || str_starts_with($realPkg, $root.'/');
                $insideModules = in_array('node_modules', explode('/', $realPkg), true);

                if ($insideHost && ! $insideModules) {
                    continue;
                }

                if (isset($seen[$realDist])) {
                    continue;
                }

                $seen[$realDist] = true;
                $packages[] = ['name' => $scope.'/'.$name, 'dist' => $dist, 'real' => $realDist];
            }
        }

        return $this->packages = $packages;
    }

    /**
     * Whether the Vite derivation plugin is wired — the post-plugin branch of the coverage assertion.
     *
     * A grep for the marker over the host's Vite configs. Static and shallow on purpose: the audit
     * asserts the wiring is DECLARED, and whether the transform actually reaches `@tailwindcss/vite`
     * is a build-diff question that belongs in a JS bin, never in `run()`.
     *
     * @return list<string> the config files carrying it, relative to the host root
     */
    public function pluginCarriers(): array
    {
        $markers = $this->list('plugin_markers', ['familySources', 'familyDistSources', 'vite-plugin-family-sources']);
        $carriers = [];

        foreach ($this->list('vite_configs', ['vite.config.ts', 'vite.config.js', 'vite.config.mjs', 'vite.config.mts', 'ui/vite.config.ts', 'ui/vite.config.js']) as $candidate) {
            $contents = $this->read($this->path($candidate));

            if ($contents === null) {
                continue;
            }

            foreach ($markers as $marker) {
                if (str_contains($contents, $marker)) {
                    $carriers[] = $candidate;

                    continue 2;
                }
            }
        }

        return $carriers;
    }

    /**
     * The declared `@source` globs of one CSS entry, canonicalised to absolute lexical paths.
     *
     * Relative to the CSS FILE's own directory (trap 3), lexically — `realpath()` cannot be used, the
     * pattern may carry wildcards. `@source not '...'` (a negation) and `@source inline(...)` are
     * skipped: neither adds scan coverage.
     *
     * @return list<string>
     */
    public function sourcesOf(string $entry): array
    {
        $contents = $this->read($entry);

        if ($contents === null) {
            return [];
        }

        preg_match_all('/@source\s+(not\s+)?["\']([^"\']+)["\']\s*;/', $contents, $matches, PREG_SET_ORDER);

        $dir = dirname($entry);
        $globs = [];

        foreach ($matches as $match) {
            if (trim($match[1]) !== '') {
                continue;
            }

            $raw = trim($match[2]);
            $globs[] = str_starts_with($raw, '/') ? $this->normalize($raw) : $this->normalize($dir.'/'.$raw);
        }

        return array_values(array_unique($globs));
    }

    /** Every declared `@source` glob across every entry, canonicalised. @return list<string> */
    public function sources(): array
    {
        $globs = [];

        foreach ($this->entries() as $entry) {
            foreach ($this->sourcesOf($entry) as $glob) {
                $globs[$glob] = true;
            }
        }

        return array_values(array_keys($globs));
    }

    /**
     * The scannable files under a package's `dist`, in node_modules form.
     *
     * Bounded: a runaway dist must not turn an advisory audit into a hang. `.d.ts` and source maps
     * are dropped — Tailwind would read them, but a declaration file cannot carry a class a host
     * renders, and counting them turns every partial glob into noise.
     *
     * @return list<string>
     */
    public function distFiles(string $dist): array
    {
        $cap = (int) ($this->config['file_cap'] ?? 4000);
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dist, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            $name = $file->getFilename();

            if (str_ends_with($name, '.d.ts') || str_ends_with($name, '.map')) {
                continue;
            }

            if (! in_array($file->getExtension(), self::SCANNABLE, true)) {
                continue;
            }

            $files[] = $file->getPathname();

            if (count($files) >= $cap) {
                break;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Does any declared glob path-match this file?
     *
     * Both forms are tried — the node_modules path and its realpath — because a host may legitimately
     * have written either. A glob naming an existing DIRECTORY covers everything beneath it, which is
     * how Tailwind reads a bare `@source '../dist'`.
     *
     * @param  list<string>  $globs
     */
    public function matched(string $file, array $globs): bool
    {
        $real = realpath($file);
        $candidates = $real === false || $real === $file ? [$file] : [$file, $real];

        foreach ($globs as $glob) {
            foreach ($candidates as $candidate) {
                if ($this->matchesGlob($candidate, $glob)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Fast-glob semantics, which is what Tailwind's `@source` uses: `**` crosses `/`, `*` and `?` do
     * not. A wildcard-free pattern naming a directory is a whole-subtree include.
     */
    public function matchesGlob(string $path, string $glob): bool
    {
        if (! preg_match('/[*?\[]/', $glob)) {
            return $path === $glob || str_starts_with($path, rtrim($glob, '/').'/');
        }

        $regex = '';
        $length = strlen($glob);

        for ($i = 0; $i < $length; $i++) {
            $char = $glob[$i];

            if ($char === '*') {
                if (($glob[$i + 1] ?? '') === '*') {
                    $i++;
                    // `/**/` must also match zero directories, so consume a trailing slash into the
                    // optional group rather than requiring one.
                    if (($glob[$i + 1] ?? '') === '/') {
                        $i++;
                        $regex .= '(?:.*/)?';

                        continue;
                    }
                    $regex .= '.*';

                    continue;
                }
                $regex .= '[^/]*';

                continue;
            }

            $regex .= $char === '?' ? '[^/]' : preg_quote($char, '#');
        }

        return preg_match('#^'.$regex.'$#', $path) === 1;
    }

    /** A `@source` line a human can paste into `$entry` to cover `$dist`. */
    public function pasteableSource(string $entry, string $dist): string
    {
        return "@source '".$this->relative(dirname($entry), $dist)."';";
    }

    /** Host-relative rendering of an absolute path, for findings. */
    public function rel(string $path): string
    {
        $root = $this->normalize($this->base).'/';

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }

    /** Lexical `..`/`.` collapse. Never `realpath()` — the input may carry wildcards. */
    public function normalize(string $path): string
    {
        $absolute = str_starts_with($path, '/');
        $out = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($out !== [] && end($out) !== '..') {
                    array_pop($out);

                    continue;
                }

                if ($absolute) {
                    continue;
                }
            }

            $out[] = $segment;
        }

        return ($absolute ? '/' : '').implode('/', $out);
    }

    private function relative(string $from, string $to): string
    {
        $fromParts = array_values(array_filter(explode('/', $this->normalize($from)), static fn ($s) => $s !== ''));
        $toParts = array_values(array_filter(explode('/', $this->normalize($to)), static fn ($s) => $s !== ''));

        while ($fromParts !== [] && $toParts !== [] && $fromParts[0] === $toParts[0]) {
            array_shift($fromParts);
            array_shift($toParts);
        }

        return implode('/', array_merge(array_fill(0, count($fromParts), '..'), $toParts));
    }

    /** @return list<string> */
    private function cssFiles(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $file): bool => ! $file->isDir() || ! in_array($file->getFilename(), self::SKIP_DIRS, true),
            ),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getExtension() === 'css') {
                $files[] = $this->normalize($file->getPathname());
            }
        }

        return $files;
    }

    public function read(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    public function path(string $relative): string
    {
        return $this->normalize(rtrim($this->base, '/').'/'.ltrim($relative, '/'));
    }

    /**
     * @param  list<string>  $default
     * @return list<string>
     */
    private function list(string $key, array $default): array
    {
        $value = $this->config[$key] ?? null;

        return is_array($value) && $value !== [] ? array_values(array_map(strval(...), $value)) : $default;
    }
}
