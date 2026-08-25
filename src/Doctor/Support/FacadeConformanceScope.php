<?php

namespace Splicewire\Beam\Doctor\Support;

use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\RegistryArity;
use Splicewire\Beam\Doctor\ConfigFacadeReferenceAudit;
use Splicewire\Beam\Doctor\StubStaticReferenceAudit;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Surgeon\CentralPinJustificationAudit;
use Splicewire\Beam\Surgeon\ComposedTableConfigAudit;
use Splicewire\Beam\Surgeon\ParticleWriteBypassAudit;
use Splicewire\Beam\Surgeon\TablePrefixBypassAudit;

/**
 * The shared scope every facade-conformance audit scans (beam-facade ticket 19): which roots are in
 * play, which files inside them are authorable, and the cross-cutting exclusions all five audits obey.
 * One class rather than five copies, because the exclusions are where the whole regime's correctness
 * lives — ticket 10's census measured a naive check at ~69% false positives, and every percentage point
 * of that came from *where* a hit sat, not from the shape it matched.
 *
 * The five: {@see StubStaticReferenceAudit}, {@see ConfigFacadeReferenceAudit} (doctor side, text-level)
 * and {@see ParticleWriteBypassAudit}, {@see ComposedTableConfigAudit}, {@see TablePrefixBypassAudit}
 * (surgeon side, AST). All advisory.
 *
 * ## Resolution mode — the check ticket 18 asked for, expressed per package root
 * A host resolves the family two ways. Under the `composer.local.json` co-dev overlay,
 * `vendor/<vendor>/<package>` is a **symlink into the source tree**: the vendored copy IS the source,
 * editable, and a drift finding there names a file someone can fix. Without the overlay —
 * `~/Herd/beam-pilot-gcp-cloud-run`, the Cloud Run deploy pilot, which cannot install a path repository —
 * `vendor/` holds **real directories** git-resolved at a pinned commit. That copy is immutable, and
 * conformance is a property of the version it pinned, not of this one: at beam-pilot the pin predates
 * the facade entirely, so `Splicewire\Beam\Beam` exists there and is the *correct* thing for its files
 * to name. Flagging it is the failure mode 18 named.
 *
 * So {@see authorablePackageRoots()} descends into a family vendor directory and takes **only the
 * symlinked children**. This is deliberately a check on the ROOT, not on the file or on beam's own
 * version — no version sniffing, no "is the facade newer than this pin" arithmetic, and no separate
 * mode flag threaded through five audits. It is also the same reasoning as the published-copy
 * exclusion below, one level up: you do not advise hand-editing something a tool regenerates, and you
 * do not advise hand-editing something composer will overwrite.
 *
 * It happens to answer 18's cost warning too (doctor already OOMs at the default 128 MB
 * `memory_limit`): a git-resolved host's entire vendor tree — every family package, every one of their
 * own vendored dependencies — drops out of the walk instead of being read five times.
 *
 * ## The cross-cutting exclusions (ticket 19, "rules every audit obeys")
 * - **`tests/` — by jurisdiction, not inheritance** (10 §7). `Beam::write()` *is* the real write, so a
 *   test resolving the writer by hand differs from the facade only cosmetically; the regime governs
 *   production drift. The cost of including them is not cosmetic: shape 1 goes from 16% to 73% FP.
 * - **Published copies.** Dated `database/migrations/*.php` are verbatim publish output of a `.php.stub`
 *   somewhere upstream — 58 of the census's naive hits were exactly this, the single largest FP block.
 *   The rule keys on the **path**, never on a category surgeon reports: 16 established that surgeon's
 *   `migration-reference` category is `--root`-relative and vanishes when the root moves inside
 *   `migrations/`, so a category-keyed exclusion silently stops excluding.
 * - **`.php.stub` included.** The same rule's other half, and the larger of the two problems it fixes: a
 *   default `*.php` glob drops the stub sources where the fix actually belongs — the largest
 *   false-negative gap in the census.
 * - **The owning package's `src/`** ({@see isOwningPackageSource()}), for the three surgeon shapes.
 *   `BeamManager::write()`'s body is shape 1 verbatim and `BeamManager.php`'s docblock spells out shape 3
 *   verbatim in order to document what `tableFor()` replaces. The fix flags itself twice; this is the
 *   owning-package exclusion earning its place mechanically rather than by inheritance.
 * - **A host's own `config/`** is out of every root list: those are published copies, they fatal on
 *   their own if they name the facade, and Beam cannot fix them. {@see ConfigFacadeReferenceAudit}
 *   scans authoring packages *before* publication for exactly that reason.
 *
 * Not an exclusion, a construction constraint: **never key on a class name** (10 §7). Shape 2 of the
 * census — `ParticleWriter` reached by constructor DI — was 9 naive hits and 9 false positives, which is
 * 04's `SchemaTargetResolver` rejection made mechanical. The audits key on resolution verbs and call
 * positions instead, which is why three of them are AST-side.
 */
#[IsRegistry(
    root: 'beam.doctor.facade-scope',
    of: 'the authorable roots + file set the five facade-conformance audits share (one walk, not five)',
    arity: RegistryArity::RunAll,
    entryType: 'string',
    onDuplicate: OnDuplicate::Admit,
    note: 'Entries are absolute path strings, not objects — which is what `entryType` now SAYS, having '
        .'said `mixed` while the sentence below said `string` (registry-kernel ticket 47). Constructor-'
        .'seeded, and nothing pushes into '
        .'it after binding — the roots are seeded once by '
        .'forApp(), which is also where the resolution-mode rule lives (a vendor/ package joins the scan '
        .'only when it is a SYMLINK). A host with an unusual source layout REBINDS the singleton; it does '
        .'not register. That is real information, not an absent seam.',
    order: 11,
)]
class FacadeConformanceScope
{
    /** The family vendor directories whose symlinked children are live source. */
    public const FAMILY_VENDORS = ['splicewire', 'rushing', 'schemastud'];

    /**
     * Path fragments — matched against a file's path RELATIVE to the root it was found under, so a
     * package root reached through `vendor/` doesn't exclude itself — whose files never carry a
     * production-drift finding.
     *
     * @var list<string>
     */
    public const EXCLUDED_RELATIVE_FRAGMENTS = [
        'tests/',
        'test/',
        'vendor/',
        'node_modules/',
        '.git/',
        'storage/',
        'bootstrap/cache/',
    ];

    /** The class the facade replaced. Deleted by ticket 18; naming it is drift everywhere it is authorable. */
    public const BRIDGE_CLASS = 'Splicewire\Beam\Beam';

    /** The facade itself — {@see Beam}. */
    public const FACADE_CLASS = 'Splicewire\Beam\Facades\Beam';

    /** The instance behind the facade. A call site outside the owning package naming it directly is 06's drift shape. */
    public const MANAGER_CLASS = 'Splicewire\Beam\BeamManager';

    /** @var list<string>|null memoized: the walk is shared by five audits, so it happens once per scope */
    private ?array $files = null;

    /** @param  list<string>  $roots  absolute directory paths */
    public function __construct(public array $roots) {}

    /**
     * The default host-scoped wiring: the host's own authored code plus every family package it composes
     * **through the overlay**. Mirrors {@see CentralPinJustificationAudit::forApp()}
     * in reaching into `vendor/` deliberately — the estate's drift lives overwhelmingly in family
     * PACKAGES, so an `app/`-only scan reports a comfortable zero from inside every host while the
     * backlog sits one directory over — and differs from it in exactly one way, the symlink test above.
     *
     * `config/` is absent by design (published copies; see the class docblock).
     *
     * @param  list<string>|null  $roots
     */
    public static function forApp(?array $roots = null): self
    {
        $roots ??= array_values(array_filter([
            base_path('app'),
            base_path('src'),
            base_path('routes'),
            base_path('database'),
            ...static::authorablePackageRoots(),
        ], 'is_dir'));

        return new self(array_values($roots));
    }

    /**
     * Every family package root that is live, editable source in this host: a child of
     * `vendor/{splicewire,rushing,schemastud}` that is a **symlink**. See the class docblock — this is the
     * resolution-mode check, and a real directory there is a pinned composer artifact, not a work item.
     *
     * @return list<string>
     */
    public static function authorablePackageRoots(): array
    {
        $roots = [];

        foreach (static::FAMILY_VENDORS as $vendor) {
            $dir = base_path("vendor/{$vendor}");

            if (! is_dir($dir)) {
                continue;
            }

            foreach ((array) glob($dir.'/*', GLOB_ONLYDIR) as $package) {
                $package = (string) $package;

                // The overlay's tell. A real directory here is git-resolved at a pinned commit: immutable,
                // and conformant to the version it pinned rather than to this one.
                if (! is_link($package)) {
                    continue;
                }

                $roots[] = $package;
            }
        }

        sort($roots);

        return $roots;
    }

    /**
     * Every scannable file under the roots — `.php` and `.php.stub` — absolute, sorted, deduplicated by
     * realpath. Sorted so a re-run with no code change produces a byte-identical finding list;
     * filesystem iteration order is not stable enough to compare against.
     *
     * Deduplication is by **realpath** (07's ruling): a Herd site's `vendor/` symlinks reach real package
     * source, and `~/Herd/beam` is itself a symlink to `laravel-beam-starter`, so path-keyed
     * deduplication counts one population twice — which is exactly how ticket 02's largest population was
     * over-counted by 24 before 07 caught it.
     *
     * @return list<string>
     */
    public function files(): array
    {
        if ($this->files !== null) {
            return $this->files;
        }

        $seen = [];

        foreach ($this->roots as $root) {
            if (! is_dir($root)) {
                continue;
            }

            foreach ($this->walk($root) as $path) {
                if (! static::isScannable($path) || static::isPublishedMigration($path)) {
                    continue;
                }

                $real = realpath($path);
                $seen[$real === false ? $path : $real] = true;
            }
        }

        $files = array_keys($seen);
        sort($files);

        return $this->files = $files;
    }

    /**
     * Every file under one root, with the excluded directories **pruned during traversal** rather than
     * filtered afterwards. The distinction is not stylistic: a family package root reached through the
     * overlay carries its own `vendor/` and `node_modules/`, so a filter-after-walk still descends into
     * tens of thousands of files it is about to discard, ~20 times over. Pruning is what makes five audits
     * sharing one walk affordable in a command that already OOMs at 128 MB (ticket 18 §5).
     *
     * @return \Generator<string>
     */
    private function walk(string $root): \Generator
    {
        $directory = new \RecursiveDirectoryIterator(
            $root,
            \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS,
        );

        $filter = new \RecursiveCallbackFilterIterator(
            $directory,
            static function (\SplFileInfo $entry) use ($root): bool {
                $relative = ltrim(substr($entry->getPathname(), strlen($root)), '/\\');

                return ! static::isExcludedRelativePath($entry->isDir() ? $relative.'/' : $relative);
            },
        );

        foreach (new \RecursiveIteratorIterator($filter) as $entry) {
            /** @var \SplFileInfo $entry */
            if ($entry->isFile()) {
                yield $entry->getPathname();
            }
        }
    }

    /**
     * The files whose raw source contains at least one needle — the prefilter every audit runs before
     * parsing. Reading ~7k files five times is cheap (the OS caches them); building five ASTs of each is
     * not, and doctor is already OOMing at 128 MB in `HouseStyleStripOperation::plan()` (18 §5). The
     * candidate set after this filter is a couple of dozen files estate-wide.
     *
     * @param  list<string>  $needles
     * @return array<string, string> absolute path => source
     */
    public function sourcesContaining(array $needles): array
    {
        $matched = [];

        foreach ($this->files() as $file) {
            $source = @file_get_contents($file);

            if ($source === false) {
                continue;
            }

            foreach ($needles as $needle) {
                if (str_contains($source, $needle)) {
                    $matched[$file] = $source;

                    break;
                }
            }
        }

        return $matched;
    }

    /** `.php` or `.php.stub` — the second half of "exclude dated migrations, include `.stub`". */
    public static function isScannable(string $path): bool
    {
        return str_ends_with($path, '.php') || str_ends_with($path, '.php.stub');
    }

    /** Whether a root-relative path sits under one of {@see EXCLUDED_RELATIVE_FRAGMENTS}. */
    public static function isExcludedRelativePath(string $relative): bool
    {
        $relative = str_replace('\\', '/', $relative);

        foreach (static::EXCLUDED_RELATIVE_FRAGMENTS as $fragment) {
            if (str_starts_with($relative, $fragment) || str_contains($relative, '/'.$fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A dated `database/migrations/**\/*.php` — publish output, a divergent fork of some `.php.stub`
     * upstream. Keyed on the **path and the timestamp in the filename**, never on a surgeon category
     * (16's amendment: that category is `--root`-relative and disappears when the root moves).
     *
     * A `.php.stub` never matches, whatever it is named: the stub is the authored origin, and it is the
     * one file in this population where a fix belongs.
     */
    public static function isPublishedMigration(string $path): bool
    {
        $path = str_replace('\\', '/', $path);

        if (! str_contains($path, '/database/migrations/') || ! str_ends_with($path, '.php')) {
            return false;
        }

        return preg_match('/\/\d{4}_\d{2}_\d{2}_\d{6}_[^\/]+\.php$/', $path) === 1;
    }

    /**
     * A path for a finding's detail line: relative to the app base path where it sits under it, and
     * otherwise shortened to `<package>/<path-inside-the-package>`. A finding reached through a
     * `vendor/` symlink resolves to somewhere under `~/Workspaces`, and the absolute path of a symlink
     * target is both long and misleading about where the reader should go looking.
     */
    public static function displayPath(string $path): string
    {
        // Guarded rather than assumed: the pure cores of these audits are unit-driven with no application
        // bound, and `base_path()` throws rather than returning null when the container is absent.
        try {
            $base = function_exists('base_path') ? realpath(base_path()) : false;
        } catch (\Throwable) {
            $base = false;
        }

        $real = realpath($path);
        $real = $real === false ? $path : $real;

        if ($base !== false && str_starts_with($real, $base.DIRECTORY_SEPARATOR)) {
            return ltrim(substr($real, strlen($base)), '/\\');
        }

        // `.../packages/splicewire/laravel-beam-ux/src/Models/BeamUxEntry.php` → `laravel-beam-ux/src/Models/…`.
        foreach (static::FAMILY_VENDORS as $vendor) {
            $marker = DIRECTORY_SEPARATOR.$vendor.DIRECTORY_SEPARATOR;
            $at = strrpos($real, $marker);

            if ($at !== false) {
                return $vendor.'/'.ltrim(substr($real, $at + strlen($marker)), '/\\');
            }
        }

        return $real;
    }

    /**
     * Whether a file is `splicewire/laravel-beam`'s own `src/` — the owning-package exclusion, resolved
     * by realpath from this very file rather than by matching a path fragment, so it holds through the
     * overlay's symlinks and inside beam's own test suite alike.
     */
    public static function isOwningPackageSource(string $path): bool
    {
        $src = realpath(dirname(__DIR__, 2));

        if ($src === false) {
            return false;
        }

        $real = realpath($path);

        return str_starts_with($real === false ? $path : $real, $src.DIRECTORY_SEPARATOR);
    }
}
