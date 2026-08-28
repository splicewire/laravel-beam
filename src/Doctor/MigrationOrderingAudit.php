<?php

namespace Splicewire\Beam\Doctor;

use Composer\InstalledVersions;
use Illuminate\Contracts\Foundation\Application;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Rushing\SchemaConvergence\ConvergentTable;
use Splicewire\Beam\Doctor\Support\MigrationTableRef;
use Splicewire\Beam\Doctor\Support\MigrationTableScanner;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Install\BeamInstallManifest;
use Splicewire\Beam\Install\InstallStep;
use Splicewire\Beam\Install\MigrationFiles;

/**
 * Catches the cross-package migration-ordering defect at boot instead of from a failed `migrate` three
 * environments later — now against the files a host will actually run, rather than against a dialect
 * the estate abandoned.
 *
 * ## What this audit used to be, and why none of it worked
 * The original design was pure over PACKAGE SOURCE: read each package's migration stubs for
 * `Schema::create('x')` / `Schema::table('x')`, join that against the install manifest's declared
 * {@see InstallStep::$order}, and warn when a package ALTERs a table it registered no higher than the
 * package that creates it. Its motivating incident was real — a greenfield install came up with no
 * `silos.name_path` because beam-taxonomy created the table, tower altered it, and both sat on order
 * 100.
 *
 * Two of the estate's own conventions falsified the premise:
 *
 * 1. **Convergence removed the shape it read.** Measured 2026-08-27 across every
 *    `~/Workspaces/php/packages/&#42;/&#42;/database/migrations` directory: **0** sites match
 *    `Schema::create('…')` and **157** declare their create as `ConvergentTable::named(…)`. `$creates`
 *    was empty on every host, every ALTER hit `$creator === null`, and the audit returned an
 *    unconditional `pass`. Confirmed at `~/Herd/tower`: *"1 finding — PASS Every cross-package ALTER
 *    installs after the package that creates its table."*
 * 2. **Half the remaining names are not literals.** 39 of those 157 name their table through
 *    `$this->target()`, `$tableNames['roles']` or `$lunarPrefix.'products'`, and 15 more through
 *    `Beam::table('x')`. A regex extension recovers the literal 103 and nothing else.
 *
 * And the audit's own docblock cited an incident it could only half see: taxonomy's
 * `ConvergentTable::named('silos')` was invisible while tower's `Schema::table('silos', …)` was visible.
 *
 * ## Why the model changed, not just the reader
 * `ALTER-after-CREATE` is largely no longer a defect. {@see ConvergentTable} makes a create and a
 * top-up the SAME call — whoever runs first creates, whoever runs second adds the missing columns — so
 * a convergent stub stamped ahead of "its" create simply becomes the create. That is why the original
 * incident cannot recur in the original form.
 *
 * What convergence cannot dissolve is a **foreign key**. `->constrained('silos')` needs `silos` to
 * exist, as a real table, in the resolving schema, at the instant the constraint is added; no top-up
 * conjures it. Measured 2026-08-27 at `~/Herd/splicewire-app`, this is the shape that actually broke:
 * `tenant/…_create_entities_table.php` carries `->constrained('silos')` and sorted BEFORE
 * `shared/…_create_silos_table.php`, so the unqualified reference fell through the search_path (widened
 * to `"$db,public"` for pgvector by `laravel-beam-tenancy`'s `PostgreSQLSchemaManager`) and bound to
 * `public.silos` while the writer writes `tenant_system.silos`. The old audit modelled none of it: both
 * files are convergent creates, so it is `CREATE-after-CREATE-via-FK`, not `ALTER-after-CREATE`.
 *
 * ## The substrate: published files, not stubs
 * Both facts this check needs are knowable only AFTER publish. Publishing stamps a filename, so the
 * order that decides the outcome is the stamped prefix on disk — not a declared install-step order,
 * which only ever *predicted* it. And a host's prefix, connection and table names resolve in a booted
 * container, where `Beam::table('hooks')` answers. So the population is
 * {@see MigrationFiles::pathsFor()} — exactly what the migrator will read — plus the published
 * subdirectories under `database/migrations`, because `tenant/` is registered by the tenancy layer's
 * own `--path` rather than by the migrator and is one half of the live defect.
 *
 * **This gives up the audit's purity, deliberately.** It was pure over source, with no disk, container
 * or DB access, and that is a real property a package-tier check gets to keep. It is spent here for one
 * reason: every question this audit asks is a question about the HOST — which files got stamped, in
 * which order, under which prefix — and a pure-over-source check can only answer a question about the
 * declaration. The reading half stays pure and unit-testable in {@see MigrationTableScanner}; only the
 * join runs against the host. `TableOwnershipResolver` already made the same trade for the same reason.
 *
 * ## Advisory, and quiet where it cannot see
 * Never fatal: "is this ordering correct here?" is a fact about the host, so it is a Finding. The
 * scanner's refusal to guess an opaque name is kept verbatim — but the silence is now COUNTED and
 * reported in the pass detail, so an empty result cannot be misread as coverage.
 */
class MigrationOrderingAudit implements DoctorAudit
{
    public function __construct(
        private Application $app,
        private BeamInstallManifest $manifest,
    ) {}

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        $check = 'cross-package migration ordering';

        $files = $this->population();
        $creators = [];
        $opaque = 0;
        $resolved = 0;

        foreach ($files as $key => $file) {
            foreach (MigrationTableScanner::creates($file['source']) as $ref) {
                $table = $this->resolve($ref);

                if ($table === null) {
                    $opaque++;

                    continue;
                }

                $resolved++;

                // First stamp to claim a table owns it here: an edge needs exactly one create to be
                // ordered against, and the earliest is the one it has to land after. A later duplicate
                // create is a different defect with its own check — `UnguardedCreateAudit` and
                // `TableOwnershipResolver` between them own that shape.
                $creators[$table] ??= $key;
            }
        }

        $findings = [];
        $edges = 0;

        foreach ($files as $key => $file) {
            foreach ($this->dependencies($file['source']) as [$ref, $kind]) {
                $table = $this->resolve($ref);

                if ($table === null) {
                    $opaque++;

                    continue;
                }

                $edges++;
                $creator = $creators[$table] ?? null;

                if ($creator === null || $creator === $key || $creator < $key) {
                    // Unknown creator (the table is created outside every path this host runs), the same
                    // file, or a create that already lands first — none of them is an ordering defect.
                    continue;
                }

                $findings[] = Finding::warn($check, $this->detail($file, $files[$creator], $table, $ref, $kind));
            }
        }

        if ($findings !== []) {
            return $findings;
        }

        return [Finding::pass($check, sprintf(
            'No migration references a table another migration creates later. Read %d file(s): '
            .'%d table name(s) resolved, %d dependency edge(s) checked, %d name(s) left unresolved and '
            .'reported on by nobody — a dynamic name (`$this->target()`, `$tableNames[…]`) is skipped '
            .'rather than guessed at, so this pass is not a claim of full coverage.',
            count($files),
            $resolved,
            $edges,
            $opaque,
        ))];
    }

    /**
     * Every dependency a migration declares on a table it does not create, tagged with why it is one.
     *
     * A bare `Schema::table()` needs the table to exist because it is not convergent; an FK needs it to
     * exist because a constraint cannot be topped up. `ConvergentTable::named()` is in neither list —
     * that is the whole point of the guard.
     *
     * @return list<array{0: MigrationTableRef, 1: string}>
     */
    private function dependencies(string $source): array
    {
        $rows = [];

        foreach (MigrationTableScanner::references($source) as $ref) {
            $rows[] = [$ref, 'points a foreign key at'];
        }

        foreach (MigrationTableScanner::alters($source) as $ref) {
            $rows[] = [$ref, 'alters (unguarded, so the table must already exist)'];
        }

        return $rows;
    }

    /**
     * The name this host will use, or `null` when the scanner declined to read the site.
     *
     * The prefix seam is resolved through the CONTAINER rather than reconstructed from config, because
     * `Beam::table()` is the seam every migration actually calls and a second implementation of it here
     * would be a second thing to drift. A host that has not bound the facade yields `null` — silence,
     * which is this audit's declared failure mode.
     */
    private function resolve(MigrationTableRef $ref): ?string
    {
        if ($ref->name === null) {
            return null;
        }

        if (! $ref->prefixed) {
            return $ref->name;
        }

        try {
            return Beam::table($ref->name);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The files the next migrate pass will read, keyed by the sort key that decides their order.
     *
     * Keyed `prefix_stem` rather than by position, so a plain `<` comparison between two keys IS the
     * migrator's own ordering and no second sort has to agree with it.
     *
     * @return array<string, array{path: string, prefix: string, stem: string, source: string}>
     */
    private function population(): array
    {
        $paths = MigrationFiles::pathsFor($this->app);

        // `database/migrations/tenant` is registered by the tenancy layer's own `--path`, never with the
        // migrator, so `pathsFor()` cannot see it — and it is one half of the `entities`/`silos` defect
        // this audit exists to catch. Published subdirectories are enumerated directly for that reason.
        $root = $this->app->databasePath('migrations');

        foreach (glob($root.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [] as $directory) {
            $paths[] = $directory;
        }

        $files = [];

        foreach (MigrationFiles::in(array_values(array_unique($paths))) as [$prefix, $stem, $path]) {
            $files[$prefix.'_'.$stem] = [
                'path' => $path,
                'prefix' => $prefix,
                'stem' => $stem,
                'source' => (string) @file_get_contents($path),
            ];
        }

        ksort($files);

        return $files;
    }

    /**
     * @param  array{path: string, prefix: string, stem: string, source: string}  $file
     * @param  array{path: string, prefix: string, stem: string, source: string}  $creator
     */
    private function detail(array $file, array $creator, string $table, MigrationTableRef $ref, string $kind): string
    {
        return sprintf(
            '`%s` %s `%s` on line %d (%s, read as %s), but `%s` creates that table and sorts AFTER it. '
            .'The migrator runs filenames in order, so the reference lands against a table that does not '
            .'exist yet — or, on Postgres with a widened search_path, silently binds to whichever schema '
            .'already holds a table of that name. %sRe-stamp the create below the reference, or raise the '
            .'creating package\'s install-step order so publishing stamps it first.',
            $file['prefix'].'_'.$file['stem'],
            $kind,
            $table,
            $ref->line,
            $ref->shape,
            $ref->via,
            $creator['prefix'].'_'.$creator['stem'],
            $this->packageHint($creator['path']),
        );
    }

    /** Which registered package owns a file, when it is one of ours and the manifest knows its order. */
    private function packageHint(string $path): string
    {
        foreach ($this->manifest->steps() as $step) {
            $install = $this->installPath($step->package);

            if ($install !== null && str_starts_with($path, $install)) {
                return sprintf('The create ships in `%s` (install order %d). ', $step->package, $step->order);
            }
        }

        return '';
    }

    private function installPath(string $package): ?string
    {
        try {
            return InstalledVersions::isInstalled($package)
                ? InstalledVersions::getInstallPath($package)
                : null;
        } catch (\OutOfBoundsException) {
            return null;
        }
    }
}
