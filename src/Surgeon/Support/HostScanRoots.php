<?php

namespace Splicewire\Beam\Surgeon\Support;

use Splicewire\Beam\Doctor\Support\FacadeConformanceScope;
use Splicewire\Beam\Surgeon\CentralPinJustificationAudit;
use Splicewire\Beam\Surgeon\InertiaPropShapeAudit;
use Splicewire\Beam\Surgeon\UndescribedRegistryAudit;

/**
 * The host-scoped scan roots a filesystem audit walks: the host's own source dirs plus **one directory
 * per family package it composes**, resolved through the symlink.
 *
 * ## Why this exists at all — the defect it is the single home for
 *
 * `RecursiveDirectoryIterator` **does not follow symlinks**, and in a co-dev host every
 * `vendor/<vendor>/<package>` IS a symlink to the working checkout. So an audit that hands
 * `base_path('vendor/splicewire')` to that iterator whole walks into a directory containing nothing but
 * links, descends into none of them, and returns a clean empty result. **An empty report and an unread one
 * look identical** — the estate's recurring defect class, *an instrument that reports success by not
 * running*.
 *
 * It has now been found three times, and the third time is why this class exists rather than a fourth copy:
 *
 *   - {@see UndescribedRegistryAudit::forHost()} hit it first — a whole-host scan of `splicewire-app`
 *     produced ONE row — and wrote the repair plus a docblock naming the next instance;
 *   - {@see CentralPinJustificationAudit::forApp()} WAS that named instance, and sat broken anyway,
 *     reporting **zero pins at the flagship** (beam-facade 97: repaired, 0 → 24 pins, 15 unjustified,
 *     across all 15 `~/Herd` hosts carrying a current beam);
 *   - {@see InertiaPropShapeAudit::forApp()} inherited the broken shape from a docblock that said its
 *     `phpFiles()` *"mirrors"* the pin census — copying the neighbour, defect included (beam-facade 149:
 *     **3 findings as shipped, 9 with the roots expanded** at `~/Herd/splicewire-app`; the ticket predicted
 *     8, and the sixth invisible finding is why the count was re-measured rather than quoted).
 *
 * A written warning naming a live instance did not stop the copy; a shared call site does. That is the whole
 * argument for centralizing eight lines.
 *
 * ## Two invariants a caller must not undo
 *
 * **`<pkg>/src` is preferred over `<pkg>`**, and a nested `vendor` dir is never descended into (that guard
 * belongs to each audit's own file walk). Both hold for the same reason: every family package carries its own
 * dev `vendor/` tree, so descending re-scans the whole estate once per package and exhausts a 128 MB limit.
 *
 * **This resolves, it does not classify.** `is_link()` is deliberately NOT consulted. beam-facade 26 ruled
 * resolution is per package rather than per site, and `~/Herd/splicewire-sync` produces a symlinked `vendor/`
 * from site-local forks — so a link tells you nothing about whether the target is ecosystem source.
 * {@see FacadeConformanceScope::authorablePackageRoots()} tests `is_link()`
 * on purpose because it is asking a *different* question (is this package authorable from here); do not
 * unify the two.
 */
class HostScanRoots
{
    /**
     * The vendors whose packages are family source. Fixed, and the same three every audit in this package
     * scans — a host's non-family vendor tree is somebody else's code and out of every audit's jurisdiction.
     *
     * @var list<string>
     */
    public const FAMILY_VENDORS = ['rushing', 'schemastud', 'splicewire'];

    /**
     * The host's own source dirs, in the order an audit would list them. `src` is here because a *package*
     * root running its own doctor has no `app`.
     *
     * @var list<string>
     */
    public const HOST_DIRS = ['app', 'src'];

    /**
     * Resolve the scan roots for the running host: each existing host dir, then one root per family package,
     * `realpath()`-ed so the iterator is handed a real directory rather than a link.
     *
     * Deduplicated by resolved path — two vendor links pointing at one checkout (which the estate does
     * produce) must not be scanned twice — and returned in `$hostDirs`-then-vendor order.
     *
     * @param  list<string>|null  $hostDirs  host-relative dirs, defaulting to {@see HOST_DIRS}. Pass e.g.
     *                                       `['app', 'routes', 'src']` for an audit whose surface includes route
     *                                       files.
     * @param  list<string>|null  $vendors  defaulting to {@see FAMILY_VENDORS}.
     * @return list<string>
     */
    public static function resolve(?array $hostDirs = null, ?array $vendors = null): array
    {
        $found = [];

        foreach ($hostDirs ?? self::HOST_DIRS as $dir) {
            if (is_dir($path = base_path($dir))) {
                $found[(string) realpath($path)] = true;
            }
        }

        foreach ($vendors ?? self::FAMILY_VENDORS as $vendor) {
            foreach (self::packageRoots($vendor) as $root) {
                $found[$root] = true;
            }
        }

        return array_keys($found);
    }

    /**
     * One resolved root per package under `vendor/<vendor>`, preferring `<pkg>/src`.
     *
     * A package whose `realpath()` fails is DROPPED rather than passed through: in this estate a vendor entry
     * that resolves nowhere is a real and recurring state (beam-facade 44's orphaned symlinks into the retired
     * `~/Workspaces/laravel/packages/` layout are in no lock and no `installed.json`, so composer never cleans
     * them), and an audit is not the place to report it — `DanglingPathRepoAudit` owns that finding.
     *
     * @return list<string>
     */
    public static function packageRoots(string $vendor): array
    {
        $roots = [];

        foreach ((array) glob(base_path('vendor/'.$vendor.'/*'), GLOB_ONLYDIR) as $package) {
            $resolved = realpath((string) $package.'/src') ?: realpath((string) $package);

            if (is_string($resolved)) {
                $roots[$resolved] = true;
            }
        }

        return array_keys($roots);
    }
}
