<?php

namespace Splicewire\Beam\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Rushing\SchemaConvergence\ConvergentTable;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Install\BeamInstallManifest;
use Splicewire\Beam\Install\ConvergencePreflight;
use Splicewire\Beam\Install\InstallStep;
use Splicewire\Beam\Install\MigrationCollision;
use Splicewire\Beam\Install\MigrationTravel;
use Splicewire\Beam\Install\RehearsedMigration;
use Splicewire\Beam\Install\TableOwnershipResolver;
use Splicewire\Beam\OpenApi\ConfiguredArtifactSpecSource;
use Splicewire\Beam\Seed\BeamSeedManifest;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * `splicewire:beam:install` — the ONE command that stands up the whole beam stack.
 *
 * Phase 1 (beam-write-pipeline ticket 08) made it manifest-driven: it iterates the
 * {@see BeamInstallManifest} core-first, runs each registered package's `vendor:publish` tags, then
 * migrates once — a beam-* package joins by registering its own {@see InstallStep} from its own provider,
 * so this command NEVER names a consumer.
 *
 * Phase 2 (beam-particle-rename ticket 10) wraps that with an interactive front door: a laravel/prompts
 * wizard that asks the config questions the Phase-1 seams now expose (the `beam_` table prefix, tenancy
 * mode, the schema-source order, which optional modules to install), applies them, then chains the same
 * manifest steps. A retrofit host completes setup from this one command — e.g. answer prefix `''` +
 * schema-sources `file` and beam provisions no `beam_schemas` table, dropping cleanly into a host that
 * already owns its tables. Every answer is also exposed as an option, so the wizard is fully scripted for
 * non-interactive runs (CI, tests) — no prompt fires when `--no-interaction` is set.
 *
 * Phase 3 (beam-facade ticket 29) adds the one answer here that ACTS instead of verifying: **table
 * ownership**. Between publish and migrate the command finds every published beam migration racing a
 * third-party one of the same filename, asks who owns each (default beam, `--own-tables` to script it),
 * and re-dates beam's copy to run first. It sits there and nowhere else because the question's options
 * are the files publish just wrote, and the answer has to land before anything runs them.
 * {@see TableOwnershipResolver} for why filename order is the only lever
 * against a package whose guard we do not own.
 *
 * Phase 4 (beam-facade ticket 84) puts a second question in that same slot, and the slot now holds both
 * halves of the collision family: ownership asks whose FILENAME wins, {@see ConvergencePreflight} asks
 * whether each declared SHAPE can land. It is there for the identical reason and it is the one pass that
 * can STOP the install — a shape conflict met from inside `migrate` is met three tables in, with the
 * rest of the queue unasked and the host half-written, so the answer moves to the last moment an abort
 * is free.
 *
 * Phase 5 (beam-facade ticket 117) adds a THIRD occupant of that same slot, and it is the only one that
 * is purely operator-declared: `--travel=` ({@see MigrationTravel}) shifts the block this run publishes,
 * so a stub can land somewhere other than the end of the host's set. It runs FIRST of the three —
 * ownership spends one tick against a competitor's stamp, and a shift applied afterwards would move the
 * claimed file straight back off it.
 */
class BeamInstallCommand extends Command
{
    protected $signature = 'splicewire:beam:install
        {--force : Overwrite any already-published files}
        {--prefix= : Beam table prefix (config beam.core.table_prefix); pass an empty string for no prefix}
        {--schema-sources= : Comma list of schema sources in read/write order, e.g. "db,file" or "file"}
        {--tenancy= : "single" (one database) or "multi" (tenant-scoped)}
        {--modules= : Comma list of optional beam modules to install (name substrings); empty ⇒ core only}
        {--own-tables= : Comma list of colliding migration stems beam should own (run first); empty ⇒ own none. Default: all}
        {--travel= : Shift every migration THIS RUN publishes by a relative amount ("-1 year", "+2 days"), keeping their order. Governs fresh/reset databases only; absolute dates are refused}
        {--skip-preflight : Skip the convergence preflight and let migrate meet any shape conflict one table at a time}
        {--no-seed : Skip the package-registered seed pass (splicewire:beam:seed) at the end of the install}';

    protected $description = 'Interactively configure + install the whole beam stack (core-first) from the self-registration manifest.';

    public function handle(BeamInstallManifest $manifest): int
    {
        // Parse `--travel=` FIRST, before anything is published. A malformed value has to cost a re-read
        // of the flag, not a half-published host waiting on a rerun.
        try {
            $travel = MigrationTravel::parse($this->option('travel'));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $steps = $manifest->steps();

        if ($steps === []) {
            $this->warn('splicewire:beam:install — nothing registered in the manifest.');

            return self::SUCCESS;
        }

        $interactive = $this->input->isInteractive();

        if ($interactive) {
            intro('splicewire:beam:install');
            note($this->banner());
        }

        // 1. Gather the answers the Phase-1 seams expose. Each is option-first (scriptable), then a prompt
        //    when interactive, then null ⇒ leave config as-is — so a non-interactive run with no options is
        //    a pure Phase-1 install against today's config (the existing behaviour, unchanged).
        $prefix = $this->answer('prefix', $interactive, fn () => text(
            label: 'Beam table prefix',
            default: (string) config('beam.core.table_prefix', 'beam_'),
            hint: 'Empty = no prefix (retrofit into a host that owns its own tables).',
        ));

        $tenancy = $this->answer('tenancy', $interactive, fn () => select(
            label: 'Tenancy mode',
            options: ['single' => 'Single database', 'multi' => 'Multi-tenant (tenant-scoped)'],
            default: 'single',
        ));

        $sources = $this->answer('schema-sources', $interactive, fn () => select(
            label: 'Schema source order (first is written to)',
            options: [
                'db,file' => 'Database, then filesystem fallback',
                'file' => 'Filesystem only (provisions no beam_schemas table)',
                'db' => 'Database only',
            ],
            default: 'db,file',
        ));

        // 2. Apply the answers to runtime config BEFORE any publish/migrate, so this same run's migrations
        //    (which resolve every table through Beam::table()) land under the chosen prefix, and a
        //    file-only schema store provisions no beam_schemas.
        if ($prefix !== null) {
            config(['beam.core.table_prefix' => $prefix]);
        }
        if ($sources !== null) {
            config(['beam.core.schema.sources' => $this->parseList($sources)]);
        }
        if ($tenancy !== null) {
            config(['beam.core.tenancy' => $tenancy]);
        }

        // 3. Which optional modules? Core (order 0) is always installed; the rest are selectable.
        [$core, $optional] = $this->partitionSteps($steps);
        $selected = $this->selectModules($optional, $interactive);
        $runSteps = array_merge($core, $selected);

        // 4. Run the chained install (Phase-1 mechanics), then persist the answered config so the choices
        //    survive the next boot. Publish and migrate are SEPARATE calls with the table-ownership
        //    answer between them: the question's options are the collisions on disk, so it cannot be
        //    asked until the files exist, and it has to be answered before anything runs them.
        //    `--travel=` sits between publish and ownership, and that order is load-bearing: ownership
        //    re-dates one file to ONE TICK below a third-party competitor, so a travel shift running
        //    afterwards would move the claimed file straight back off its winning stamp. Travel places
        //    the block; ownership then spends its tick against whatever the block now looks like.
        $before = $travel === null ? [] : MigrationTravel::snapshot($this->laravel->databasePath('migrations'));
        $this->publishSteps($runSteps);
        $this->applyTravel($travel, $before);
        $this->resolveTableOwnership($interactive);

        if ($this->stepsMigrate($runSteps)) {
            // 4a. Ask every pending convergent stub what it WOULD do, while an abort is still free.
            //     Nothing has been written to the database at this point, so a conflict costs the
            //     operator a re-run and not a half-installed host (beam-facade ticket 84).
            if (! $this->preflightConvergence($interactive)) {
                return self::FAILURE;
            }

            $this->call('migrate', $this->option('force') ? ['--force' => true] : []);
        }

        $this->persistConfig($prefix, $sources, $tenancy);

        // 4b. Generate the OpenAPI artifact, so a fresh host serves its OWN spec at
        //     beam/openapi.{yaml,json} on first boot rather than 404ing until someone remembers to run
        //     the generator. Publishing beam's scribe stub happened above; this is the step that turns it
        //     into bytes.
        $this->generateOpenApiArtifact();

        // 4c. Run the package-registered seed pass, so a fresh host boots with the data its packages
        //     need to serve anything at all — beam-ux's `site` realm root above all (ADR-0209 §9: the
        //     public renderer never writes, so its absence means "nothing to serve", and until now
        //     nothing created it). Each seeder is config-gated, and a per-seeder failure still does not
        //     ABORT the install — but it no longer passes unnoticed either. This comment used to end
        //     "so this cannot fail an install", and that was the sentence three real key-type defects
        //     hid behind (beam-facade 143): a seeder failed on every run at three hosts, inside an
        //     install that printed "beam stack installed." and exited 0.
        $this->runSeeders();

        // 5. Verify the three fresh-host provisioning traps (beam-install-turnkey). Each fix lands as a
        //    package DEFAULT (so a host that never runs this command is still safe); the command's job here
        //    is to VERIFY the default took effect + REPORT it, and to nudge the host on the one thing it must
        //    author itself (a nav.yml). Never fatal — a warning, not a failure.
        $this->verifyProvisioning($tenancy);

        // 6. Frontend-surface provisioning (pnpm trap): a pnpm host must pin the unpublished
        //    @splicewire/@schemastud transitive JS deps to their local file: paths (npm resolves them
        //    leniently; pnpm 404s). Delegated to laravel-beam-ux's `splicewire:beam:ux:pnpm-overrides`
        //    when present — idempotent + a no-op on npm/yarn hosts, so it's always safe to run.
        $this->ensurePnpmOverrides();

        if ($this->seedingFailed) {
            // Reported at the END, not where it happened: the seed pass runs mid-install and its warnings
            // scroll away behind publishing, migrating and spec generation.
            $this->error('beam stack installed, but SEEDING REPORTED FAILURES — re-run `splicewire:beam:seed` '
                .'to see them. The host is installed; its package-owned data is incomplete.');

            return self::FAILURE;
        }

        if ($interactive) {
            outro('beam stack installed.');
        } else {
            $this->info('beam stack installed.');
        }

        return self::SUCCESS;
    }

    /** Whether the mid-install seed pass reported any failure; surfaced at the end, never swallowed. */
    private bool $seedingFailed = false;

    /**
     * Resolve one answer: the option value if given (scriptable), else the prompt when interactive, else
     * null (⇒ leave config as-is). An option is honoured even when empty (`--prefix=` means "no prefix").
     */
    private function answer(string $option, bool $interactive, callable $prompt): ?string
    {
        $value = $this->option($option);

        if ($value !== null) {
            return $value;
        }

        return $interactive ? (string) $prompt() : null;
    }

    /**
     * Split the manifest into the always-run core steps (order 0) and the operator-selectable rest.
     *
     * @param  list<InstallStep>  $steps
     * @return array{0: list<InstallStep>, 1: list<InstallStep>}
     */
    private function partitionSteps(array $steps): array
    {
        $core = array_values(array_filter($steps, static fn (InstallStep $s): bool => $s->order === 0));
        $optional = array_values(array_filter($steps, static fn (InstallStep $s): bool => $s->order !== 0));

        return [$core, $optional];
    }

    /**
     * Which optional modules to install. `--modules` (even empty) is authoritative; else a multiselect
     * when interactive; else all of them (the unchanged non-interactive full-stack install).
     *
     * @param  list<InstallStep>  $optional
     * @return list<InstallStep>
     */
    private function selectModules(array $optional, bool $interactive): array
    {
        if ($optional === []) {
            return [];
        }

        $option = $this->option('modules');

        if ($option !== null) {
            $wanted = $this->parseList($option);

            return array_values(array_filter(
                $optional,
                fn (InstallStep $s): bool => $this->matchesAny($s->package, $wanted),
            ));
        }

        if (! $interactive) {
            return $optional;
        }

        $chosen = multiselect(
            label: 'Which optional beam modules?',
            options: array_map(static fn (InstallStep $s): string => $s->package, $optional),
            default: array_map(static fn (InstallStep $s): string => $s->package, $optional),
            hint: 'Space to toggle. Core is always installed.',
        );

        return array_values(array_filter($optional, static fn (InstallStep $s): bool => in_array($s->package, $chosen, true)));
    }

    /** @param  list<string>  $needles */
    private function matchesAny(string $package, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($package, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The Phase-1 publish mechanics, over the chosen steps. Migrating is the caller's separate step —
     * see the ownership pass in {@see handle()} for why the two cannot be one call any more.
     *
     * @param  list<InstallStep>  $steps
     */
    private function publishSteps(array $steps): void
    {
        $force = (bool) $this->option('force');

        foreach ($steps as $step) {
            $this->line("splicewire:beam:install → {$step->package}");

            if ($step->note !== null) {
                $this->line("  ↳ {$step->note}");
            }

            foreach ($step->publishTags as $tag) {
                $this->callSilent('vendor:publish', array_merge(
                    ['--tag' => $tag],
                    $force ? ['--force' => true] : [],
                ));
            }
        }
    }

    /**
     * `--travel=`: place the block this run just published (beam-facade ticket 117).
     *
     * The mechanics and the whole argument live in {@see MigrationTravel}; this method is the reporting
     * half, and it reports three things because each answers an objection the ticket inherited:
     *
     * - **what moved, as a range** — never one filename, because the convention's re-stamping incident is
     *   exactly a one-file move that nobody could see the blast radius of;
     * - **what the block crossed** — the already-published migrations it now sorts past, which is the
     *   visibility that incident lacked. A crossing is only a hazard if the crossed file has not run, and
     *   this says so rather than querying a database a fresh host does not have;
     * - **that this governs the NEXT fresh migrate**, in the same words `carryMigrationRecord()` uses,
     *   because filename order sorts pending files and can never reorder what already ran.
     *
     * Never fatal. A blocked pass leaves every file exactly where publish put it, which is a working
     * install with an unspent lever.
     *
     * @param  array<string, string>  $before
     */
    private function applyTravel(?MigrationTravel $travel, array $before): void
    {
        if ($travel === null) {
            return;
        }

        $this->line('splicewire:beam:install → travel');

        $result = $travel->shift($this->laravel->databasePath('migrations'), $before);

        if ($result['blocked'] !== []) {
            $this->warn('  ↳ nothing moved: '.implode(', ', $result['blocked']).' already exist(s) at the '.
                'shifted stamp. The whole pass is refused rather than half-applied — pick a different '.
                "shift than `{$travel->expression}`.");

            return;
        }

        if ($result['moved'] === []) {
            $this->line("  ↳ `{$travel->expression}`: nothing new was published, so nothing to place.");

            return;
        }

        $names = array_map('basename', array_values($result['moved']));
        sort($names);

        $this->line(sprintf(
            '  ↳ `%s`: %d newly published migration(s) shifted, order preserved — now %s … %s.',
            $travel->expression,
            count($names),
            substr($names[0], 0, 17),
            substr($names[count($names) - 1], 0, 17),
        ));

        $crossed = $travel->crossings($before, $result['moved']);

        if ($crossed !== []) {
            $this->warn(sprintf(
                '     ! the block sorted past %d already-published migration(s): %s. That only matters '.
                'for any of them that have NOT run — a migration already in the ledger keeps its place. '.
                'If a dependency chain now interleaves, regenerate the whole chain rather than moving one '.
                'more file (migration-publish-ordering.convention.md, "Re-stamping one file to move it").',
                count($crossed),
                implode(', ', array_slice($crossed, 0, 8)).(count($crossed) > 8 ? ', …' : ''),
            ));
        }

        $this->line('     ↳ this governs the NEXT fresh migrate (a new environment, a new tenant schema, '.
            '`migrate:fresh`). Filename order sorts PENDING files; nothing already applied is reordered.');
    }

    /**
     * Table ownership: the one answer on this command that ACTS rather than verifies (beam-facade
     * ticket 29, ruled by 22).
     *
     * A published beam migration and a third-party one can ship the same `create_*` filename. Inside
     * the family that no longer matters — {@see ConvergentTable} means whoever
     * runs first creates and whoever runs second tops up. Against a package whose guard we do not own
     * (`lunarphp/core`'s bare `hasTable → return` over a bigint-morph `activity_log`) filename order is
     * the only lever, and 22 rejected spending it as an invisible publish band. So it is spent as a
     * DECLARED answer: option-first, prompting when interactive, defaulting to beam, and re-dating the
     * published copy so a fresh clone of this install reproduces the result instead of inheriting a
     * hand edit one host has and the next does not.
     *
     * A host that declines an entry gets the competitor's schema and, thanks to the convergent guard, a
     * loud red migrate rather than a quiet wrong table. Never fatal here: a fresh host that cannot reach
     * its database still installs.
     */
    private function resolveTableOwnership(bool $interactive): void
    {
        $resolver = TableOwnershipResolver::forApplication($this->laravel);
        $collisions = $resolver->collisions();

        if ($collisions === []) {
            return;
        }

        $this->line('splicewire:beam:install → table ownership');

        $contested = [];

        foreach ($collisions as $collision) {
            if ($collision->beamWins()) {
                $this->line("  ↳ `{$collision->stem}`: OK — beam's copy already sorts ahead of {$collision->competitor()}.");

                continue;
            }

            $contested[] = $collision;
        }

        if ($contested === []) {
            return;
        }

        foreach ($this->selectOwnedTables($contested, $interactive) as $collision) {
            $this->claim($resolver, $collision);
        }
    }

    /**
     * Which contested stems beam should own. `--own-tables` (even empty) is authoritative; else a
     * multiselect with every entry pre-selected when interactive; else all of them — which is the
     * defaults-true answer `--no-interaction` takes silently.
     *
     * @param  list<MigrationCollision>  $contested
     * @return list<MigrationCollision>
     */
    private function selectOwnedTables(array $contested, bool $interactive): array
    {
        $option = $this->option('own-tables');

        if ($option !== null) {
            $wanted = $this->parseList($option);

            return array_values(array_filter($contested, fn ($c): bool => $this->matchesAny($c->stem, $wanted)));
        }

        if (! $interactive) {
            return $contested;
        }

        $options = [];
        foreach ($contested as $c) {
            $options[$c->stem] = "{$c->stem} — currently {$c->competitor()} wins";
        }

        $chosen = multiselect(
            label: 'Which of these tables does beam own? (unchecked ⇒ the other package\'s migration wins)',
            options: $options,
            default: array_keys($options),
            hint: 'Beam\'s copy is re-dated to run first. Space to toggle.',
        );

        return array_values(array_filter($contested, static fn ($c): bool => in_array($c->stem, $chosen, true)));
    }

    /** Re-date one published migration so it wins, reporting what moved and what that move risks. */
    private function claim(TableOwnershipResolver $resolver, MigrationCollision $collision): void
    {
        $old = $collision->ourMigrationName();
        $prefix = $resolver->winningPrefix($collision);

        if ($prefix === null) {
            $this->warn("  ↳ `{$collision->stem}`: cannot be moved ahead of {$collision->competitor()} ".
                "({$collision->theirPrefix}) — claim it by hand or let the other package own it.");

            return;
        }

        // Read the risks while the file is still where the collision says it is.
        $risks = $resolver->dependencyRisks($collision, $prefix);
        $target = $resolver->claim($collision);

        if ($target === null) {
            $this->warn("  ↳ `{$collision->stem}`: could not re-date {$collision->ourFile} — check permissions.");

            return;
        }

        $new = basename($target, '.php');

        $this->line("  ↳ `{$collision->stem}`: beam owns it — {$old} → {$new}, ahead of ".
            "{$collision->competitor()} at {$collision->theirPrefix}.");

        foreach ($risks as $risk) {
            $this->warn("     ! {$risk}");
        }

        $this->carryMigrationRecord($old, $new);
    }

    /**
     * The convergence preflight (beam-facade ticket 84): every pending convergent stub asked what it
     * would do, all at once, before `migrate` does any of it.
     *
     * IT SITS IN THE OWNERSHIP PASS'S SLOT AND FOR THE SAME REASON — the question's options are the files
     * publish just wrote, and the answer has to land before anything runs them. The difference is what is
     * asked: ownership asks whose FILENAME wins, this asks whether the declared SHAPE can land. A guard
     * answering from inside `migrate` answers correctly and too late, three tables in, with the rest of
     * the queue unasked.
     *
     * ON A CONFLICT THIS STOPS THE INSTALL, which is the behaviour change. Continuing only reaches the
     * same throw a few tables later, having written the ones before it — so the choice is offered to an
     * operator who can see it (interactive) and refused to one who cannot (`--no-interaction` exits
     * non-zero rather than half-applying). `--skip-preflight` restores the old timing outright.
     *
     * @return bool false ⇒ stop the install
     */
    private function preflightConvergence(bool $interactive): bool
    {
        if ($this->option('skip-preflight')) {
            return true;
        }

        $found = ConvergencePreflight::forApplication($this->laravel)->rehearse();

        if ($found === []) {
            return true;
        }

        $this->line('splicewire:beam:install → convergence preflight');

        $conflicted = array_values(array_filter($found, fn (RehearsedMigration $m) => $m->hasConflicts()));
        $skipped = array_values(array_filter($found, fn (RehearsedMigration $m) => ! $m->wasRehearsed()));
        $changing = array_values(array_filter($found, fn (RehearsedMigration $m) => $m->changes() !== [] && ! $m->hasConflicts()));

        $this->line(sprintf(
            '  ↳ %d pending convergent migration%s: %d clean, %d will change the schema, %d cannot converge%s. '.
            'Nothing has been written yet.',
            count($found),
            count($found) === 1 ? '' : 's',
            count($found) - count($conflicted) - count($changing) - count($skipped),
            count($changing),
            count($conflicted),
            $skipped === [] ? '' : ', '.count($skipped).' not rehearsed',
        ));

        foreach ($changing as $migration) {
            foreach ($migration->changes() as $report) {
                $this->line("     + {$report->summary()}");
            }
        }

        foreach ($skipped as $migration) {
            $this->line("     ? `{$migration->migration}` {$migration->skipped}");
        }

        if ($conflicted === []) {
            return true;
        }

        foreach ($conflicted as $migration) {
            $this->warn("     ✗ `{$migration->migration}`");

            foreach ($migration->conflicts() as $conflict) {
                $this->warn("       [{$conflict->kind}] {$conflict->detail}");

                if ($conflict->repair !== '') {
                    $this->line('       repair: '.str_replace("\n", "\n       ", $conflict->repair));
                }
            }
        }

        // The resumability statement, said out loud because its absence made the safe move look risky:
        // an operator who aborts here has changed nothing, and a convergent guard is idempotent, so the
        // install can simply be re-run once the shapes are reconciled.
        $this->line('  ↳ No table was written. Reconcile the shapes above and re-run '.
            '`splicewire:beam:install` — convergent creates are idempotent, so a re-run creates what is '.
            'absent, adds what is missing, and does nothing to a table that already matches.');

        if (! $interactive) {
            $this->error('splicewire:beam:install — stopped before migrating: '.count($conflicted).
                ' migration(s) cannot converge. Pass --skip-preflight to migrate anyway and meet each '.
                'conflict one table at a time.');

            return false;
        }

        return confirm(
            label: 'Migrate anyway? The tables before the first conflict will be written, then it will fail there.',
            default: false,
            hint: 'No is almost always right — nothing is written yet, so aborting costs a re-run.',
        );
    }

    /**
     * Carry an already-run migration's ledger row onto the new filename.
     *
     * Without this a re-dated file the host has already migrated reads as a brand-new migration and runs
     * again. The convergent guard makes that survivable, not free — it would still add a duplicate
     * ledger row. Updating the row keeps "already ran" exactly true, and is honest about what it does
     * NOT do: it cannot change a database that already holds the competitor's shape. Ordering governs
     * the next FRESH migrate (a new environment, a new tenant schema, `migrate:fresh`), which is where
     * the collision is decided; an existing wrong table is the guard's and ticket 30's business.
     */
    private function carryMigrationRecord(string $old, string $new): void
    {
        try {
            $table = config('database.migrations', 'migrations');
            $table = is_array($table) ? ($table['table'] ?? 'migrations') : $table;

            if (! Schema::hasTable($table)) {
                return;
            }

            $moved = DB::table($table)->where('migration', $old)->update(['migration' => $new]);

            if ($moved > 0) {
                $this->line("     ↳ ledger: `{$old}` was already migrated — carried onto `{$new}`, so it ".
                    'does not re-run. The live table keeps whatever shape it already has; the new order '.
                    'governs the next fresh migrate.');
            }
        } catch (\Throwable $e) {
            $this->line('     ↳ ledger: skipped (database unreachable) — re-run the install once the database is up.');
        }
    }

    /** @param  list<InstallStep>  $steps */
    private function stepsMigrate(array $steps): bool
    {
        foreach ($steps as $step) {
            if ($step->migrates) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate the OpenAPI artifact so the OTB docs promise holds on FIRST boot (ADR-0211 §4).
     *
     * Three things this deliberately does:
     *
     *  - **Re-reads the just-published config.** `config/scribe.php` was written by the publish pass a few
     *    steps up, in a process that loaded its config before that file existed. Without this the install's
     *    own generate would run against Scribe's STOCK defaults — Postman on, `add_routes` on, none of
     *    beam's strategies — and produce an artifact of bare paths that looks like a successful install.
     *  - **Never fails the install.** Extraction reflects over every route in the application, which is
     *    exactly where a half-configured fresh host throws. A host that cannot generate yet still has a
     *    working stack and a doctor check telling it so; a host that cannot *install* has nothing.
     *  - **Skips silently when Scribe is not registered.** It is a hard transitive dependency of beam
     *    today, so this is defence rather than a real branch — but a host that removed it should not get a
     *    crash out of an installer.
     */
    /**
     * Run `splicewire:beam:seed` — the seed-side twin of this command, over the same self-registration
     * shape. Install has always publish-and-migrated without ever seeding, which was invisible while the
     * only registered seeder was a nav restamp; it stopped being invisible when ADR-0209 made the realm
     * root a thing SOMETHING has to create, because the renderer deliberately does not.
     *
     * `--no-seed` opts out for a host that seeds on its own schedule. Skipped when nothing is
     * registered, and never fatal: `splicewire:beam:seed` already reports per-seeder failures and
     * carries on, matching how every step of this command reports.
     */
    private function runSeeders(): void
    {
        if ($this->option('no-seed')) {
            return;
        }

        if (! $this->getLaravel()->bound(BeamSeedManifest::class)) {
            return;
        }

        if ($this->getLaravel()->make(BeamSeedManifest::class)->steps() === []) {
            return;
        }

        $this->line('splicewire:beam:install → seed (splicewire:beam:seed)');

        try {
            // ⚠️ READ the exit code. `Command::call()` RETURNS it and does not throw on non-zero, so
            // discarding it — which this line did until beam-docs-satellite ticket 46 — means a seed run
            // that reported failures was indistinguishable from a clean one. The install still continues
            // (a failed demo seeder should not strand a host mid-install); it just stops claiming success.
            $code = $this->call('splicewire:beam:seed', $this->option('force') ? ['--force' => true] : []);

            if ($code !== self::SUCCESS) {
                $this->seedingFailed = true;
            }
        } catch (\Throwable $e) {
            $this->seedingFailed = true;
            $this->warn('  ↳ seeding failed (the rest of the install is unaffected): '.$e->getMessage());
        }
    }

    private function generateOpenApiArtifact(): void
    {
        $app = $this->getApplication();

        if ($app === null || ! $app->has('scribe:generate')) {
            return;
        }

        $this->line('splicewire:beam:install → OpenAPI artifact (scribe:generate)');

        $published = config_path('scribe.php');

        if (is_file($published)) {
            try {
                $fresh = require $published;

                if (is_array($fresh)) {
                    config(['scribe' => $fresh]);
                }
            } catch (\Throwable $e) {
                $this->warn('  ↳ could not read the published config/scribe.php — generating against the '.
                    'config already loaded. ('.$e->getMessage().')');
            }
        }

        try {
            $this->callSilent('scribe:generate');
        } catch (\Throwable $e) {
            $this->warn('  ↳ extraction failed, so no spec was written: '.$e->getMessage());
            $this->line('     beam/openapi.{yaml,json} will 404 until `php artisan scribe:generate` '.
                'succeeds. The rest of the install is unaffected; `beam:doctor` reports the missing artifact.');

            return;
        }

        $artifact = app(ConfiguredArtifactSpecSource::class)->artifactPath();

        if (is_file($artifact)) {
            $this->line("  ↳ OK — {$artifact} written; beam/openapi.yaml + beam/openapi.json now serve it.");

            return;
        }

        $this->warn("  ↳ scribe:generate ran but no artifact landed at {$artifact} — check that ".
            '`scribe.openapi.enabled` is true and that `beam.core.openapi.artifact` points where Scribe writes.');
    }

    /**
     * Verify the three fresh-host provisioning traps closed (beam-install-turnkey), reporting each. The
     * FIXES live as package defaults; this is the orchestrator's verify + report pass, and the one place a
     * host is nudged to author its `nav.yml`. Best-effort + never fatal (a DB may be unreachable in CI).
     *
     * @param  ?string  $tenancy  the answered tenancy mode ('single'|'multi'), or null ⇒ read from config
     */
    private function verifyProvisioning(?string $tenancy): void
    {
        $this->line('splicewire:beam:install → verify provisioning');

        $this->verifySharedMigrations($tenancy);
        $this->verifyNavDisk();
        $this->verifyUsersTable();
    }

    /**
     * Trap 1: the ubiquitous tables publish to `database/migrations/shared/` — a subdir the stock migrator
     * never recurses into. beam-core's boot registers that path for a SINGLE-tenant host (beam-tenancy owns
     * it for a multi-tenant one), so the migrate above ran the shared stubs. Verify the canonical shared
     * table (`beam_particles`, honouring the table prefix) actually landed.
     */
    private function verifySharedMigrations(?string $tenancy): void
    {
        $mode = $tenancy ?? (string) config('beam.core.tenancy', 'single');
        $sharedDir = database_path('migrations/shared');
        $table = Beam::table('particles');

        if (! is_dir($sharedDir)) {
            $this->line("  ↳ trap 1 (shared migrations): {$sharedDir} not published yet — publish + migrate first.");

            return;
        }

        try {
            if (Schema::hasTable($table)) {
                $owner = $mode === 'multi' ? 'beam-tenancy owns the shared path' : 'beam-core registered the shared path (single-tenant)';
                $this->line("  ↳ trap 1 (shared migrations): OK — `{$table}` migrated; {$owner}.");

                return;
            }

            $this->warn("  ↳ trap 1 (shared migrations): `{$table}` is NOT migrated though `{$sharedDir}` is published. ".
                'The stock migrator does not recurse into shared/; ensure beam-core (single-tenant) or beam-tenancy (multi-tenant) registers it.');
        } catch (\Throwable $e) {
            $this->line('  ↳ trap 1 (shared migrations): skipped verification (database unreachable).');
        }
    }

    /**
     * Trap 2: `nav.yml` discovery. NavSource now roots at `resource_path('beam-ux')` when no git-tracked
     * mirror disk is configured (was base_path(), which never matched the documented location). Report the
     * root + whether a nav file is present, nudging the host to author one if absent (nav then DERIVES from
     * entry frontmatter — valid, but a nav.yml pins it).
     */
    private function verifyNavDisk(): void
    {
        $mirror = config('beam.ux.storage.mirror_disk');
        if (is_string($mirror) && $mirror !== '') {
            $root = (string) config("filesystems.disks.{$mirror}.root", '');
            $root = $root !== '' ? rtrim($root, '/') : rtrim(resource_path('beam-ux'), '/');
            $where = "mirror disk `{$mirror}`";
        } else {
            $root = rtrim(resource_path('beam-ux'), '/');
            $where = 'resource_path(beam-ux)';
        }

        $found = null;
        foreach (['yml', 'yaml', 'json'] as $ext) {
            if (is_file("{$root}/nav.{$ext}")) {
                $found = "nav.{$ext}";
                break;
            }
        }

        if ($found !== null) {
            $this->line("  ↳ trap 2 (nav disk): OK — `{$found}` found under {$where} ({$root}).");

            return;
        }

        $this->line("  ↳ trap 2 (nav disk): no nav.{yml,yaml,json} under {$where} ({$root}) — ".
            'nav will DERIVE from entry frontmatter (valid). Drop a nav.yml there to pin it.');
    }

    /**
     * Trap 3: the accounts `create_users_table` migration is now guarded (`hasTable('users')` skips), so a
     * host that already owns a `users` table keeps it while still getting the rest of the auth estate. Report
     * whether a users table is present (guard would have short-circuited) or absent.
     */
    private function verifyUsersTable(): void
    {
        try {
            if (Schema::hasTable('users')) {
                $this->line('  ↳ trap 3 (users table): OK — `users` exists; the accounts create_users_table migration self-skips (hasTable guard).');

                return;
            }

            $this->line('  ↳ trap 3 (users table): no `users` table — the accounts migration will create the uuid-keyed one.');
        } catch (\Throwable $e) {
            $this->line('  ↳ trap 3 (users table): skipped verification (database unreachable).');
        }
    }

    /**
     * Frontend-surface provisioning (beam-install-turnkey, pnpm trap): a pnpm host must pin the
     * unpublished @splicewire/@schemastud transitive JS deps to their local `file:` paths (npm resolves
     * them leniently; pnpm hits ERR_PNPM_FETCH_404). `splicewire:beam:ux:pnpm-overrides` (shipped by
     * laravel-beam-ux) generates that `pnpm.overrides` block — idempotent + a no-op on an npm/yarn host.
     * Skipped silently when laravel-beam-ux isn't installed (the command isn't registered), so beam-core
     * never hard-depends on it.
     */
    private function ensurePnpmOverrides(): void
    {
        $app = $this->getApplication();

        if ($app === null || ! $app->has('splicewire:beam:ux:pnpm-overrides')) {
            return;
        }

        $this->line('splicewire:beam:install → frontend surfaces (pnpm overrides)');
        $this->call('splicewire:beam:ux:pnpm-overrides', $this->option('force') ? ['--force' => true] : []);
    }

    /**
     * Persist the answered values into the published `config/beam/core.php` so they survive the next boot.
     * Best-effort + non-fatal: if the host hasn't published the config (or it isn't writable), the runtime
     * config still governs this run — we just warn. Only the keys the operator actually answered are written.
     *
     * ⚠️ **All THREE answers are written; `tenancy` used to be accepted and dropped** (beam-facade ticket
     * 158). It reached this method, participated in the early-return guard below, and appeared nowhere
     * else — so an operator who answered the tenancy question on an already-installed host had the answer
     * govern the run and die with the process. Nothing reported it, because `beam.core.tenancy` is read in
     * exactly one place in the whole estate: this command's own {@see self::verifySharedMigrations()}.
     *
     * ⚠️ **The regression test's FIXTURE is half of the guard.** {@see self::replaceScalar()} is a
     * `preg_replace` that no-ops on a key absent from the file and returns the contents unchanged, so a
     * test whose fixture omits `'tenancy'` passes against a writer that wrote nothing — which is exactly
     * how this survived two existing `persistConfig` tests. Any new key added here needs its key present
     * in the fixture, or the assertion is vacuous.
     *
     * Safe-unless-force, matching `vendor:publish`: `config/beam/core.php` already existing means it was
     * already published (and may carry hand edits), so — like re-publishing an existing file — persisting
     * over it requires `--force`. Without it, the answered values still govern this run via runtime config;
     * they're just not written to disk.
     */
    private function persistConfig(?string $prefix, ?string $sources, ?string $tenancy): void
    {
        if ($prefix === null && $sources === null && $tenancy === null) {
            return;
        }

        $path = config_path('beam/core.php');

        if (! is_file($path) || ! is_writable($path)) {
            $this->warn('splicewire:beam:install — answered config kept for this run only; publish config/beam/core.php to persist it.');

            return;
        }

        if (! $this->option('force')) {
            $this->warn("splicewire:beam:install — answered config kept for this run only; pass --force to persist it into the already-published {$path} (protects hand edits).");

            return;
        }

        $contents = (string) file_get_contents($path);

        if ($prefix !== null) {
            $contents = $this->replaceScalar($contents, 'table_prefix', $prefix);
        }
        if ($sources !== null) {
            $list = implode(', ', array_map(static fn (string $s): string => "'{$s}'", $this->parseList($sources)));
            $contents = preg_replace("/'sources'\s*=>\s*\[[^\]]*\]/", "'sources' => [{$list}]", $contents) ?? $contents;
        }
        if ($tenancy !== null) {
            $contents = $this->replaceScalar($contents, 'tenancy', $tenancy);
        }

        file_put_contents($path, $contents);
    }

    private function replaceScalar(string $contents, string $key, string $value): string
    {
        return preg_replace("/'{$key}'\s*=>\s*'[^']*'/", "'{$key}' => '{$value}'", $contents) ?? $contents;
    }

    /** @return list<string> */
    private function parseList(string $csv): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $csv)), static fn (string $s): bool => $s !== ''));
    }

    private function banner(): string
    {
        return <<<'ART'
  ┌────────────────────────────────────────┐
  │   ▓▓▓  BEAM  ▓▓▓   schema-driven CMS    │
  │   one command · core-first · retrofit   │
  └────────────────────────────────────────┘
ART;
    }
}
