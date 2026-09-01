<?php

namespace Splicewire\Beam\Surgeon;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Doctor\FrameManifestAudit;

/**
 * The **central-pin relation identity** audit: for every pin of the `central` connection, does the pinned
 * frame and the ambient tenant frame resolve the same `pg_class` OID?
 *
 * Not "how many schemas". Not "how many rows". **Which physical relation.**
 *
 * ## Why relation identity, and not the residency oracle that was proposed first
 * The instrument this replaces asked where a model's rows *live*, and every version of it got
 * `Splicewire\Beam\Ux\Theme\ThemeResolver` wrong, because residency is a proxy for relation identity and
 * row counts are a proxy for residency. Measured at `~/Herd/splicewire-app` on 2026-09-01: the pins in
 * `Splicewire\Tower\Models\*` are inert because pinned and unpinned resolve the **identical** relation
 * (`leads` OID 634194833 under both frames), while `ThemeResolver` is load-bearing because they resolve
 * **different** relations (`beam_ux_entries` 634194919 central vs 634226901 tenant) — and *both of those
 * relations are empty*. A row-count instrument sees `0` and `0` and calls it inert. An OID instrument sees
 * two OIDs and calls it what it is. The proxy is what failed; the OID is the thing itself.
 *
 * ## The oracle
 * `to_regclass(<relation>)::oid`, evaluated under a transaction-local `set_config('search_path', …, true)`
 * for each frame. The frames are:
 *
 * - **pinned** — the `central` connection's own `search_path` ({@see centralFrame()}; `public` in every
 *   host on this estate).
 * - **ambient** — `<tenant schema>,public`, which is what `Splicewire\Beam\Tenancy\PostgreSQLSchemaManager`
 *   builds when it overrides stancl's hook with `"$databaseName,public"`, reached through
 *   `HybridPostgresTenantDatabaseManager::makeConnectionConfig()`'s schema branch.
 *
 * The probe runs on the `central` handle inside a transaction that is always rolled back, and uses
 * `is_local = true`, so it cannot leak a `search_path` onto a pooled connection.
 *
 * ## Five attacks this survived before it was written
 * Each was measured at `~/Herd/splicewire-app` (18 schemas: `public` + 17 tenant… **19 as of 2026-09-01**,
 * `public` + 18 tenant — the estate moved under the brief, which is itself the point of attack 3).
 *
 * 1. **Is OID identity the right question for WRITES?** Yes, and it is not an argument from construction:
 *    `explain (verbose) insert/update/delete` on an unqualified `roles` under `search_path =
 *    tenant_splicewire,public` all print `… on tenant_splicewire.roles`. Postgres resolves an unqualified
 *    write target by the same first-match rule as a read, so one OID answer covers both. The one construct
 *    that does NOT follow the rule is unqualified `CREATE`, which takes the first schema unconditionally —
 *    and no pin creates a relation at runtime, so it is out of scope here rather than unhandled.
 * 2. **What does this say for an isolated-database tenant?** `unknown`, and it must. That topology swaps
 *    the connection's `database` (and possibly its `host`) and never reaches the schema manager at all, so
 *    there is no `search_path` to compare and, worse, `pg_class` OIDs are per-database — the probe cannot
 *    see the other catalog even to fail honestly. It is gated out ({@see MARKER}). ⚠️ **Zero tenants on
 *    this estate carry the marker (0 of 18, measured 2026-09-01), so that branch has never been exercised
 *    against a real isolated tenant.** It is written to be conservative rather than tested to be right:
 *    any error determining the count is treated as `unknown`, never as zero.
 * 3. **Does the answer change over time?** Yes — proven, not assumed. The brief for this audit cited
 *    `tenant_numero` at 119 tables against 115 for the other sixteen, four `beam_calendar*` tables
 *    mid-rollout. By 2026-09-01 all eighteen tenant schemas carry 119 and `beam_calendar*` sits in `public`
 *    **and** every tenant schema. So a relation moves from `convergent` to `divergent` the moment a tenant
 *    migration lands, with no code change anywhere. **A verdict here is a snapshot of this database at this
 *    moment**, which is exactly why {@see CHECK_CONVERGENT} refuses to conclude anything about the pin.
 * 4. **What about partial replication — a relation in `public` and only SOME tenant schemas?** The audit
 *    probes **every** tenant schema and reports `mixed`, naming which schemas answered which way. It never
 *    samples one tenant, because sampling one tenant is the estate's "the flagship is not the estate"
 *    failure one tier down. ⚠️ The brief's premise that four relations sit in `public` plus exactly one
 *    tenant is **false as of 2026-09-01**: the census is 55 relations in `public` + all 18 tenants, 110
 *    `public`-only, 64 tenant-only, and **zero** partially replicated. The `mixed` branch therefore has no
 *    live instance — it is an instrument for a reachable state, not a report on a present one.
 *
 *    ⚠️ **This attack is also what the first cut got wrong, and only the estate could show it.** That cut
 *    read `mixed` as "the tenant frames disagree with each other", and the first run at the flagship
 *    returned `mixed` for all seven genuinely divergent relations — because every tenant schema holds its
 *    own physical `roles` with its own OID, and eighteen distinct OIDs is what divergence LOOKS like. The
 *    comparison is per-tenant **against central**, and what is compared across tenants is that boolean, not
 *    an OID. A one-tenant fixture cannot express the difference; a seventeen-tenant host says it instantly.
 *    See {@see verdict()}.
 * 5. **A host with no tenant schemas.** `~/Herd/tower` has exactly one schema, `public`. The population
 *    gate fails and it emits `unknown`; it does **not** report every pin as `convergent`, which is what a
 *    naive comparison would say there and would be the most confidently wrong output this audit could
 *    produce.
 *
 * ## What this audit does NOT claim, ever
 * Three sentences it is built to be incapable of saying, because each is false and each is the obvious
 * misreading of its output:
 *
 * - **`convergent` does not mean the pin is unnecessary or safe to remove.** Beyond attack 3's snapshot
 *   problem, `central` is a **separate PDO handle**. An unpinned model joins the ambient request's
 *   transaction; a pinned one does not. So even a byte-identical relation is not a byte-identical
 *   behaviour, and the difference is transactional visibility and rollback scope. No call path in this
 *   estate has been shown to depend on it — and nobody has looked hard, which is not the same as none.
 * - **`divergent` does not mean the pin is wrong.** It means the pin is load-bearing: it selects a
 *   different physical relation than the ambient frame would. Whether that is the intent is a question
 *   about intent, and this audit cannot see intent.
 * - **Nothing here justifies a pin.** {@see CentralPinJustificationAudit::FLOOR_CATEGORIES} is a closed
 *   list of *reasons*, and "the OIDs differ" is not one. This audit is deliberately not a citation source
 *   for that one, and its verdicts must never be widened into it.
 *
 * ## Composition, not re-derivation
 * The population is {@see CentralPinJustificationAudit::pins()} — the same census
 * {@see CentralPinResolvabilityAudit} composes, for the same reason: a pin cannot appear in one of these
 * three audits and not the others. What this one adds on top is a relation per row, resolved by
 * {@see relationsFor()}.
 *
 * ⚠️ **Not every pin has a relation, and that is a fourth outcome rather than a filter.** Four of the 26
 * pins at the flagship pin the connection through `DB::connection('central')` / `Schema::connection(…)` —
 * a raw handle with no model and therefore no relation. They are reported as `no-relation` and named,
 * because a population that quietly shrinks is {@see FrameManifestAudit}'s defect
 * wearing a different hat.
 *
 * ## It names pins; it never reports a cardinality alone
 * `FrameManifestAudit` resolves a registry and prints `"frame manifest resolves (N resources)"`, and N was
 * right while 16 of 36 framed resources reached no realm. A count cannot see membership and it cannot see
 * relation identity either. Every finding here names the pin, the relation, and both OIDs.
 *
 * ## Advisory, permanently, and computed on read
 * Whether a relation resolves — and to what — is a fact about the HOST's database at this instant, which
 * `rushing/laravel-doctor/docs/agents/gate-or-advisory.convention.md` names as its textbook advisory case.
 * The estate bought that rule with an outage: a boot-time throw over a host-dependent fact took
 * `~/Herd/tower` off the air entirely. So `gate: false`, and nothing above {@see Finding::warn()} is ever
 * emitted, at any verdict. Nothing is stamped at `register()`; every OID is read at `run()`, so a
 * migration that lands between boot and audit is seen rather than remembered.
 */
class CentralPinRelationIdentityAudit implements DoctorAudit
{
    /** The census line, and every `unknown` — emitted whether or not anything warned. */
    public const CHECK_CENSUS = 'tenancy.central-pin-relation';

    /** Pinned and ambient frames resolve the SAME relation at this host, right now. */
    public const CHECK_CONVERGENT = 'tenancy.central-pin-relation.convergent';

    /** The pinned frame cannot see the relation at all: touching the pin is `relation … does not exist`. */
    public const CHECK_UNRESOLVABLE = 'tenancy.central-pin-relation.unresolvable';

    /** Tenant schemas disagree with each other — partial replication. No live instance; see attack 4. */
    public const CHECK_MIXED = 'tenancy.central-pin-relation.mixed';

    public const CONVERGENT = 'convergent';

    public const DIVERGENT = 'divergent';

    public const UNRESOLVABLE = 'unresolvable';

    public const MIXED = 'mixed';

    public const NO_RELATION = 'no-relation';

    /**
     * The ambient tenant frame's shape. `Splicewire\Beam\Tenancy\PostgreSQLSchemaManager:13` overrides
     * stancl's `makeConnectionConfig()` hook with `"$databaseName,public"`, and
     * `~/Herd/splicewire-app/config/tenancy.php` binds `HybridPostgresTenantDatabaseManager` onto it. Held
     * as a literal here because beam-core does not require beam-tenancy and must not reach into it for a
     * string; the constructor seam is how a host that shapes it differently corrects this.
     */
    public const TENANT_SEARCH_PATH = '%s,public';

    /**
     * The tenant attribute that means "this tenant is NOT a schema on this cluster" —
     * `HybridPostgresTenantDatabaseManager::isolatedDatabaseDestination()`'s backing key. Its presence on
     * any tenant makes the whole comparison unanswerable; see attack 2.
     */
    public const MARKER = 'isolated_database_destination';

    /**
     * @param  \Closure(): list<string>|null  $tenantSchemas  The tenant schemas on this cluster. Null reads
     *                                                        `pg_namespace` for the configured tenancy
     *                                                        prefix.
     * @param  \Closure(string, list<string>): array<string, int|null>|null  $resolve  `(searchPath,
     *                                                                                 relations) => [relation => oid|null]`. Null
     *                                                                                 runs `to_regclass` on the `central` handle.
     * @param  \Closure(): ?int|null  $isolatedTenants  How many tenants carry {@see MARKER}. **Null return
     *                                                  means "could not determine" and gates the audit
     *                                                  out** — never assume zero.
     */
    public function __construct(
        protected CentralPinJustificationAudit $census,
        protected ?\Closure $tenantSchemas = null,
        protected ?\Closure $resolve = null,
        protected ?\Closure $isolatedTenants = null,
    ) {}

    /**
     * Host-scoped wiring: the same census scope its two siblings use, so the three always report over an
     * identical population.
     */
    public static function forApp(?CentralPinJustificationAudit $census = null): self
    {
        return new self($census ?? CentralPinJustificationAudit::forApp());
    }

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        $pins = $this->census->pins();

        if ($pins === []) {
            return [Finding::inconclusive(self::CHECK_CENSUS, sprintf(
                'No pins of the [%s] connection in scope, so there is no relation to compare frames over. '
                .'Nothing was measured.',
                CentralPinJustificationAudit::CENTRAL,
            ))];
        }

        if (($blocked = $this->gate()) !== null) {
            return [Finding::inconclusive(self::CHECK_CENSUS, sprintf(
                'Relation identity is UNKNOWN for all %d pin(s) of the [%s] connection here: %s Nothing was '
                .'measured — in particular this is NOT a report that the pins are convergent, which is what '
                .'a comparison run anyway would have said. The pins in scope are: %s.',
                count($pins),
                CentralPinJustificationAudit::CENTRAL,
                $blocked,
                $this->namePins($pins),
            ))];
        }

        $rows = $this->verdicts();
        $findings = [];

        foreach ($rows as $row) {
            $finding = match ($row['verdict']) {
                self::CONVERGENT => Finding::warn(self::CHECK_CONVERGENT, $this->convergentDetail($row)),
                self::UNRESOLVABLE => Finding::warn(self::CHECK_UNRESOLVABLE, $this->unresolvableDetail($row)),
                self::MIXED => Finding::warn(self::CHECK_MIXED, $this->mixedDetail($row)),
                default => null,
            };

            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        $findings[] = Finding::pass(self::CHECK_CENSUS, $this->censusDetail($rows));

        return $findings;
    }

    /**
     * One row per (pin, relation) pair — a pin reaching two models produces two rows, because the two
     * relations can and do answer differently.
     *
     * @return list<array{class: string, file: string, line: int, form: string, model: string, relation: string, central: int|null, tenants: array<string, int|null>, verdict: string}>
     */
    public function verdicts(): array
    {
        $rows = [];
        $schemas = $this->schemas();

        foreach ($this->census->pins() as $pin) {
            $relations = $this->relationsFor($pin);

            if ($relations === []) {
                $rows[] = $this->row($pin, '', '', null, [], self::NO_RELATION);

                continue;
            }

            foreach ($relations as $model => $relation) {
                $central = $this->oid($this->centralFrame(), $relation);
                $tenants = [];

                foreach ($schemas as $schema) {
                    $tenants[$schema] = $this->oid(sprintf(self::TENANT_SEARCH_PATH, $schema), $relation);
                }

                $rows[] = $this->row($pin, $model, $relation, $central, $tenants, $this->verdict($central, $tenants));
            }
        }

        return $rows;
    }

    /**
     * The verdict rule, in one place.
     *
     * `mixed` is checked FIRST and deliberately: a relation that is convergent in nine tenants and
     * divergent in nine is neither, and collapsing it to a majority is the failure attack 4 names.
     *
     * @param  array<string, int|null>  $tenants
     */
    protected function verdict(?int $central, array $tenants): string
    {
        // The pinned frame cannot see the relation. Decided FIRST, and independently of the tenants:
        // when the central frame resolves nothing the pin is not "convergent on nothing" — reporting an
        // absent relation as agreement would be the row-count proxy's mistake in OID clothing.
        if ($central === null) {
            return self::UNRESOLVABLE;
        }

        // ⚠️ The comparison is per-tenant AGAINST CENTRAL, never tenant against tenant.
        //
        // The first cut of this method took `mixed` to mean "the tenant frames disagree with each other",
        // and running it at `~/Herd/splicewire-app` on 2026-09-01 returned `mixed` for all seven genuinely
        // divergent relations — because of course they disagree: every tenant schema holds its own physical
        // `roles`, with its own OID. Eighteen distinct OIDs is what divergence LOOKS like, not a defect.
        // What `mixed` has to mean is that the tenants disagree about the only question being asked —
        // whether their frame lands on the pinned relation — which is a boolean per tenant, not an OID.
        $answers = [];

        foreach ($tenants as $oid) {
            $answers[$oid === $central ? self::CONVERGENT : self::DIVERGENT] = true;
        }

        if (count($answers) > 1) {
            return self::MIXED;
        }

        return array_key_first($answers) ?? self::CONVERGENT;
    }

    /**
     * The population gates, all required. Any failure returns the reason and the audit emits `unknown`.
     *
     * Order is cheapest-and-most-decisive first, so a host that is not multi-tenant never touches its
     * database on this audit's behalf.
     */
    protected function gate(): ?string
    {
        $central = CentralPinJustificationAudit::CENTRAL;
        $config = config("database.connections.{$central}");

        if ($config === null) {
            return sprintf(
                'this host defines no [%s] connection, so there is no pinned frame to resolve under '
                .'(CentralPinResolvabilityAudit reports that as a fail, which is the finding to read).',
                $central,
            );
        }

        if (($config['driver'] ?? null) !== 'pgsql') {
            return sprintf(
                'the [%s] connection\'s driver is [%s], and schema-per-tenant search_path resolution — the '
                .'thing this audit measures — is PostgreSQL-specific.',
                $central,
                $config['driver'] ?? 'none',
            );
        }

        if (config('tenancy.database.managers.pgsql') === null) {
            return 'this host configures no PostgreSQL tenant database manager (`tenancy.database.managers'
                .'.pgsql`), so there is no ambient tenant frame for a pin to differ from.';
        }

        $isolated = $this->isolatedTenantCount();

        if ($isolated === null) {
            return sprintf(
                'whether any tenant carries the [%s] marker could not be determined, and an isolated-'
                .'database tenant lives in a different catalog whose pg_class OIDs are not comparable with '
                .'this one\'s. Undeterminable is treated as blocking rather than as zero, on purpose.',
                self::MARKER,
            );
        }

        if ($isolated > 0) {
            return sprintf(
                '%d tenant(s) carry the [%s] marker, so their ambient frame is a different DATABASE rather '
                .'than a schema on this one — pg_class OIDs are per-catalog and cannot be compared across '
                .'it. (This branch has never run against a real isolated tenant; none exist on this estate.)',
                $isolated,
                self::MARKER,
            );
        }

        if ($this->schemas() === []) {
            return sprintf(
                'no schema on this cluster matches the tenancy prefix [%s], so there is no ambient tenant '
                .'frame to compare the pinned frame against.',
                $this->prefix(),
            );
        }

        return null;
    }

    /** @var list<string>|null */
    protected ?array $schemaCache = null;

    /**
     * Every tenant schema on this cluster, sorted. Cached for the run only — computed on read, never
     * stamped at registration.
     *
     * @return list<string>
     */
    protected function schemas(): array
    {
        if ($this->schemaCache !== null) {
            return $this->schemaCache;
        }

        if ($this->tenantSchemas !== null) {
            return $this->schemaCache = array_values(($this->tenantSchemas)());
        }

        try {
            $rows = $this->connection()->select(
                'select nspname from pg_namespace where nspname like ? order by nspname',
                [$this->prefix().'%'],
            );
        } catch (\Throwable) {
            return $this->schemaCache = [];
        }

        return $this->schemaCache = array_map(fn ($row) => (string) $row->nspname, $rows);
    }

    protected function prefix(): string
    {
        return (string) config('tenancy.database.prefix', 'tenant_');
    }

    /**
     * The pinned frame: whatever `search_path` the `central` connection block declares. Read from config
     * rather than assumed, even though every host on this estate declares `public`.
     */
    protected function centralFrame(): string
    {
        $path = config('database.connections.'.CentralPinJustificationAudit::CENTRAL.'.search_path');

        return is_string($path) && $path !== '' ? $path : 'public';
    }

    /**
     * `to_regclass()` under one frame. The `set_config(…, true)` is transaction-local and the transaction
     * is always rolled back, so a pooled `central` handle cannot be left with a mutated `search_path`.
     *
     * A relation that does not resolve returns null — which is a VERDICT input here, not an error, so a
     * throw from the driver (permissions, a dropped schema mid-run) is distinguished by being allowed to
     * surface as null too. That conflation is acceptable precisely because both land on `unresolvable`,
     * whose finding text tells the reader to check the relation exists rather than asserting why it does not.
     */
    protected function oid(string $searchPath, string $relation): ?int
    {
        if ($this->resolve !== null) {
            return ($this->resolve)($searchPath, [$relation])[$relation] ?? null;
        }

        $connection = $this->connection();

        // Hand-rolled rather than ->transaction(), because that helper COMMITS on a clean return and the
        // rollback is the whole safety argument: it is the only thing that guarantees the transaction-local
        // search_path never outlives the probe on a pooled handle.
        try {
            $connection->beginTransaction();

            try {
                $connection->statement('select set_config(?, ?, true)', ['search_path', $searchPath]);
                $rows = $connection->select('select to_regclass(?)::oid as oid', [$relation]);
                $oid = $rows[0]->oid ?? null;
            } finally {
                $connection->rollBack();
            }

            return $oid === null ? null : (int) $oid;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function connection(): Connection
    {
        return DB::connection(CentralPinJustificationAudit::CENTRAL);
    }

    /**
     * How many tenants carry the isolated-database marker, or **null when that cannot be determined**.
     *
     * beam-core does not require beam-tenancy and has no tenant model to ask, so this reads the marker out
     * of the tenants table's stancl `data` column directly. Every failure path returns null, and
     * {@see gate()} treats null as blocking: assuming zero would let the audit report OID comparisons that
     * are meaningless for a topology it cannot see.
     */
    protected function isolatedTenantCount(): ?int
    {
        if ($this->isolatedTenants !== null) {
            return ($this->isolatedTenants)();
        }

        try {
            $connection = $this->connection();

            if (! $connection->getSchemaBuilder()->hasTable('tenants')) {
                // No tenants table on the central frame: there is nothing to be isolated, and the schema
                // gate below will catch a host that has tenant schemas anyway.
                return 0;
            }

            $rows = $connection->select(
                'select count(*) as n from tenants where data ->> ? is not null',
                [self::MARKER],
            );

            return (int) ($rows[0]->n ?? 0);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The relations one pin reaches, as `model class => table`.
     *
     * The pin row's own `class` is the model for the `property` form; for the `constant` and `call` forms
     * the models are in `targets`. Anything in either slot that is not an Eloquent model — most often
     * `Illuminate\Support\Facades\DB`, i.e. a raw `DB::connection('central')` handle — contributes no
     * relation, and a pin left with none is reported as {@see NO_RELATION} rather than dropped.
     *
     * @param  array{class: string, file: string, line: int, form: string, targets: list<string>, citation: string|null, justified: bool}  $pin
     * @return array<string, string>
     */
    protected function relationsFor(array $pin): array
    {
        $relations = [];

        foreach ([$pin['class'], ...$pin['targets']] as $class) {
            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            try {
                $relations[$class] = (new $class)->getTable();
            } catch (\Throwable) {
                // A model whose constructor or getTable() needs a context this audit is not in. Skipping it
                // here is safe only because the pin is still named — as no-relation if nothing else resolved.
                continue;
            }
        }

        return $relations;
    }

    /**
     * @param  array{class: string, file: string, line: int, form: string, targets: list<string>, citation: string|null, justified: bool}  $pin
     * @param  array<string, int|null>  $tenants
     * @return array{class: string, file: string, line: int, form: string, model: string, relation: string, central: int|null, tenants: array<string, int|null>, verdict: string}
     */
    protected function row(array $pin, string $model, string $relation, ?int $central, array $tenants, string $verdict): array
    {
        return [
            'class' => $pin['class'],
            'file' => $pin['file'],
            'line' => $pin['line'],
            'form' => $pin['form'],
            'model' => $model,
            'relation' => $relation,
            'central' => $central,
            'tenants' => $tenants,
            'verdict' => $verdict,
        ];
    }

    /**
     * @param  array{class: string, file: string, line: int, form: string, model: string, relation: string, central: int|null, tenants: array<string, int|null>, verdict: string}  $row
     */
    protected function convergentDetail(array $row): string
    {
        return sprintf(
            '%s pins the [%s] connection (%s form) at %s:%d, and %s\'s relation [%s] resolves to the SAME '
            .'pg_class OID (%d) under the pinned frame [%s] and under every tenant frame — so the pin does '
            .'not currently change which physical relation is read or written. ⚠️ That is NOT a finding '
            .'that the pin is unnecessary, and this audit cannot make that finding. Two reasons: (1) it is '
            .'a snapshot — the same relation becomes divergent the moment a tenant migration creates it in '
            .'the tenant schemas, with no code change (measured on beam_calendar* between 2026-08-26 and '
            .'2026-09-01); (2) [%s] is a SEPARATE PDO handle, so an unpinned model joins the ambient '
            .'request transaction and a pinned one does not — identical relation is not identical '
            .'behaviour. Removing a pin needs a reason from CentralPinJustificationAudit, not from here.',
            $this->shortName($row['class']),
            CentralPinJustificationAudit::CENTRAL,
            $row['form'],
            $row['file'],
            $row['line'],
            $this->shortName($row['model']),
            $row['relation'],
            $row['central'],
            $this->centralFrame(),
            CentralPinJustificationAudit::CENTRAL,
        );
    }

    /**
     * @param  array{class: string, file: string, line: int, form: string, model: string, relation: string, central: int|null, tenants: array<string, int|null>, verdict: string}  $row
     */
    protected function unresolvableDetail(array $row): string
    {
        $ambient = array_values(array_filter($row['tenants'], fn (?int $oid) => $oid !== null));

        return sprintf(
            '%s pins the [%s] connection (%s form) at %s:%d, and %s\'s relation [%s] does NOT resolve under '
            .'the pinned frame [%s] — %s. A query through this pin raises `relation "%s" does not exist`. '
            .'Either the relation is missing from the pinned frame\'s schema at this host, or the model '
            .'should not be pinned; this audit cannot tell which, because which one is right depends on '
            .'what the host meant.',
            $this->shortName($row['class']),
            CentralPinJustificationAudit::CENTRAL,
            $row['form'],
            $row['file'],
            $row['line'],
            $this->shortName($row['model']),
            $row['relation'],
            $this->centralFrame(),
            $ambient === []
                ? 'and it does not resolve under any tenant frame either, so the relation appears to be '
                    .'absent from this database entirely'
                : sprintf('though it DOES resolve under the tenant frames (OID %d)', $ambient[0]),
            $row['relation'],
        );
    }

    /**
     * @param  array{class: string, file: string, line: int, form: string, model: string, relation: string, central: int|null, tenants: array<string, int|null>, verdict: string}  $row
     */
    protected function mixedDetail(array $row): string
    {
        $groups = [self::CONVERGENT => [], self::DIVERGENT => []];

        foreach ($row['tenants'] as $schema => $oid) {
            $groups[$oid === $row['central'] ? self::CONVERGENT : self::DIVERGENT][] = $schema;
        }

        return sprintf(
            '%s pins the [%s] connection (%s form) at %s:%d, and %s\'s relation [%s] answers DIFFERENTLY per '
            .'tenant against OID %d under the pinned frame [%s]: convergent (the tenant frame lands on the '
            .'pinned relation) in %s; divergent (the tenant frame has its own) in %s. The pin is therefore '
            .'inert for some tenants and load-bearing for others, which normally means a tenant migration is '
            .'part-way through its rollout. EVERY tenant schema was probed rather than one sampled — a '
            .'single-tenant sample would have reported one of these two groups as the whole answer.',
            $this->shortName($row['class']),
            CentralPinJustificationAudit::CENTRAL,
            $row['form'],
            $row['file'],
            $row['line'],
            $this->shortName($row['model']),
            $row['relation'],
            $row['central'],
            $this->centralFrame(),
            implode(', ', $groups[self::CONVERGENT]),
            implode(', ', $groups[self::DIVERGENT]),
        );
    }

    /**
     * The census. Names every pin in every verdict class — including the ones that warned — because a
     * reader needs the whole classification in one place, and because naming is the only thing that can
     * see what a cardinality cannot.
     *
     * @param  list<array{class: string, file: string, line: int, form: string, model: string, relation: string, central: int|null, tenants: array<string, int|null>, verdict: string}>  $rows
     */
    protected function censusDetail(array $rows): string
    {
        $by = [];

        foreach ($rows as $row) {
            $by[$row['verdict']][] = $row['relation'] === ''
                ? sprintf('%s (no model)', $this->shortName($row['class']))
                // ASCII arrow deliberately. A `→` here rendered correctly at `~/Herd/splicewire-app` and was
                // dropped entirely at `~/Herd/standwell` on 2026-09-01 — same file, same run shape — leaving
                // `CentralActivityLogactivity_log`. The census line is the one output that has to be legible
                // at every host, so it does not get to depend on a renderer's multibyte handling.
                : sprintf('%s -> %s', $this->shortName($row['class']), $row['relation']);
        }

        $order = [self::DIVERGENT, self::CONVERGENT, self::UNRESOLVABLE, self::MIXED, self::NO_RELATION];
        $parts = [];

        foreach ($order as $verdict) {
            if (isset($by[$verdict])) {
                $parts[] = sprintf('%s — %s', $verdict, implode(', ', $by[$verdict]));
            }
        }

        return sprintf(
            'Relation identity for %d pin/relation pair(s) of the [%s] connection, pinned frame [%s] '
            .'against %d tenant frame(s) (%s). %s. Verdicts are a snapshot of this database now, not a '
            .'property of the code.',
            count($rows),
            CentralPinJustificationAudit::CENTRAL,
            $this->centralFrame(),
            count($this->schemas()),
            implode(', ', $this->schemas()),
            implode('. ', $parts),
        );
    }

    /**
     * @param  list<array{class: string, file: string, line: int, form: string, targets: list<string>, citation: string|null, justified: bool}>  $pins
     */
    protected function namePins(array $pins): string
    {
        return implode(', ', array_map(
            fn (array $pin) => sprintf('%s (%s:%d)', $this->shortName($pin['class']), $pin['file'], $pin['line']),
            $pins,
        ));
    }

    protected function shortName(string $fqn): string
    {
        $pos = strrpos($fqn, '\\');

        return $pos === false ? $fqn : substr($fqn, $pos + 1);
    }
}
