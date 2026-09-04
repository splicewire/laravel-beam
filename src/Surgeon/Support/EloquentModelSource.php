<?php

namespace Splicewire\Beam\Surgeon\Support;

use Rushing\Popcorn\Discovery\AttributedClassScanner;
use Splicewire\Beam\Surgeon\ModelSurfaceCoverageAudit;
use Splicewire\Beam\Surgeon\MorphAliasCoverageAudit;
use Symfony\Component\Finder\Finder;

/**
 * The concrete Eloquent models declared under a set of scan roots, decided from **source text**.
 *
 * ## Why this is static analysis and not reflection
 *
 * The reasoning is {@see MorphAliasCoverageAudit::declaresEloquentModel()}'s, which grew this census
 * first and still carries its own copy (see the fork note below). Restated because it is the property
 * a future caller is most likely to "simplify" away:
 *
 * The obvious implementation is `class_exists($class) && is_subclass_of($class, Model::class)`. It is
 * not usable here, because `class_exists()` AUTOLOADS, and autoloading is not a safe operation to
 * perform over a whole vendor tree. A class whose parent is a dev-only dependency raises an `Error`;
 * worse, a class whose method signature is incompatible with its parent's is a COMPILE-time fatal that
 * no `try`/`catch` can contain — it kills the process, and this estate has at least one
 * (`Splicewire\Tower\Policies\ModelStatusPolicy::update()`). An audit is a diagnostic: it has to
 * survive a codebase that is already broken, which is precisely the codebase it will be run against.
 *
 * ## The census is a FLOOR, and it says so out loud
 *
 * Two blind spots, and the difference between them is the whole reason {@see Scan::$unnamed} exists.
 *
 * **Known:** a model extending a family base class several hops from `Model` (rather than `Model` or
 * `Pivot` directly) is not recognised and is silently skipped. That under-reports and never
 * false-positives, which is the right direction for an advisory backlog.
 *
 * **Unknown:** a file whose text declares a model but whose tokens name no class — a fragment with no
 * open tag, a file that would not read. Those are COUNTED and returned, because an instrument that
 * enumerates only its known blind spots reads as thorough exactly where it is weakest. A caller with a
 * non-empty `unnamed` list must not report a clean run.
 *
 * ## ⚠️ Fork note — deliberate, time-boxed, and TICKETED
 *
 * {@see MorphAliasCoverageAudit} still carries its own private copy of `declaresEloquentModel()` and
 * its own `modelsIn()`. That is one census in two places — the fork shape {@see FamilyPackageSource}
 * was extracted to end — and it is left standing only because that file was under a concurrent edit
 * when {@see ModelSurfaceCoverageAudit} landed, where this repo's rule is to wait rather than commit
 * around someone.
 *
 * The two have already DIVERGED in behaviour: this copy folds `abstract` into the modifier group and
 * that one does not, which is why the guard is live here and dead there. Nothing is wrong at a host
 * today (both reject abstract models, by different routes); the cost is that a fix to one regex has no
 * mechanical way to reach the other.
 *
 * Folding them is `competitive-landscape` ticket **09**, filed rather than left as prose — a follow-up
 * that lives only in a docblock is this estate's signature way of never happening.
 */
class EloquentModelSource
{
    /**
     * Directory names never descended into. `vendor` is the load-bearing one: every family package
     * carries its own dev `vendor/` tree, so descending re-scans the whole estate once per package and
     * exhausts a 128 MB limit ({@see HostScanRoots}'s second invariant, which says the guard belongs to
     * each caller's own file walk). The rest are fixture roots — a test double is never live code, so a
     * model declared in one is noise, and its parents may not even be installed.
     *
     * @var list<string>
     */
    public const SKIP_DIRS = ['vendor', 'node_modules', 'tests', 'test', 'fixtures', 'stubs'];

    public function __construct(protected AttributedClassScanner $scanner) {}

    /**
     * Walk the given roots and report what was found — and what could not be read.
     *
     * @param  list<string>  $roots  absolute directories; one that does not exist contributes nothing
     */
    public function scan(array $roots): Scan
    {
        $models = [];
        $unnamed = [];
        $files = 0;

        foreach ($this->uniqueDirs($roots) as $dir) {
            foreach ($this->finder($dir) as $file) {
                $files++;

                $source = @file_get_contents($path = $file->getRealPath());

                if ($source === false || ! $this->declaresEloquentModel($source)) {
                    continue;
                }

                $class = $this->scanner->classNameFromFile($path);

                if ($class === null) {
                    $unnamed[] = $path;

                    continue;
                }

                $models[$class] = $path;
            }
        }

        return new Scan($models, $unnamed, $files);
    }

    /**
     * Whether a file declares a CONCRETE Eloquent model.
     *
     * Abstract bases are skipped: an abstract model has no rows and no surface of its own; its concrete
     * children are scanned separately and are where a resource would be declared.
     */
    public function declaresEloquentModel(string $source): bool
    {
        if (preg_match('/^\s*abstract\s+class\s/m', $source)) {
            return false;
        }

        // `extends Model`, `extends Pivot`, or either fully qualified. The short forms are what the
        // estate writes (every model imports `Illuminate\Database\Eloquent\Model`).
        //
        // ⚠️ `abstract` is IN the modifier group deliberately, and it is the one difference from
        // {@see MorphAliasCoverageAudit}'s copy. There it is absent, which makes the guard above dead
        // code: `abstract class X extends Model` fails the modifier group and is rejected by the
        // pattern, never by the guard. A test written against that shape passes whether or not the
        // guard exists — measured, by deleting the guard and watching nothing go red. Matching it here
        // and rejecting it above makes the two steps compose, so the guard is the thing that decides
        // and a future edit that drops it fails a test.
        return (bool) preg_match(
            '/^\s*(?:abstract\s+|final\s+|readonly\s+)*class\s+\w+\s+extends\s+(?:\\\\?Illuminate\\\\Database\\\\Eloquent\\\\)?(?:Model|Pivot)\b/m',
            $source
        );
    }

    /**
     * Existing roots, deduplicated by resolved path. Two vendor links pointing at one checkout is a
     * state this estate produces, and scanning it twice would double every finding from it.
     *
     * @param  list<string>  $roots
     * @return list<string>
     */
    protected function uniqueDirs(array $roots): array
    {
        $seen = [];

        foreach ($roots as $root) {
            if (is_dir($root) && is_string($resolved = realpath($root))) {
                $seen[$resolved] = true;
            }
        }

        return array_keys($seen);
    }

    protected function finder(string $dir): Finder
    {
        return (new Finder)->files()->in($dir)->name('*.php')->exclude(self::SKIP_DIRS);
    }
}
