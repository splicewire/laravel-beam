<?php

namespace Splicewire\Beam\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Install\BeamInstallManifest;
use Splicewire\Beam\Install\InstallStep;
use Splicewire\Beam\Install\MigrationCollision;
use Splicewire\Beam\Install\TableOwnershipResolver;
use Splicewire\Beam\Schema\ConvergentTable;

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
 */
class BeamInstallCommand extends Command
{
    protected $signature = 'splicewire:beam:install
        {--force : Overwrite any already-published files}
        {--prefix= : Beam table prefix (config beam.core.table_prefix); pass an empty string for no prefix}
        {--schema-sources= : Comma list of schema sources in read/write order, e.g. "db,file" or "file"}
        {--tenancy= : "single" (one database) or "multi" (tenant-scoped)}
        {--modules= : Comma list of optional beam modules to install (name substrings); empty ⇒ core only}
        {--own-tables= : Comma list of colliding migration stems beam should own (run first); empty ⇒ own none. Default: all}';

    protected $description = 'Interactively configure + install the whole beam stack (core-first) from the self-registration manifest.';

    public function handle(BeamInstallManifest $manifest): int
    {
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
        $this->publishSteps($runSteps);
        $this->resolveTableOwnership($interactive);

        if ($this->stepsMigrate($runSteps)) {
            $this->call('migrate', $this->option('force') ? ['--force' => true] : []);
        }

        $this->persistConfig($prefix, $sources, $tenancy);

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

        if ($interactive) {
            outro('beam stack installed.');
        } else {
            $this->info('beam stack installed.');
        }

        return self::SUCCESS;
    }

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
