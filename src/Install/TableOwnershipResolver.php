<?php

namespace Splicewire\Beam\Install;

use Illuminate\Contracts\Foundation\Application;
use Rushing\SchemaConvergence\ConvergentTable;
use Splicewire\Beam\Doctor\MigrationOrderingAudit;

/**
 * Finds the published beam migrations that lose a filename race to a third-party migration of the same
 * name, and — on a declared answer — re-dates ours so it wins.
 *
 * WHY THIS EXISTS AND WHY IT IS NOT A PUBLISH BAND (beam-facade tickets 22 + 29). A convergent guard
 * ({@see ConvergentTable}) dissolves every collision *inside* the family: both
 * sides top each other up and order stops mattering. It cannot reach a package whose guard we do not
 * own. `lunarphp/core` ships `activity_log` at a FIXED `2026_01_01_900001` behind a bare
 * `Schema::hasTable() → return`, creating bigint `nullableMorphs` where beam needs strings to hold a
 * tenant slug. Beam first ⇒ green (Lunar's bare guard skips harmlessly). Lunar first ⇒ beam's
 * convergent guard finds `subject_id` present-but-bigint, cannot converge, and throws. So filename
 * order is the only lever left, and 22 rejected making it a global publish band — invisible magic that
 * would also outrank a host's own deliberate migration. This is the same lever, DECLARED: the operator
 * says beam owns the table and the installer acts, so the fix is in the install rather than in a hand
 * edit one host has and the next clone does not.
 *
 * THE UNIT IS THE FILENAME STEM, not the table. A table name is routinely dynamic
 * (`Beam::table('particles')`, `config('activitylog.table_name')`) and unresolvable without booting the
 * declaring package — the blindness {@see MigrationOrderingAudit} refuses to
 * guess around. A stem is a literal on disk, and two files named `create_media_table` are two packages
 * that both believe they create it.
 *
 * SCOPE MATCHES THE CONVENTION. "Ours" is only ever a published file under `database/migrations/**`;
 * "theirs" is only ever a path a package registered from its own source. Two migrations a HOST wrote
 * itself are both "ours", collide with nothing here, and stay the host's own bug — beam has no standing
 * to arbitrate between two of an app's migrations
 * (`rushing/laravel-schema-convergence/docs/agents/convergent-migration-guards.convention.md`, "Scope").
 */
class TableOwnershipResolver
{
    /**
     * @param  list<string>  $paths  every registered migration path (ours and theirs, unfiltered)
     * @param  string  $ours  the directory tree whose files we may re-date — `database_path()`
     */
    public function __construct(private array $paths, private string $ours) {}

    public static function forApplication(Application $app): self
    {
        return new self(MigrationFiles::pathsFor($app), $app->databasePath());
    }

    /**
     * Every stem where a published beam migration and a third-party one both exist, whether or not we
     * currently win — a collision we already win is still worth reporting, because "already wins" is
     * the state a re-run of the installer has to be able to recognise (this pass is idempotent by
     * construction: claiming a stem twice is a no-op the second time).
     *
     * @return list<MigrationCollision>
     */
    public function collisions(): array
    {
        [$ours, $theirs] = $this->index();

        $collisions = [];

        foreach ($ours as $stem => $files) {
            if (! isset($theirs[$stem])) {
                continue;
            }

            // The earliest competitor is the one that has to be beaten; beating it beats the rest.
            $theirPrefixes = array_keys($theirs[$stem]);
            sort($theirPrefixes);

            $ourPrefix = min(array_keys($files));

            $collisions[] = new MigrationCollision(
                stem: $stem,
                ourFile: $files[$ourPrefix],
                ourPrefix: $ourPrefix,
                theirFiles: array_values($theirs[$stem]),
                theirPrefix: $theirPrefixes[0],
            );
        }

        usort($collisions, static fn (MigrationCollision $a, MigrationCollision $b): int => strcmp($a->stem, $b->stem));

        return $collisions;
    }

    /**
     * Re-date beam's copy so it sorts ahead of the earliest competitor, returning the new absolute path
     * (or null when nothing was done — we already win, or no winning prefix is expressible).
     *
     * MINIMAL DISPLACEMENT, deliberately: the new prefix is one tick BELOW the competitor's, never a
     * `0001_01_01_*` band. `migration-publish-ordering.convention.md` records a real incident where
     * re-stamping one file moved it relative to *every* other published file; a band maximises that blast
     * radius and one tick minimises it. The fallback to `0001_01_01_000000` fires only when the
     * competitor's own prefix cannot be decremented, which no observed vendor stamp needs.
     */
    public function claim(MigrationCollision $collision): ?string
    {
        $prefix = $this->winningPrefix($collision);

        if ($prefix === null) {
            return null;
        }

        $target = dirname($collision->ourFile).DIRECTORY_SEPARATOR.$prefix.'_'.$collision->stem.'.php';

        if (! @rename($collision->ourFile, $target)) {
            return null;
        }

        return $target;
    }

    /**
     * The prefix beam's copy would take to win, or null when it already wins / no winning prefix is
     * expressible. Public so a caller can weigh {@see dependencyRisks()} against the move BEFORE the
     * file is renamed out from under it.
     */
    public function winningPrefix(MigrationCollision $collision): ?string
    {
        return $collision->beamWins() ? null : $this->precede($collision->theirPrefix);
    }

    /**
     * Migrations this file's create depends on that would now run AFTER it — the trap
     * `migration-publish-ordering.convention.md` records under "re-stamping one file to move it".
     * Moving a CREATE earlier is safe for every ALTER against that table (they stay after it) and unsafe
     * only where the create itself references a table created later.
     *
     * Literal names only, on `MigrationOrderingAudit`'s posture: a dynamically-named FK target is
     * skipped rather than guessed at, so this under-reports instead of misleading. It warns and never
     * blocks — the operator declared the ownership, and a failed migrate is the loud failure this whole
     * collision family exists to convert silence into.
     *
     * @return list<string> human-readable warnings, empty when nothing is at risk
     */
    public function dependencyRisks(MigrationCollision $collision, string $newPrefix): array
    {
        $source = (string) @file_get_contents($collision->ourFile);

        preg_match_all("/->constrained\(\s*'([a-z0-9_]+)'/i", $source, $constrained);
        preg_match_all("/->on\(\s*'([a-z0-9_]+)'/i", $source, $on);

        $targets = array_unique(array_merge($constrained[1], $on[1]));

        if ($targets === []) {
            return [];
        }

        $creators = $this->literalCreators();
        $risks = [];

        foreach ($targets as $table) {
            $creator = $creators[$table] ?? null;

            if ($creator === null || $creator[0] < $newPrefix) {
                continue;
            }

            $risks[] = sprintf(
                '`%s` references `%s`, which `%s` creates at %s — after the claimed position %s.',
                $collision->stem,
                $table,
                $creator[1],
                $creator[0],
                $newPrefix,
            );
        }

        return $risks;
    }

    /**
     * table ⇒ [prefix, migration name] for every LITERAL `Schema::create('x')` across all paths.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function literalCreators(): array
    {
        $creators = [];

        foreach ($this->files() as [$prefix, $stem, $file]) {
            preg_match_all(
                "/Schema::(?:connection\([^)]*\)->)?create\(\s*'([a-z0-9_]+)'/i",
                (string) @file_get_contents($file),
                $matches,
            );

            foreach ($matches[1] as $table) {
                // First claim wins, matching MigrationOrderingAudit — a later duplicate is the
                // collision this class is about, not an additional creator.
                $creators[$table] ??= [$prefix, $prefix.'_'.$stem];
            }
        }

        return $creators;
    }

    /**
     * Split every migration file into ours (published, re-datable) and theirs (package source), keyed
     * stem ⇒ prefix ⇒ path.
     *
     * @return array{0: array<string, array<string, string>>, 1: array<string, array<string, string>>}
     */
    private function index(): array
    {
        $ours = [];
        $theirs = [];
        $root = $this->realpath($this->ours);

        foreach ($this->files() as [$prefix, $stem, $file]) {
            $bucket = str_starts_with($this->realpath($file), $root) ? 'ours' : 'theirs';

            if ($bucket === 'ours') {
                $ours[$stem][$prefix] = $file;
            } else {
                $theirs[$stem][$prefix] = $file;
            }
        }

        return [$ours, $theirs];
    }

    /**
     * Every migration file across every registered path, as [prefix, stem, absolute path].
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function files(): array
    {
        return MigrationFiles::in($this->paths);
    }

    /** The sortable prefix one tick below $prefix, or null when the date part itself would have to move. */
    private function precede(string $prefix): ?string
    {
        if (! preg_match('/^(\d{4}_\d{2}_\d{2})_(\d+)$/', $prefix, $m)) {
            return null;
        }

        $tick = (int) $m[2];

        if ($tick <= 0) {
            // Nothing below midnight on that date without moving the date; the floor prefix is the only
            // honest answer, and it is what a host hand-editing this file would have reached for.
            return $prefix === '0001_01_01_000000' ? null : '0001_01_01_000000';
        }

        return $m[1].'_'.str_pad((string) ($tick - 1), strlen($m[2]), '0', STR_PAD_LEFT);
    }

    private function realpath(string $path): string
    {
        return realpath($path) ?: $path;
    }
}
