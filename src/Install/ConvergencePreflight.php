<?php

namespace Splicewire\Beam\Install;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Rushing\SchemaConvergence\ConvergentTable;
use Throwable;

/**
 * Asks every pending convergent migration what it WOULD do, before `migrate` does any of it
 * (beam-facade ticket 84).
 *
 * ## The failure it exists to end
 *
 * The convergent guard is correct and this class does not touch it. The complaint is purely about WHEN
 * it speaks: from inside `migrate`, one table at a time, after earlier tables in the same run have
 * already changed. What an operator at `~/Herd/audiostud` actually got was three tables migrated, a
 * stack trace on the fourth, and no answer to any of *how many more were queued*, *is the host now
 * half-installed*, *is re-running safe*. There were ~30 pending. They learned about one.
 *
 * So the answer moves forward to the one position where an abort is free: {@see TableOwnershipResolver}'s
 * slot, between publish and migrate. That reasoning transfers verbatim from ticket 29 — the question's
 * options are the files publish just wrote, and the answer has to land before anything runs them. The
 * two passes ask different questions of the same population: ownership asks whose FILENAME wins, this
 * asks whether the declared SHAPE can land at all.
 *
 * ## How a migration is asked without running it
 *
 * A guard is constructed INSIDE the migration's own `up()`, so there is nothing for a caller to hold and
 * call `report()` on. `ConvergentTable::rehearse()` therefore puts every terminal into report mode and
 * the body is invoked for real — which makes the honesty of {@see rehearsable()} load-bearing, not
 * defensive: rehearsal neutralises convergent guards and NOTHING ELSE, so a `DB::statement()` sitting
 * beside one would execute. A migration this class cannot prove is a pure convergent declaration is
 * skipped and SAID, never quietly run and never quietly dropped.
 *
 * Two mechanics worth knowing before changing this:
 *
 * - **The file is copied to a temp path before it is required.** PHP's `require` returns `true` rather
 *   than the migration object on a second include of the same path, and `migrate` runs in THIS process a
 *   few lines later through the same `Migrator::resolvePath()` — so requiring the real file here would
 *   hand the migrator a boolean and break the install this pass exists to protect. Migrations are
 *   anonymous classes with no `__DIR__` dependency, so a copy resolves identically.
 * - **`migrate --pretend` is not an alternative.** Pretending makes `select()` return `[]`, so every
 *   `getColumns()` the comparison depends on answers "no columns" and the guard would report a clean
 *   convergence against a table it cannot see.
 *
 * ## What it is deliberately blind to
 *
 * The central pass only. {@see MigrationFiles} enumerates exactly the paths the next `migrate` will
 * read, so a `tenant/` directory only stancl knows about is not rehearsed — reporting on a pass this
 * command does not make would be worse than silence. And already-run migrations are excluded: a guard
 * that will not speak again cannot conflict again.
 */
final class ConvergencePreflight
{
    /**
     * @param  list<string>  $paths  every migration path the next `migrate` will read
     * @param  list<string>|null  $ran  migration names already in the ledger; null ⇒ unknown, treat all as pending
     */
    public function __construct(private array $paths, private ?array $ran) {}

    public static function forApplication(Application $app): self
    {
        return new self(MigrationFiles::pathsFor($app), self::ledger());
    }

    /**
     * Every pending convergent migration, rehearsed or explained.
     *
     * @return list<RehearsedMigration>
     */
    public function rehearse(): array
    {
        $found = [];

        foreach (MigrationFiles::in($this->paths) as [$prefix, $stem, $file]) {
            $migration = $prefix.'_'.$stem;

            if ($this->ran !== null && in_array($migration, $this->ran, true)) {
                continue;
            }

            $source = (string) @file_get_contents($file);

            if (! RehearsalSafety::isConvergent($source)) {
                continue;
            }

            $found[] = $this->rehearseOne($migration, $file, $source);
        }

        return $found;
    }

    private function rehearseOne(string $migration, string $file, string $source): RehearsedMigration
    {
        $unsafe = $this->unrehearsableBecause($source);

        if ($unsafe !== null) {
            return RehearsedMigration::skip($migration, $file, $unsafe);
        }

        $copy = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beam-preflight-'.bin2hex(random_bytes(8)).'.php';

        if (! @copy($file, $copy)) {
            return RehearsedMigration::skip($migration, $file, 'could not be copied to a temp path to be read');
        }

        try {
            $instance = require $copy;

            if (! $instance instanceof Migration) {
                return RehearsedMigration::skip($migration, $file, 'does not return a Migration instance');
            }

            return new RehearsedMigration(
                $migration,
                $file,
                ConvergentTable::rehearse(fn () => $instance->up()),
            );
        } catch (Throwable $e) {
            // A body that cannot even be rehearsed is a finding, not a crash: this pass runs BEFORE the
            // install commits to anything, and a fresh host that cannot reach its database must still
            // install. Same posture the ownership pass takes one step earlier.
            return RehearsedMigration::skip($migration, $file, 'could not be rehearsed: '.$e->getMessage());
        } finally {
            @unlink($copy);
        }
    }

    /**
     * Why this file must not be rehearsed, or null when it is a pure convergent declaration.
     *
     * The predicate itself lives in {@see RehearsalSafety} — extracted at beam-facade ticket 109, when a
     * second instrument (the standing doctor report) needed the identical question answered about the
     * identical population. Its docblock carries the whole argument for why the scan is source text,
     * why it errs safe, and why `down()` is excised.
     */
    private function unrehearsableBecause(string $source): ?string
    {
        return RehearsalSafety::explain($source);
    }

    /**
     * The migration names already run, or null when that cannot be known — a fresh host with no ledger
     * yet, or a database this command cannot reach. Null means "treat everything as pending", which
     * over-reports rather than skipping a migration that is about to run.
     *
     * @return list<string>|null
     */
    private static function ledger(): ?array
    {
        try {
            $table = config('database.migrations', 'migrations');
            $table = is_array($table) ? ($table['table'] ?? 'migrations') : $table;

            if (! Schema::hasTable($table)) {
                return null;
            }

            return DB::table($table)->pluck('migration')->all();
        } catch (Throwable) {
            return null;
        }
    }
}
