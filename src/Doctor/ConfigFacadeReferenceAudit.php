<?php

namespace Splicewire\Beam\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Doctor\Support\FacadeConformanceScope;
use Splicewire\Beam\Doctor\Support\FacadeReferenceScanner;

/**
 * **No config template a family package publishes may call the facade** (beam-facade tickets 05, 10 §5
 * and 19). A `config/*.php` file is evaluated before the container exists, so a facade call there
 * throws — at `config:cache` time too.
 *
 * ## The argument that does NOT justify this check, and the one that does
 * The first case put for it was that the failure mode is a hard throw. **That is an argument against
 * it**: a hazard that fatals loudly and unmissably is precisely the case an advisory audit adds nothing
 * to, and ticket 05 had already celebrated that throw as the *improvement* over the old static's silent
 * fallback to a hardcoded `beam_` prefix — a latent wrong-table bug for any retrofit host.
 *
 * The real case is ticket 03's, and it is narrower: **the break is latent until a host publishes**. The
 * hazard is authored in one repo and detonates in another, so the authoring package's suite stays green
 * while the bomb ships. That is what relocates the check onto **authoring packages before publication**
 * ({@see FacadeConformanceScope::authorablePackageRoots()}) and keeps it off a host's published copy,
 * which fatals on its own and which Beam cannot fix anyway.
 *
 * The live specimen existed when ticket 10 wrote this: `laravel-beam-threads/config/beam/threads.php`
 * imported the bridge at line 3 and executed it at boot via `env('BEAM_THREADS_TABLE',
 * Beam::table('threads'))` at 39–41, unpublished, so no host had detonated on it yet. Ticket 17 removed
 * it — by dropping the `tables` key entirely rather than by inlining, which is what left all 13
 * downstream readers working byte-identically. The population here is now zero, and this check is what
 * keeps it there.
 *
 * ## Comment-awareness is what makes it usable
 * Six `config/*.php` files across the estate name `Beam::` and **all six are comments**, several of them
 * explaining that prefixing is beam core's job — including
 * `laravel-beam/config/beam/core.php:76`, which carries a `{@see \Splicewire\Beam\Facades\Beam::table()}`
 * pointing at exactly the right class. Six false positives on day one is how an advisory check gets
 * ignored. {@see FacadeReferenceScanner::codeReferences()} draws the line where PHP does.
 *
 * ## Grammar
 * The predicate is **execution at config-load time**, so it reads executable position only, and it reads
 * it for both the facade and the deleted bridge — either one detonates. A merely *stale* docblock tag in
 * a config file is a documentation defect, not a load-order hazard, and is deliberately not this check's
 * business; the {@see StubStaticReferenceAudit} sibling covers the same staleness where it actually
 * ships, in the stub population.
 *
 * Advisory ({@see \Splicewire\Beam\BeamServiceProvider::registerFacadeConformanceAudits()}).
 */
class ConfigFacadeReferenceAudit implements DoctorAudit
{
    public const CHECK = 'beam.facade.config-reference';

    /** @param  list<string>  $configDirs  authoring-package `config/` directories */
    public function __construct(protected array $configDirs) {}

    /**
     * Every authorable family package's `config/` directory. A host's own `config/` is deliberately
     * absent — see the class docblock.
     *
     * @param  list<string>|null  $configDirs
     */
    public static function forApp(?array $configDirs = null): self
    {
        $configDirs ??= array_values(array_filter(
            array_map(
                static fn (string $root): string => $root.'/config',
                FacadeConformanceScope::authorablePackageRoots(),
            ),
            'is_dir',
        ));

        return new self($configDirs);
    }

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        $rows = $this->calls();

        if ($rows === []) {
            return [Finding::pass(self::CHECK, sprintf(
                'No package config template calls the Beam facade (%d config file(s) across %d package(s) scanned).',
                count($this->configFiles()),
                count($this->configDirs),
            ))];
        }

        return array_map(fn (array $row): Finding => Finding::warn(self::CHECK, sprintf(
            '%s:%d names %s in executable position. A config file is evaluated before the container '.
            'exists, so this throws when a host publishes it — and at `config:cache` time. The break is '.
            'latent until then, so this package\'s own suite will stay green. Ship the bare value (or drop '.
            'the key and let the model resolve through the facade, as ticket 17 did for beam-threads).',
            $row['file'],
            $row['line'],
            $row['class'],
        )), $rows);
    }

    /**
     * Every facade/bridge call in a package config template, as sorted rows — the work-list.
     *
     * @return list<array{file: string, line: int, class: string}>
     */
    public function calls(): array
    {
        $rows = [];

        foreach ($this->configFiles() as $file) {
            $source = @file_get_contents($file);

            if ($source === false) {
                continue;
            }

            foreach ($this->callsInSource($source) as $row) {
                $rows[] = ['file' => FacadeConformanceScope::displayPath($file)] + $row;
            }
        }

        usort($rows, fn (array $a, array $b): int => [$a['file'], $a['line']] <=> [$b['file'], $b['line']]);

        return $rows;
    }

    /**
     * Parse ONE config file. Pure over source — unit-callable with no disk, container, or DB.
     *
     * @return list<array{line: int, class: string}>
     */
    public function callsInSource(string $source): array
    {
        $rows = [];

        foreach ([FacadeConformanceScope::FACADE_CLASS, FacadeConformanceScope::BRIDGE_CLASS] as $class) {
            foreach (FacadeReferenceScanner::codeReferences($source, $class) as $line) {
                $rows[] = ['line' => $line, 'class' => $class];
            }
        }

        usort($rows, fn (array $a, array $b): int => $a['line'] <=> $b['line']);

        return $rows;
    }

    /**
     * The governed population — every `.php` under the contributed config dirs, recursively (the estate
     * nests them one level: `config/beam/threads.php`, `config/splicewire/timeline.php`).
     *
     * @return list<string>
     */
    public function configFiles(): array
    {
        $files = [];

        foreach ($this->configDirs as $dir) {
            if (! is_dir($dir)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS),
            );

            foreach ($iterator as $entry) {
                /** @var \SplFileInfo $entry */
                $path = $entry->getPathname();

                if ($entry->isFile() && str_ends_with($path, '.php')) {
                    $real = realpath($path);
                    $files[$real === false ? $path : $real] = true;
                }
            }
        }

        $files = array_keys($files);
        sort($files);

        return $files;
    }
}
