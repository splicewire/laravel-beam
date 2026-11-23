<?php

namespace Splicewire\Beam\Doctor\Support;

use DOMDocument;
use DOMElement;
use Throwable;

/**
 * The static read-model both halves of browser-journeys 13's mount check share: it answers, for ONE host,
 * "which installed `splicewire/*` package ships a `tests/Browser` suite, and does this host's phpunit.xml
 * actually collect it?" — from files alone, booting nothing (ticket 03 Q2/Q3).
 *
 * ## One inventory, two consumers
 *
 * {@see \Splicewire\Beam\Doctor\BrowserSuiteMountAudit} reads it to report an installed-but-unmounted or
 * empty suite before a run; {@see BrowserCoverageReader} reads it AFTER a run to know which package suites
 * were EXPECTED, so a JUnit file that collected zero testcases for a package is called `not-collected`
 * rather than silently clean (ticket 03 Q6). Sharing one inventory is what lets the reader attribute a
 * not-collected suite to the same cause the audit names (unmounted vs. empty vs. filtered).
 *
 * ## It resolves globs the way the runtime does (F3)
 *
 * The one sanctioned mount is `vendor/splicewire/<pkg>/tests/Browser` — a single-segment `splicewire/*` glob,
 * NOT whole-vendor and NOT a `{a,b}` brace list, because PHP `glob()` has no `GLOB_BRACE` on the default
 * path and a `rushing/schemastud` suite must surface as `unmounted`, not be silently swept in. This reads
 * the install with the same `glob()` the host resolves at boot, so the inventory sees exactly the dirs a
 * real run would mount — never a guess about what "should" be installed.
 *
 * ## It never throws
 *
 * Every read is a file read behind a guard: a missing composer.json, an unparseable phpunit.xml, an absent
 * vendor/ — each degrades to an empty answer, because this is consumed by a doctor audit that runs from
 * artisan on a partially-composed host (F12), where "there is nothing to read" is an ordinary state, not a
 * defect. A host without the Pest browser plugin is reported (so the audit can NOTE it), not crashed into.
 */
final class BrowserSuiteInventory
{
    /** The one sanctioned installed-package mount (ticket 03 Q2); `splicewire/*` is a single glob segment. */
    public const MOUNT_GLOB = 'vendor/splicewire/*/tests/Browser';

    /** PHPUnit's default test-file suffix — what a `<directory>` testsuite entry collects unless it says otherwise. */
    private const DEFAULT_SUFFIX = 'Test.php';

    public function __construct(private readonly ?string $basePath = null) {}

    /**
     * Every installed `splicewire/*` package that ships a `tests/Browser` directory, with the count of
     * test files in it and whether this host's phpunit.xml collects that directory.
     *
     * A directory present but empty of `*Test.php` files is still listed (count 0) so the audit can tell a
     * mounted-but-empty suite apart from one that is simply not installed.
     *
     * @return list<array{package: string, path: string, testFileCount: int, mounted: bool}>
     */
    public function packages(): array
    {
        $collected = $this->collectedBrowserDirs();

        $out = [];
        foreach ($this->installedBrowserDirs() as $dir) {
            $real = realpath($dir) ?: $dir;
            $out[] = [
                'package' => $this->packageOf($dir),
                'path' => $real,
                'testFileCount' => $this->testFileCount($dir),
                'mounted' => isset($collected[$real]),
            ];
        }

        usort($out, static fn (array $a, array $b): int => strcmp($a['package'], $b['package']));

        return $out;
    }

    /**
     * The `splicewire/*` package `tests/Browser` directories actually present in this install, resolved with
     * the runtime `glob()` (F3). An absent vendor/ or no match yields `[]`.
     *
     * @return list<string>
     */
    public function installedBrowserDirs(): array
    {
        $matches = @glob($this->path(self::MOUNT_GLOB), GLOB_ONLYDIR);

        return $matches === false ? [] : array_values($matches);
    }

    /**
     * The real directory paths this host's phpunit.xml testsuites collect, keyed by realpath for an O(1)
     * membership test. Each testsuite `<directory>` is resolved with `glob()` exactly as the mount author
     * wrote it, so a `vendor/splicewire/<pkg>/tests/Browser` entry expands to the same dirs the installer sees.
     *
     * @return array<string, true>
     */
    public function collectedBrowserDirs(): array
    {
        $dirs = [];

        foreach ($this->testsuiteDirectoryEntries() as $relative) {
            $matches = @glob($this->path($relative), GLOB_ONLYDIR);
            if ($matches === false) {
                // A literal (non-glob) directory that exists but glob declined: fall back to a realpath read.
                $real = realpath($this->path($relative));
                if ($real !== false) {
                    $dirs[$real] = true;
                }

                continue;
            }

            foreach ($matches as $match) {
                $real = realpath($match);
                if ($real !== false) {
                    $dirs[$real] = true;
                }
            }
        }

        return $dirs;
    }

    /** Whether a `<testsuite>` in phpunit.xml declares the sanctioned installed-package mount glob. */
    public function mountPatternDeclared(): bool
    {
        foreach ($this->testsuiteDirectoryEntries() as $relative) {
            if ($this->normalizeGlob($relative) === self::MOUNT_GLOB) {
                return true;
            }
        }

        return false;
    }

    /** Whether this host has a readable phpunit.xml (or phpunit.xml.dist). */
    public function phpunitExists(): bool
    {
        return $this->phpunitPath() !== null;
    }

    /**
     * The major version of Pest this host declares in composer (require or require-dev), best-effort from the
     * constraint's first integer. `null` when `pestphp/pest` is not declared at all — a host that cannot run
     * Pest browser tests.
     */
    public function pestMajor(): ?int
    {
        $constraint = $this->declaredVersion('pestphp/pest');
        if ($constraint === null) {
            return null;
        }

        return preg_match('/(\d+)/', $constraint, $m) === 1 ? (int) $m[1] : null;
    }

    /** Whether this host declares the Pest browser plugin (required for a `->use(Browsable::class)` mount, F2/F12). */
    public function hasBrowserPlugin(): bool
    {
        return $this->declaredVersion('pestphp/pest-plugin-browser') !== null;
    }

    /* ---------------- file reads (each degrades, never throws) ---------------- */

    /**
     * The `<directory>` entries under every `<testsuite>` in this host's phpunit.xml (NOT the ones under
     * `<source>`/`<coverage>`, which bound code coverage, not test collection). Returns the raw relative
     * text so the caller resolves it against the base path.
     *
     * @return list<string>
     */
    private function testsuiteDirectoryEntries(): array
    {
        $path = $this->phpunitPath();
        if ($path === null) {
            return [];
        }

        try {
            $xml = (string) @file_get_contents($path);
            if ($xml === '') {
                return [];
            }

            $previous = libxml_use_internal_errors(true);
            $dom = new DOMDocument;
            $loaded = $dom->loadXML($xml);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            if ($loaded === false) {
                return [];
            }

            $entries = [];
            foreach ($dom->getElementsByTagName('testsuite') as $suite) {
                if (! $suite instanceof DOMElement) {
                    continue;
                }
                foreach ($suite->getElementsByTagName('directory') as $directory) {
                    $text = trim($directory->textContent);
                    if ($text !== '') {
                        $entries[] = $text;
                    }
                }
            }

            return $entries;
        } catch (Throwable) {
            return [];
        }
    }

    /** Count `*Test.php` files in a directory tree — the files a default-suffix `<directory>` testsuite collects. */
    private function testFileCount(string $dir): int
    {
        $count = 0;

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile() && str_ends_with($file->getFilename(), self::DEFAULT_SUFFIX)) {
                    $count++;
                }
            }
        } catch (Throwable) {
            return 0;
        }

        return $count;
    }

    /** The composer package name owning a `.../vendor/splicewire/<pkg>/tests/Browser` path. */
    private function packageOf(string $browserDir): string
    {
        // .../vendor/splicewire/<pkg>/tests/Browser  →  splicewire/<pkg>
        $parts = explode('/vendor/', str_replace('\\', '/', $browserDir), 2);
        if (count($parts) === 2) {
            $segments = explode('/', trim($parts[1], '/'));
            if (count($segments) >= 2) {
                return $segments[0].'/'.$segments[1];
            }
        }

        return $browserDir;
    }

    /** A declared version constraint for a composer package (require or require-dev), or null. */
    private function declaredVersion(string $package): ?string
    {
        $composer = $this->composer();
        $require = (array) ($composer['require'] ?? []);
        $dev = (array) ($composer['require-dev'] ?? []);
        $declared = $dev + $require;

        $value = $declared[$package] ?? null;

        return is_string($value) ? $value : null;
    }

    /** @return array<string, mixed> */
    private function composer(): array
    {
        $path = $this->path('composer.json');
        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) @file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** The host phpunit config path (phpunit.xml preferred, then phpunit.xml.dist), or null. */
    private function phpunitPath(): ?string
    {
        foreach (['phpunit.xml', 'phpunit.xml.dist'] as $name) {
            $candidate = $this->path($name);
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** Collapse a glob's run of path noise so a declared entry can be compared to {@see MOUNT_GLOB}. */
    private function normalizeGlob(string $relative): string
    {
        return trim(preg_replace('#/+#', '/', str_replace('\\', '/', $relative)) ?? $relative, '/');
    }

    private function path(string $relative): string
    {
        $base = $this->basePath ?? base_path();

        return rtrim($base, '/').'/'.$relative;
    }
}
