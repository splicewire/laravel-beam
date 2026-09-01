<?php

declare(strict_types=1);

namespace Splicewire\Beam\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Doctor\Support\FamilyTailwindScan;

/**
 * ADVISORY: can this host's Tailwind actually SEE the family packages it resolves?
 *
 * Tailwind v4 does not follow symlinked `node_modules`, and every family package resolves as a
 * symlink onto workspace source. A utility class used only inside a package's built `dist` is
 * therefore never generated — correct markup, absent classes, **HTTP 200**. A green PHP suite, a
 * green JS suite, a clean `tsc`, a passing `beam:ux:compile` and a passing doctor audit are all
 * blind to it; the only prior instrument was opening the page.
 *
 * ## The assertion is TWO-BRANCHED, and that is the design
 *
 *   *a derivation plugin is wired* **OR** *every resolved family `dist` is path-matched by a
 *   declared `@source` glob*
 *
 * The first branch is the post-migration world (`familySources()` off `@schemastud/seam/vite`
 * derives the list at build time); the second is the hand-written allowlist that still exists at most
 * roots. A host satisfies either. Written any other way this audit would open a finding on every root
 * the day the plugin lands, or go blind the day the allowlist is deleted — and it has to survive its
 * own migration, because the fix for a fleet of findings is landing the plugin once, not editing
 * eleven stylesheets.
 *
 * ## Advisory, never a throw
 *
 * "Does this host scan this dist" is a fact about the HOST, not about anything a declaration's author
 * could have gotten right — the rule that took `~/Herd/tower` down once already. It registers on
 * {@see BeamDoctorManifest} with `gate: false` and renders as a work-list.
 *
 * ## What it cannot see — do not quote a green run as proof of styling
 *
 * Dynamically-constructed class names, `@splicewire/beam-ux`'s self-injected `pe-*`/`ve-*` namespace
 * (`src/canvas/css.ts`, deliberately out of scope), arbitrary-value classes (`bg-[var(--x)]`), and
 * whether the wired plugin's transform actually reaches `@tailwindcss/vite`. The only instrument that
 * sees the real defect is a build diff of dist class names against the compiled stylesheet, and per
 * the estate's standing ruling (beam-ux-prototype's `PrototypeWiringAudit`, which keeps its one
 * build-dependent check out of `run()` and shells to a JS bin) that check belongs in a JS bin invoked
 * on demand, never here. Everything in this class is static file inspection.
 *
 * (Named as prose rather than a `{@see}`: beam-core does not depend on beam-ux-prototype, and an
 * import for a docblock would invert the layering for a cross-reference.)
 *
 * @see FamilyTokenContractAudit for the lower-tier companion — a scanned dist whose tokens the host
 *      never declared is styled by nothing, and this audit reports it CLEAN.
 */
class FamilySourceCoverageAudit implements DoctorAudit
{
    public const CHECK = 'family @source coverage';

    public function __construct(private readonly FamilyTailwindScan $scan) {}

    /** @return list<Finding> */
    public function run(): array
    {
        return [$this->coverage()];
    }

    public function coverage(): Finding
    {
        $entries = $this->scan->entries();

        // Population: a Tailwind v4 entry. A v3 host has no `@source` at all and a host with none is
        // not in scope — reported as a pass with the reason, so a clean line is never mistaken for a
        // check that ran.
        if ($entries === []) {
            return Finding::inconclusive(self::CHECK, 'no Tailwind v4 CSS entry found — this host is out of the audit\'s population.');
        }

        $packages = $this->scan->packages();

        if ($packages === []) {
            return Finding::inconclusive(self::CHECK, 'no family-scoped package with a `dist` resolves in node_modules — nothing to scan.');
        }

        // Branch 1: the list is DERIVED. Nothing to path-match, and nothing that can drift.
        $carriers = $this->scan->pluginCarriers();

        if ($carriers !== []) {
            return Finding::pass(
                self::CHECK,
                'the @source list is derived at build time by '.implode(', ', $carriers).
                ' — '.count($packages).' resolved family package(s) are covered by construction.'
            );
        }

        // Branch 2: path-match the hand-written allowlist. Per package, per FILE — a `dist/*.js` glob
        // reaching 22 of 23 dist files is under-coverage that any name-keyed set difference calls clean.
        $globs = $this->scan->sources();
        $gaps = [];

        foreach ($packages as $package) {
            $files = $this->scan->distFiles($package['dist']);

            if ($files === []) {
                continue;
            }

            $unscanned = 0;

            foreach ($files as $file) {
                if (! $this->scan->matched($file, $globs)) {
                    $unscanned++;
                }
            }

            if ($unscanned > 0) {
                $gaps[] = [
                    'name' => $package['name'],
                    'dist' => $package['dist'],
                    'unscanned' => $unscanned,
                    'total' => count($files),
                ];
            }
        }

        if ($gaps === []) {
            return Finding::pass(
                self::CHECK,
                count($packages).' resolved family package(s) are path-matched by '.count($globs).' declared @source glob(s).'
            );
        }

        // Root x package pairs with the paste-ready glob, not a count: the finding IS the work-list.
        $entry = $entries[0];
        $lines = array_map(
            fn (array $gap): string => '  - '.$gap['name'].' ('.$gap['unscanned'].' of '.$gap['total'].
                ' dist files unscanned)  '.$this->scan->pasteableSource($entry, $gap['dist']),
            $gaps,
        );

        return Finding::fail(
            self::CHECK,
            count($gaps).' of '.count($packages).' resolved family package(s) are not scanned by any @source glob in '.
            $this->scan->rel($entry).' — their dist classes are silently stripped (correct markup, absent classes, HTTP 200). '.
            'Preferred fix is to wire `familySources()` from `@schemastud/seam/vite` once, rather than pasting these:'.PHP_EOL.
            implode(PHP_EOL, $lines)
        );
    }
}
