<?php

namespace Splicewire\Beam\Install;

use InvalidArgumentException;

/**
 * `--travel=` — the installer's lever over **where a newly published stub lands** among the migrations
 * that have not yet run (beam-facade ticket 117, spun out of 86).
 *
 * `vendor:publish` stamps a migration with the moment it was published, so a stub always lands at the END
 * of the host's set. That is right for a package joining an estate and wrong whenever the host already
 * carries migrations the new block has to sort BEFORE. The only lever over that is the filename, and until
 * now the only way to spend it was a hand re-date — which the publish-ordering convention rejects, not
 * because it fails (package-tools re-finds a published file by basename, so a re-date survives the next
 * `vendor:publish`) but because **the next greenfield clone does not inherit it**. That is the entire
 * argument for putting the lever in the installer, and it is 29's asymmetry unchanged: a **published copy**
 * under `database/migrations/**` may be re-dated, a package's own `loadMigrationsFrom` source may never be.
 *
 * ## What it can and cannot do — stated in the interface, not just the docs
 *
 * Laravel's migrator takes every file from the registered paths, subtracts the rows already in the
 * `migrations` table (matched by filename stem), sorts what remains by name, and runs it. So:
 *
 * - **Filename date orders PENDING files only.** Publishing with an earlier stamp makes a file sort first
 *   among things that have not run. It can never make it run "before" something already applied.
 * - **Therefore this governs fresh and reset databases** — a new environment, a new tenant schema,
 *   `migrate:fresh` — which is exactly where the estate's greenfield posture says it should be operating.
 *
 * **The other half is deliberately not here.** Making an already-run migration pending again by dropping
 * its ledger row re-runs a `create_*` against a live table, and it is only survivable under a posture
 * beam-facade 115 owns the exit of. Naming both halves "travel" would hide that one governs fresh
 * databases and the other governs live ones.
 *
 * **And it stays out of that half by CONSTRUCTION, not by care.** {@see shift()} moves exactly the files
 * that did not exist before this install run — and a file that did not exist cannot have run, so no move
 * this class makes can ever orphan a ledger row. `BeamInstallCommand::carryMigrationRecord()`, which
 * exists so the ownership pass's re-dates do not re-run, therefore has nothing to do here, and its
 * docblock stays true.
 *
 * ## Relative only — an absolute anchor is the band ticket 22 rejected
 *
 * The value is a **relative** expression (`-1 year`, `+2 days`), never a date. Three reasons, and each is
 * an existing ruling rather than a new one:
 *
 * - **22 rejected a `0001_01_01_*` publish band on merit** — invisible magic that also outranks a host's
 *   own deliberate migration. Pinning a run's files to a chosen absolute stamp is that band with the
 *   operator's fingerprints on it; a shift is displacement, which is what 29 chose instead.
 * - **A shift preserves the run's internal order for free.** Install order IS migration order (the
 *   publish-ordering convention's whole rule, and the `$order` tier table that spends it), so anything
 *   that collapses a run's stamps onto one anchor destroys the sequencing the manifest just produced.
 * - **It cascades.** `splicewire:tower:install` → `:satellite:install` → `:beam:install` each publish their
 *   own block; one relative value shifts all three consistently, where one absolute anchor would stack
 *   three blocks on the same second.
 *
 * ## Never one file, and the crossing is reported
 *
 * "Re-stamping one file to move it" is a real incident in the publish-ordering convention: moving
 * beam-taxonomy's creates forward pushed them past tower's ALTERs still at their original stamps and a
 * fresh migrate died on a duplicate column. The documented repair is to regenerate the WHOLE chain. So
 * this class has no single-file affordance at all — the unit is the run — and {@see crossings()} names the
 * already-published files the moved block has just sorted past, which is the visibility that incident was
 * missing.
 *
 * ## Its relationship to `$order`, which it does not replace
 *
 * {@see BeamInstallManifest}'s `$order` governs **package** sequencing and is declared in code, once, for
 * every host (`laravel-beam` 0, accounts/tenancy 5, beam-ux 20, default 100, `tower` 200). `--travel=` is
 * **per-invocation and operator-declared**, and it moves the run's whole block relative to what the host
 * already had. They compose rather than compete: `$order` decides the order WITHIN the block, travel
 * decides where the block sits. A cross-package ALTER-before-CREATE bug is always `$order`'s to fix and
 * never travel's — `MigrationOrderingAudit` still says so.
 */
class MigrationTravel
{
    /** `<sign><n> <unit>` — the only accepted shape. Anything else is refused with its reason. */
    private const PATTERN = '/^\s*([+-])\s*(\d+)\s*(second|minute|hour|day|week|month|year)s?\s*$/i';

    /**
     * @param  string  $expression  a normalized `strtotime` modifier, e.g. `-1 year`
     */
    public function __construct(public string $expression) {}

    /**
     * Parse the `--travel=` option. Null for an absent or empty option (the overwhelmingly common case:
     * no travel, publish stamps stand). Throws on anything that is not a relative expression, naming why
     * rather than silently doing something adjacent.
     *
     * @throws InvalidArgumentException
     */
    public static function parse(?string $value): ?self
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        if (! preg_match(self::PATTERN, $value, $m)) {
            throw new InvalidArgumentException(sprintf(
                '--travel=%s is not a relative shift. Pass a signed amount and a unit — "-1 year", '.
                '"+2 days", "-6 months" (second|minute|hour|day|week|month|year). An absolute date is '.
                'deliberately refused: pinning a run\'s published files to a chosen stamp is the publish '.
                'band beam-facade ticket 22 rejected, and it would collapse the ordering the install '.
                'manifest just produced.',
                $value,
            ));
        }

        return new self(sprintf('%s%d %s', $m[1], (int) $m[2], strtolower($m[3])));
    }

    /**
     * Every timestamped migration currently under `$root`, as `absolute path => Y_m_d_His prefix`. Taken
     * before and after a publish pass; the difference is the run's block.
     *
     * Non-recursive on purpose, matching `Illuminate\Database\Migrations\Migrator` and
     * {@see MigrationFiles} — plus one level for the publish-destination subdirectories the estate
     * declares (`shared/`, `tenant/`), which are separate registered paths and publish separately.
     *
     * @return array<string, string>
     */
    public static function snapshot(string $root): array
    {
        $found = [];

        foreach ([$root.'/*_*.php', $root.'/*/*_*.php'] as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                if (preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6})_/', basename($file), $m)) {
                    $found[$file] = $m[1];
                }
            }
        }

        return $found;
    }

    /**
     * Apply the shift to everything published since `$before` was taken.
     *
     * VALIDATE-THEN-APPLY: every target name is computed and checked first, and a single collision with a
     * name already on disk aborts the whole pass untouched. A half-shifted block is worse than an
     * unshifted one — it is precisely the "moved relative to some files but not others" state the
     * convention's re-stamping incident describes.
     *
     * @param  array<string, string>  $before  a {@see snapshot()} taken before the publish pass
     * @return array{moved: array<string, string>, blocked: list<string>}
     *                                                                    `moved` is old absolute path => new absolute path; `blocked` names the collisions that
     *                                                                    stopped the pass (non-empty ⇒ nothing was moved)
     */
    public function shift(string $root, array $before): array
    {
        $after = self::snapshot($root);
        $published = array_diff_key($after, $before);

        if ($published === []) {
            return ['moved' => [], 'blocked' => []];
        }

        $delta = $this->delta(min($published));

        if ($delta === null || $delta === 0) {
            return ['moved' => [], 'blocked' => []];
        }

        $planned = [];
        $blocked = [];

        foreach ($published as $file => $prefix) {
            $target = $this->target($file, $prefix, $delta);

            if ($target === null || $target === $file) {
                continue;
            }

            if (isset($after[$target]) || file_exists($target) || in_array($target, $planned, true)) {
                $blocked[] = basename($target);

                continue;
            }

            $planned[$file] = $target;
        }

        if ($blocked !== []) {
            return ['moved' => [], 'blocked' => $blocked];
        }

        $moved = [];

        foreach ($planned as $file => $target) {
            if (@rename($file, $target)) {
                $moved[$file] = $target;
            }
        }

        return ['moved' => $moved, 'blocked' => []];
    }

    /**
     * The already-published migrations the moved block has just sorted past — the fact the publish-ordering
     * convention's re-stamping incident was missing. Empty when the block moved within a gap, which is the
     * safe case and the one worth being able to say out loud.
     *
     * Reported as a FILE fact only: whether a crossing matters depends on whether the crossed migration has
     * run, and this class never opens a database (a fresh host has none). The caller says so in prose.
     *
     * @param  array<string, string>  $before  the pre-publish snapshot
     * @param  array<string, string>  $moved  old path => new path, from {@see shift()}
     * @return list<string> basenames, sorted
     */
    public function crossings(array $before, array $moved): array
    {
        if ($moved === []) {
            return [];
        }

        $oldPrefixes = [];
        $newPrefixes = [];

        foreach ($moved as $old => $new) {
            $oldPrefixes[] = substr(basename($old), 0, 17);
            $newPrefixes[] = substr(basename($new), 0, 17);
        }

        $low = min(min($oldPrefixes), min($newPrefixes));
        $high = max(max($oldPrefixes), max($newPrefixes));

        $crossed = [];

        foreach ($before as $file => $prefix) {
            if (strcmp($prefix, $low) >= 0 && strcmp($prefix, $high) <= 0) {
                $crossed[] = basename($file);
            }
        }

        sort($crossed);

        return $crossed;
    }

    /**
     * The shift, resolved ONCE against the run's earliest stamp, as a count of seconds.
     *
     * Resolving the calendar expression once and applying the resulting delta to every file is what makes
     * spacing exact and order preservation total. Evaluating `-1 month` per file would be neither: PHP's
     * calendar arithmetic overflows (`2026-03-31 -1 month` is `2026-03-03`, February having no 31st), so
     * two files a second apart that straddle a day boundary can come out in the opposite order. That is a
     * narrow window, and a lever whose correctness depends on not landing in it is the wrong lever —
     * install order IS migration order, and this class exists to move a block without disturbing it.
     */
    private function delta(string $earliest): ?int
    {
        $at = \DateTimeImmutable::createFromFormat('Y_m_d_His', $earliest);

        if ($at === false) {
            return null;
        }

        $shifted = $at->modify($this->expression);

        if ($shifted === false) {
            return null;
        }

        return $shifted->getTimestamp() - $at->getTimestamp();
    }

    /** The shifted absolute path for one file, or null when its prefix is unreadable. */
    private function target(string $file, string $prefix, int $delta): ?string
    {
        $at = \DateTimeImmutable::createFromFormat('Y_m_d_His', $prefix);

        if ($at === false) {
            return null;
        }

        return dirname($file).DIRECTORY_SEPARATOR.
            date('Y_m_d_His', $at->getTimestamp() + $delta).
            substr(basename($file), 17);
    }
}
