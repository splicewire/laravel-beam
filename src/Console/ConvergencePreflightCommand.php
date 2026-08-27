<?php

namespace Splicewire\Beam\Console;

use Illuminate\Console\Command;
use Rushing\SchemaConvergence\ConvergenceReport;
use Splicewire\Beam\Install\ConvergencePreflight;
use Splicewire\Beam\Install\MigrationRehearsal;
use Splicewire\Beam\Install\PackageStubs;
use Splicewire\Beam\Install\RehearsalSafety;
use Splicewire\Beam\Install\RehearsedMigration;

/**
 * `splicewire:beam:convergence-preflight` — **the read-only entry point to the convergence preflight**
 * (beam-facade ticket 146).
 *
 * ## The defect it closes
 *
 * `ConvergencePreflight` existed only as a *phase* of `splicewire:beam:install`, which publishes and
 * migrates. There was no `--dry-run`, no report-only flag and no standalone command, so the question
 * *"what would collide if I published?"* could be asked only by an operator **willing to publish** — or
 * by calling the class from `tinker`, which is not an instrument anyone runs.
 *
 * That is the estate's recurring shape one more time: the check that most wants running is the one
 * structurally least able to run. Here it was not dishonest, merely unreachable.
 *
 * ## Two populations, and the second one is the whole reason this is worth a command
 *
 * **Pending** — every convergent migration the next `migrate` would read and has not run yet. This is
 * what the install phase rehearses, and it is what an operator about to migrate cares about.
 *
 * **Unpublished package stubs** — every `.php.stub` an installed package ships that this host has never
 * published. `MigrationFiles::pathsFor()` cannot see these by construction, so no instrument tied to the
 * migrator can. beam-facade [108]'s entire evidence base — nine columns across three templates at
 * `~/Herd/audiostud` — lives here, and was previously obtainable only by hand-rehearsing stubs outside
 * any command. **A ruling that rests on evidence no command can produce is a ruling the next session
 * re-derives from scratch**, which is why both populations are shown by default and `--pending-only`
 * is the opt-out rather than the reverse.
 *
 * ## Nothing here is rebuilt
 *
 * `MigrationRehearsal` (ticket 182) rehearses one migration body, published copy or `.php.stub`;
 * `PackageStubs::forHost()` resolves the unpublished population from composer's own install record;
 * `RehearsalSafety` decides what can be rehearsed at all. This command is **a wrapper and a renderer**
 * over those three, deliberately: a second rehearsal implementation is the mistake ticket 109 already
 * refused once. If a number here looks wrong, the bug is in one of those classes, not in this file.
 *
 * ## It reports; it does not gate — and the flag is the whole nuance
 *
 * Every answer here is a fact about the *host* (does this table exist, does it hold rows, what shape is
 * it in), which under `rushing/laravel-doctor/docs/agents/gate-or-advisory.convention.md` is an advisory
 * finding and never a fatal. So the default exit code is **0 even when it finds conflicts** — the same
 * conflict at another host is not a defect, and a command whose exit code moves with the database it
 * happened to reach is exactly what that convention forbids in a gate.
 *
 * `--fail-on-conflict` is offered for the one legitimate case: an operator or a pipeline that has
 * *chosen* this host as the subject and wants the answer as an exit status. Opt-in, so the enforcement
 * belongs to the caller who knows what they pointed it at, rather than to the command.
 */
class ConvergencePreflightCommand extends Command
{
    protected $signature = 'splicewire:beam:convergence-preflight
        {--pending-only : Only the migrations the next `migrate` would read; skip the unpublished package stubs}
        {--stubs-only : Only the unpublished package stubs; skip the pending set}
        {--fail-on-conflict : Exit non-zero when a conflict is found (opt-in; the default is advisory)}
        {--json : Emit machine-readable output}';

    protected $description = 'Report what the convergent guards would do — read-only. Rehearses pending migrations AND unpublished package stubs; writes nothing.';

    public function handle(): int
    {
        $pending = $this->option('stubs-only') ? [] : ConvergencePreflight::forApplication($this->laravel)->rehearse();
        $stubs = $this->option('pending-only') ? [] : $this->rehearseUnpublishedStubs();

        if ($this->option('json')) {
            return $this->json($pending, $stubs);
        }

        $this->line('');
        $this->line('<options=bold>splicewire:beam:convergence-preflight</> <fg=gray>(read-only — nothing has been written)</>');

        if (! $this->option('stubs-only')) {
            $this->render('Pending migrations', $pending, 'Nothing pending is convergent — the next migrate has no guard to run.');
        }

        if (! $this->option('pending-only')) {
            $this->render(
                'Unpublished package stubs',
                $stubs,
                'Every convergent stub an installed package ships is already published here.',
            );
        }

        $this->line('');

        return $this->exitCode(array_merge($pending, $stubs));
    }

    /**
     * The population no migrator-tied instrument can see: a package's `.php.stub` this host has never
     * published. Rehearsed through the same `MigrationRehearsal` the pending set uses — an unpublished
     * stub resolves exactly as its published copy would, because a migration is an anonymous class with
     * no `__DIR__` dependency (182's finding, and the whole reason this population is reachable at all).
     *
     * @return list<RehearsedMigration>
     */
    private function rehearseUnpublishedStubs(): array
    {
        $found = [];

        foreach (PackageStubs::forHost($this->laravel->basePath()) as $stub) {
            $source = (string) @file_get_contents($stub['file']);

            if (! RehearsalSafety::isConvergent($source)) {
                continue;
            }

            $found[] = MigrationRehearsal::of($stub['stem'], $stub['file'], $source);
        }

        return $found;
    }

    /**
     * The three outcomes plus the `?` lines, in the same vocabulary the install phase already renders —
     * *"a preflight that printed only what it managed to rehearse would read as everything-is-clean to
     * an operator whose one risky migration is the one it silently skipped"*
     * ({@see RehearsedMigration}). **The skipped count is never suppressed, including when it is the
     * only thing to report.**
     *
     * @param  list<RehearsedMigration>  $found
     */
    private function render(string $heading, array $found, string $empty): void
    {
        $this->line('');
        $this->line("  <options=bold>{$heading}</>");

        if ($found === []) {
            $this->line("    <fg=gray>{$empty}</>");

            return;
        }

        [$conflicted, $changing, $skipped, $clean] = $this->partition($found);

        $this->line(sprintf(
            '    %d convergent migration%s: <fg=green>%d clean</>, <fg=yellow>%d would change the schema</>, <fg=red>%d conflicted</>%s.',
            count($found),
            count($found) === 1 ? '' : 's',
            count($clean),
            count($changing),
            count($conflicted),
            $skipped === [] ? '' : sprintf(', <fg=gray>%d not rehearsed</>', count($skipped)),
        ));

        foreach ($conflicted as $migration) {
            $this->line("    <fg=red>✗</> <options=bold>{$migration->migration}</>");
            foreach ($migration->conflicts() as $conflict) {
                $this->line("        <fg=red>[{$conflict->kind}]</> {$conflict->detail}");

                // The repair is printed in full, deliberately: the install phase does the same, and the
                // whole point of a read-only entry point is that an operator can act on what it says
                // WITHOUT having run the mutating command that would otherwise have told them.
                if ($conflict->repair !== '') {
                    $this->line('        repair: '.str_replace("\n", "\n        ", $conflict->repair));
                }
            }
        }

        foreach ($changing as $migration) {
            $this->line("    <fg=yellow>+</> <options=bold>{$migration->migration}</>");
            foreach ($migration->changes() as $report) {
                $this->line('        '.$report->summary());
            }
        }

        foreach ($skipped as $migration) {
            $this->line("    <fg=gray>?</> {$migration->migration} — {$migration->skipped}");
        }
    }

    /** @param  list<RehearsedMigration>  $found */
    private function json(array $found, array $stubs): int
    {
        $shape = fn (RehearsedMigration $m) => [
            'migration' => $m->migration,
            'file' => $m->file,
            'rehearsed' => $m->wasRehearsed(),
            'skipped' => $m->skipped,
            'conflicts' => array_map(fn ($c) => [
                'kind' => $c->kind,
                'table' => $c->table,
                'column' => $c->column,
                'detail' => $c->detail,
                'repair' => $c->repair,
            ], $m->conflicts()),
            'changes' => array_map(fn (ConvergenceReport $r) => $r->summary(), $m->changes()),
        ];

        $this->line((string) json_encode([
            'command' => 'convergence-preflight',
            'read_only' => true,
            'pending' => array_map($shape, $found),
            'unpublished_stubs' => array_map($shape, $stubs),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $this->exitCode(array_merge($found, $stubs));
    }

    /**
     * @param  list<RehearsedMigration>  $found
     * @return array{0: list<RehearsedMigration>, 1: list<RehearsedMigration>, 2: list<RehearsedMigration>, 3: list<RehearsedMigration>}
     */
    private function partition(array $found): array
    {
        $conflicted = array_values(array_filter($found, fn (RehearsedMigration $m) => $m->hasConflicts()));
        $skipped = array_values(array_filter($found, fn (RehearsedMigration $m) => ! $m->wasRehearsed()));
        $changing = array_values(array_filter($found, fn (RehearsedMigration $m) => $m->changes() !== [] && ! $m->hasConflicts()));
        $clean = array_values(array_filter(
            $found,
            fn (RehearsedMigration $m) => $m->wasRehearsed() && ! $m->hasConflicts() && $m->changes() === [],
        ));

        return [$conflicted, $changing, $skipped, $clean];
    }

    /** @param  list<RehearsedMigration>  $found */
    private function exitCode(array $found): int
    {
        if (! $this->option('fail-on-conflict')) {
            return self::SUCCESS;
        }

        $conflicted = array_filter($found, fn (RehearsedMigration $m) => $m->hasConflicts());

        return $conflicted === [] ? self::SUCCESS : self::FAILURE;
    }
}
