<?php

namespace Splicewire\Beam\Doctor;

use Illuminate\Support\Facades\DB;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Throwable;

/**
 * Reports rows in the host's `migrations` ledger whose migration FILE no longer exists anywhere in the
 * host's published set — the signature of **a database that is ahead of its own repository**.
 *
 * A `vendor:publish` writes migration files into the host, `migrate` runs them and stamps the ledger, and
 * then the files are discarded without ever being committed. The schema and the ledger both remember a
 * migration the repository does not have. Nothing in the estate says so, because every existing check
 * looks at files: the ledger is the only artifact that still holds the evidence.
 *
 * The consequence is not cosmetic and it is not theoretical. It was measured twice — beam-facade tickets
 * 78 and 110, one host apart. At `rushing/audiostud` the ledger held twelve such rows, and two of them
 * (`create_rank_trees_table`, `create_ranks_table`, published from `splicewire/laravel-beam-rank` and
 * never committed) meant `beam_ranks` and `beam_rank_trees` existed in the dev database and in NO fresh
 * one. The dev database passed the suite; a fresh scratch database migrated clean and then failed
 * `RanksTest` twelve times on `relation "beam_rank_trees" does not exist`. "The suite is green" was not a
 * statement anyone at that host could make, and the reason was invisible from the repository alone.
 *
 * ## Why this is the ledger's question and not the filesystem's
 *
 * The obvious static form of this check — "the package ships a stub with no published copy at the host" —
 * is 88% noise, and that number is measured rather than feared: at `rushing/audiostud`, sixteen installed
 * stubs had no published copy and fourteen of them were entirely correct, because a host does not publish
 * migrations for package features it does not use (beam-schemas, silos, scaffold-packs). Absence of a
 * published copy is not evidence of anything.
 *
 * A ledger row with no file is different in kind. It is not a judgment about what the host ought to have
 * published — it is the host's own record that it DID publish, and ran, a file it can no longer produce.
 * At `rushing/audiostud` that predicate fired twelve times and was right twelve times.
 *
 * ## Its place among the estate's migration audits
 *
 * Four checks now cover published copies, and they partition cleanly by what they compare:
 *  - {@see Support\StubMigrationsAudit} — how a PACKAGE ships its migrations (stub-only, registered).
 *  - {@see RetiredMigrationAudit} — a host copy that exists and should NOT (superseded upstream).
 *  - `Rushing\Surgeon\Conformance\PublishedMigrationDriftAudit` (b3) — a host copy that exists and has
 *    drifted from the package file it was published from.
 *  - this one — a host copy that does NOT exist and should, on the ledger's own evidence.
 *
 * b3 declares this direction out of its scope in as many words ("a package file with no published copy is
 * 'not published yet', which is the installer's question, not this one"), and that exclusion is correct:
 * b3 is a static, git-free, connection-free sweep that ran across twenty hosts in 0.38s, and the noise
 * measurement above is why it should not be taught to report absence from the filesystem. This audit
 * reaches the same defect class from the one artifact b3 deliberately never opens.
 *
 * ## Deliberate limits
 *
 * - **It matches the migrator's own semantics, not a recursive sweep.** A ledger row is an orphan when the
 *   file is absent from the paths the migrator would actually scan — the default `database/migrations` plus
 *   whatever is registered (the estate's `shared/` convention arrives that way). A recursive walk would
 *   silence a genuinely unreachable file sitting in an unregistered subdirectory, which is a defect of the
 *   same family.
 * - **Advisory, never gating, and it nominates no operation.** The repair is a judgment call — re-publish
 *   and commit the stub, or delete the row because the migration was legitimately withdrawn — and it lands
 *   in the host's own tree and its own database. See `docs/agents/migration-publish-ordering.convention.md`.
 * - **No connection, no finding.** A doctor runs in environments with no database (CI conformance, a
 *   package's own test harness). Anything that cannot reach the ledger passes with a stated reason rather
 *   than failing the sweep it is a passenger in.
 */
class LedgerAheadOfRepositoryAudit implements DoctorAudit
{
    private const CHECK = 'migration ledger agrees with the published set';

    /**
     * @param  list<string>|null  $ledger  Migration names, as stored in the `migrations` table. Null reads
     *                                     the live ledger.
     * @param  list<string>|null  $paths  Migration directories to scan. Null asks the migrator.
     */
    public function __construct(
        private ?array $ledger = null,
        private ?array $paths = null,
    ) {}

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        $ledger = $this->ledger ?? $this->readLedger();

        if ($ledger === null) {
            return [Finding::inconclusive(self::CHECK, 'No reachable `migrations` ledger — nothing to compare against.')];
        }

        $onDisk = $this->stemsOnDisk();

        $orphans = array_values(array_filter(
            $ledger,
            static fn (string $name): bool => ! in_array($name, $onDisk, true),
        ));

        if ($orphans === []) {
            return [Finding::pass(self::CHECK, sprintf(
                'All %d ledger row(s) have a migration file in the published set.',
                count($ledger),
            ))];
        }

        return [Finding::warn(self::CHECK, sprintf(
            'The database is ahead of the repository: %d ledger row(s) name a migration this host cannot '.
            'produce — %s. A publish that was run and then never committed leaves exactly this: the schema '.
            'and the ledger remember a migration the repository does not have, so the dev database carries '.
            'state a fresh one cannot reproduce and the suite is green only where the fossil happens to '.
            'live. Re-publish and COMMIT the stub each row came from, or delete the row if the migration '.
            'was withdrawn on purpose.',
            count($orphans),
            implode(', ', $orphans),
        ))];
    }

    /**
     * @return list<string>|null
     */
    private function readLedger(): ?array
    {
        try {
            $table = config('database.migrations.table', 'migrations');

            if (is_array($table)) {
                $table = $table['table'] ?? 'migrations';
            }

            return DB::table((string) $table)->orderBy('id')->pluck('migration')->all();
        } catch (Throwable) {
            // No connection, no database, or no ledger table yet. All three mean "nothing to compare",
            // never "the host is broken" — a doctor must not turn an absent database into a finding.
            return null;
        }
    }

    /**
     * Migration stems the MIGRATOR would find, which is the only definition that makes an orphan an
     * orphan: a file the migrator cannot reach is exactly as absent as a file that does not exist.
     *
     * @return list<string>
     */
    private function stemsOnDisk(): array
    {
        $stems = [];

        foreach ($this->migrationPaths() as $path) {
            // Non-recursive by design, matching Illuminate's own `Migrator::getMigrationFiles()`.
            foreach ((array) glob(rtrim($path, '/').'/*_*.php') as $file) {
                if (is_string($file)) {
                    $stems[] = basename($file, '.php');
                }
            }
        }

        return array_values(array_unique($stems));
    }

    /**
     * @return list<string>
     */
    private function migrationPaths(): array
    {
        if ($this->paths !== null) {
            return $this->paths;
        }

        $paths = [database_path('migrations')];

        try {
            $paths = array_merge($paths, app('migrator')->paths());
        } catch (Throwable) {
            // Unresolvable migrator (a bare container in a package harness) — the default path stands.
        }

        return array_values(array_unique(array_filter($paths, 'is_dir')));
    }
}
