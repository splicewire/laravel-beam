<?php

namespace Splicewire\Beam\Config\Overrides;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Rushing\Versioning\Contracts\VersionStore;
use Rushing\Versioning\Models\Version;
use Schemastud\DataSchemas\Migration\AcceptanceGate;
use Schemastud\DataSchemas\Overlay\JsonPathMutator;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Config\ConfigSectionSchema;
use Splicewire\Beam\Config\JsonPointer;
use Splicewire\Beam\Config\RegisteredConfigSection;
use Splicewire\Beam\Enums\ConfigMerge;
use Splicewire\Beam\Enums\ConfigOverrideOp;
use Splicewire\Beam\Enums\ConfigTier;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Realm\Contracts\TenancyContext;
use Throwable;

/**
 * The override store of one writable tier (ADR-0245 decision 2 and §9): the draft, the two pins, the versions and the
 * revisions of every section. It has its own direct publish, for a host without beam-ux; a Release joins in ticket 08.
 *
 * **Tiers (config-sections 05, 07).** The same shared tables hold both writable tiers, on different connections:
 * - the OPERATOR tier on `central` (ticket 05);
 * - the TENANT tier on the DEFAULT connection while a tenant is initialized, which is the tenant's own schema, pool
 *   (rows kept apart by its row-level security) or database (ticket 07). A tenant's history stays in its workspace and
 *   moves with it when it is relocated.
 * The tier is {@see forTier()}'s, or, unnamed, the current context's: the tenant tier while tenancy is active, the
 * operator tier otherwise ({@see tier()}). The runtime hooks always name theirs ({@see ConfigOverlay},
 * {@see ConfigTenantOverlay}); the `beam-config` ops act on the realm they run in.
 *
 * - {@see saveDraft()} applies `set` / `null` / `unset` ops to a section's draft. Each write is refused unless the
 *   key's ceiling admits the writing tier, its pointer is addressable, an `extend` key's entry is new, and the
 *   section's document with the draft applied validates against the section schema through the same
 *   {@see AcceptanceGate} a particle write uses. A tenant write is also refused, with the operator's reason, when an
 *   operator lock covers the pointer ({@see lock()}). Each op appends one {@see ConfigOverrideRevision}; the draft is
 *   saved as a new version and HEAD moves to it. A draft changes nothing the runtime applies.
 * - {@see publish()} moves the published pin to HEAD. An operator publish moves the published-version counter, so every
 *   process re-applies on its next request, job or command; a tenant publish needs no counter, because the tenant tier
 *   is read afresh on every tenant hop.
 * - {@see discardDraft()} puts the draft back to the published version and HEAD with it; nothing published changes.
 * - {@see restore()} rehydrates the draft from a version and saves it as a NEW version: a restore is a draft.
 */
class ConfigOverrideStore
{
    /** The tier this store writes; null is the current context's ({@see tier()}). */
    protected ?ConfigTier $tier = null;

    /**
     * An explicit connection the tier's rows are written through ({@see on()}), overriding {@see connectionName()}'s
     * tier default. config-sections 08: a Release publishes its config override through the Release's OWN connection, so
     * the pin and the Release's other pins move in one transaction on one PDO, even where a host's `central` alias is a
     * copy of the default (a second connection to the same database). The caller must pass a connection reaching the
     * same database as the tier's; the config_locks reads remain on `central` (central data).
     */
    protected ?string $connection = null;

    /**
     * The section schema and the acceptance gate are resolved on first VALIDATION, never at construction. The overlay
     * builds this store on a process's first sync ({@see ConfigOverlay::sync()}, at a command start, a request or a job),
     * and reading the published layers needs neither. Resolving them eagerly built the Schemastud `Generator` chain in
     * every process, including boots that register only some providers, where `Generator` (an interface) has no binding.
     */
    public function __construct(
        protected ConfigSectionRegistry $sections,
        protected Container $container,
        protected VersionStore $versions,
        protected ConfigOverrideCounter $counter,
    ) {
        $this->tenantTargets = new \WeakMap;
    }

    /**
     * {@see tenantTargetProblem()}'s answers for the current tenant frame: keyed by the live PDO OBJECT (a WeakMap, so an
     * entry dies with its PDO and a later PDO can never inherit it, as a reused spl_object_id could), then by connection
     * and tenant. An object, so every {@see forTier()} clone shares it. Forgotten at each hop's start and end by
     * {@see ConfigTenantOverlay}, and on stancl's TenancyInitialized / TenancyEnded whether or not the host lists the
     * ladder bootstrapper ({@see \Splicewire\Beam\BeamServiceProvider}).
     *
     * @var \WeakMap<object, array<string, string|false>>
     */
    protected \WeakMap $tenantTargets;

    /** Forget the cached tenant-target answers (a tenant hop starts or ends). */
    public function forgetTenantTargets(): void
    {
        $pdos = [];

        foreach ($this->tenantTargets as $pdo => $answers) {
            $pdos[] = $pdo;
        }

        foreach ($pdos as $pdo) {
            unset($this->tenantTargets[$pdo]);
        }
    }

    /** What the last {@see publish()} must tell the operator about propagation, or null when there is nothing to tell. */
    protected ?string $propagationNotice = null;

    public function propagationNotice(): ?string
    {
        return $this->propagationNotice;
    }

    /**
     * The fault-mode notice (root ruling (A), row 64e24681): a publish whose token could not be written to the cache
     * reaches other processes within `recheck_seconds` instead of on their next request, job or command.
     */
    public static function cacheWriteFailedNotice(): string
    {
        return sprintf('published; propagation to other processes may take up to %d s (cache write failed)', ConfigOverlay::recheckSeconds());
    }

    /** This store, writing `$tier` (Operator or Tenant). */
    public function forTier(ConfigTier $tier): static
    {
        if (! in_array($tier, [ConfigTier::Operator, ConfigTier::Tenant], true)) {
            throw new InvalidArgumentException("The {$tier->value} tier has no override store; only the operator and tenant tiers are written.");
        }

        $store = clone $this;
        $store->tier = $tier;
        $store->propagationNotice = null;

        return $store;
    }

    /**
     * This store, writing the tier's rows through `$connection` instead of {@see connectionName()}'s tier default
     * (config-sections 08). The connection must reach the same database as the tier's — a Release publishes its config
     * override through the Release's own connection so both pins move in one transaction.
     */
    public function on(string $connection): static
    {
        $store = clone $this;
        $store->connection = $connection;
        $store->propagationNotice = null;
        // The explicit connection becomes connectionName(), so the tenant-target guard and the writes check the SAME
        // connection. Guard it now (fail fast): on the tenant tier a write may only target the tenant's OWN isolated
        // store, never central and never a schema lacking the table (config-sections 07 guard).
        $store->guardTenantTarget($connection);

        return $store;
    }

    /** The tier this store writes: the one {@see forTier()} named, else the tenant tier while tenancy is active. */
    public function tier(): ConfigTier
    {
        return $this->tier ?? (app(TenancyContext::class)->isActive() ? ConfigTier::Tenant : ConfigTier::Operator);
    }

    /**
     * The connection this tier's rows live on, resolved on every call: `central` for the operator, the current DEFAULT
     * connection for a tenant (the tenant's own while its tenancy is initialized).
     */
    public function connectionName(): string
    {
        return $this->connection ?? ($this->tier() === ConfigTier::Operator ? 'central' : DB::getDefaultConnection());
    }

    /** @return \Illuminate\Database\Eloquent\Builder<ConfigOverrideScope> */
    protected function scopes(): \Illuminate\Database\Eloquent\Builder
    {
        return ConfigOverrideScope::on($this->connectionName());
    }

    /** @return \Illuminate\Database\Eloquent\Builder<ConfigOverrideRevision> */
    protected function revisions(): \Illuminate\Database\Eloquent\Builder
    {
        return ConfigOverrideRevision::on($this->connectionName());
    }

    protected function schemas(): ConfigSectionSchema
    {
        return $this->container->make(ConfigSectionSchema::class);
    }

    protected function gate(): AcceptanceGate
    {
        return $this->container->make(AcceptanceGate::class);
    }

    /**
     * @param  list<array{pointer: string, op: string|ConfigOverrideOp, value?: mixed}>  $ops
     */
    public function saveDraft(string $root, array $ops, ?string $actor = null): ConfigOverrideScope
    {
        $registered = $this->registered($root);
        $this->guardTenantTarget();
        $normalized = $this->normalize($registered, $ops, $this->base($root));
        $tier = $this->tier()->value;

        return DB::connection($this->connectionName())->transaction(function () use ($registered, $root, $normalized, $actor, $tier): ConfigOverrideScope {
            $scope = $this->lockedScope($root);
            $draft = $scope->draft ?? [];
            $base = $this->base($root);
            $now = Carbon::now()->toIso8601String();
            $pending = [];

            foreach ($normalized as $i => [$pointer, $op, $value, $secret, $merge, $via, $inherit]) {
                // A parent op replaces what its earlier set split out: drop the secret children derived from it, and
                // (config-sections 20 r3) every split child BELOW it, by pointer prefix. A secret split out of a list
                // item is addressed by its index, and a list index is not a stable identity: kept past a later set of
                // the list or of any ancestor, it would re-apply at that position to whatever record now sits there.
                if ($via === null) {
                    $draft = array_filter(
                        $draft,
                        fn (array $entry, string|int $at): bool => ($entry['via'] ?? null) === null
                            || ($entry['via'] !== $pointer && ! str_starts_with((string) $at, $pointer.'/')),
                        ARRAY_FILTER_USE_BOTH,
                    );
                }

                if ($merge === ConfigMerge::Extend && $op === ConfigOverrideOp::Set) {
                    [$exists] = JsonPointer::get($base, $pointer);

                    if ($exists && ! isset($draft[$pointer])) {
                        throw ConfigOverrideRefusal::because("ops.{$i}.pointer", "{$pointer} is an extend key, and its entry already exists in a tier below the {$tier}; an override may add entries, never replace one.");
                    }
                }

                if ($op === ConfigOverrideOp::Unset) {
                    unset($draft[$pointer]);
                } else {
                    $draft[$pointer] = ['op' => $op->value, 'secret' => $secret, 'set' => $op === ConfigOverrideOp::Set, 'setAt' => $now]
                        + ($secret || $op !== ConfigOverrideOp::Set ? [] : ['value' => $value])
                        + ($via === null ? [] : ['via' => $via])
                        + ($inherit === [] ? [] : ['inherit' => $inherit]);
                }

                $pending[] = [$pointer, $op, $value, $secret];
            }

            $schema = $this->schemas()->for($registered->section);
            $candidate = $this->governed($this->document($base, $draft, $pending), $schema);

            if (! $this->gate()->accepts($candidate, $schema)) {
                throw ConfigOverrideRefusal::because('ops', "The draft for {$root} does not validate against its section schema.");
            }

            $revision = (int) $this->revisions()->where('section', $root)->max('revision');

            foreach ($pending as [$pointer, $op, $value, $secret]) {
                $row = $this->revisions()->create([
                    'section' => $root,
                    'pointer' => $pointer,
                    'op' => $op,
                    'value' => $secret || $op !== ConfigOverrideOp::Set ? null : $value,
                    'encrypted_value' => $secret && $op === ConfigOverrideOp::Set ? json_encode($value) : null,
                    'revision' => ++$revision,
                    'created_by' => $actor,
                ]);

                if ($op !== ConfigOverrideOp::Unset) {
                    $draft[$pointer]['revision'] = $row->id;
                }
            }

            $scope->draft = $draft;
            $scope->save();
            $scope->snapshotVersion();

            return $scope->refresh();
        });
    }

    /** Move the published pin to HEAD; a no-op when nothing is pending. */
    public function publish(string $root): ConfigOverrideScope
    {
        $this->registered($root);
        $this->guardTenantTarget();
        $this->propagationNotice = null;

        $moved = false;

        $scope = DB::connection($this->connectionName())->transaction(function () use ($root, &$moved): ConfigOverrideScope {
            $scope = $this->lockedScope($root);

            if ($scope->head_version === null) {
                throw ConfigOverrideRefusal::because('root', "{$root} has no saved draft to publish.");
            }

            if ($scope->published_version !== $scope->head_version) {
                $scope->published_version = $scope->head_version;
                $scope->save();
                $moved = true;
            }

            return $scope;
        });

        if ($moved) {
            // config-sections 08: announce on the OUTERMOST commit, not synchronously after this inner transaction. When
            // a Release calls this inside its own transaction, a later rollback of that outer transaction reverts the pin,
            // and the counter token must NOT have moved; afterCommit fires at level 0 (immediately when no transaction is
            // open, so the standalone path is unchanged) and is skipped on rollback.
            DB::connection($this->connectionName())->afterCommit(fn () => $this->announce($root));
        }

        return $scope->refresh();
    }

    /**
     * Move the PUBLISHED pin directly to an existing version, or to null — no published layer, so the override is
     * removed (config-sections 08: a Release rollback reverts the config pin to its previous value, which may be null).
     * Unlike {@see publish()} this does NOT touch the draft and does NOT mint a new version; it only moves the pin, so an
     * operator's in-flight draft survives. The published token re-derives from the pins on its next read.
     */
    public function publishVersion(string $root, ?string $version): ConfigOverrideScope
    {
        $this->registered($root);
        // Like publish()/saveDraft(): a tenant-tier pin move may only run against the tenant's OWN isolated store, inside
        // its tenant hop. A tenant Release publishes within the tenant's request, so this passes; an operator publish is
        // a no-op here.
        $this->guardTenantTarget();
        $this->propagationNotice = null;

        $moved = false;

        $scope = DB::connection($this->connectionName())->transaction(function () use ($root, $version, &$moved): ConfigOverrideScope {
            $scope = $this->lockedScope($root);
            if ($version !== null && $this->versions->version($scope, $version) === null) {
                throw ConfigOverrideRefusal::because('version', "{$root} has no version {$version}.");
            }
            if ($scope->published_version !== $version) {
                $scope->published_version = $version;
                $scope->save();
                $moved = true;
            }

            return $scope;
        });

        if ($moved) {
            // config-sections 08: announce on the OUTERMOST commit (see publish()) so a Release's outer rollback leaves
            // the counter token unmoved along with the reverted pin.
            DB::connection($this->connectionName())->afterCommit(fn () => $this->announce($root));
        }

        return $scope->refresh();
    }

    /**
     * A fingerprint of the published state: every section's published pin, from one query on the scopes table. Equal
     * state gives an equal token; any publish moves a pin, and a restore always makes a NEW version, so a pin never
     * returns to an earlier value and a token is never reused. Compared for equality only.
     */
    public function publishedToken(): string
    {
        if (! $this->hasStore()) {
            return 'none';
        }

        $pins = $this->scopes()->whereNotNull('published_version')->orderBy('section')
            ->pluck('published_version', 'section')->all();

        return $pins === [] ? 'none' : sha1(json_encode($pins, JSON_THROW_ON_ERROR));
    }

    /**
     * Whether this tier's connection has the store's tables (a host or tenant that has not run the shared migration has
     * not). The tenant tier asks {@see tenantTargetProblem()}: its OWN schema, schema-qualified, so a table found only
     * through a `<tenant schema>,public` search path's fall-through (central's) never counts as the tenant's.
     */
    protected function hasStore(): bool
    {
        return $this->tier() === ConfigTier::Tenant
            ? $this->tenantTargetProblem() === null
            : Schema::connection($this->connectionName())->hasTable(Beam::table('config_scopes'));
    }

    /**
     * Refuse a TENANT-tier write that has nowhere safe to go (root ab22fd59), with {@see tenantTargetProblem()}'s reason;
     * nothing is written. Called before every tenant-tier write (saveDraft, publish, discardDraft, restore), and public so
     * a caller that writes on a connection it names (08's `on()`) asks it of THAT connection, the one it writes on.
     *
     * @param  string|null  $connection  the connection the write will use; null means this store's own
     */
    public function guardTenantTarget(?string $connection = null): void
    {
        $problem = $this->tenantTargetProblem($connection);

        if ($problem !== null) {
            throw ConfigOverrideRefusal::because('tier', "A tenant-tier config write was refused and nothing was written: {$problem}. Run `php artisan tenants:migrate` (and `php artisan pools:migrate` for pooled tenants) so each tenant's own storage has the shared config tables.");
        }
    }

    /**
     * Why this store's TENANT tier has no safe storage on `$connection`, or null when it has (and always null for the
     * operator tier). Every tenant-tier read and write asks it: a write is refused ({@see guardTenantTarget()}), and a
     * read finds nothing ({@see hasStore()}, {@see scope()}), so central's rows are never served or written as a tenant's.
     * It answers with a reason unless all three hold:
     * - tenancy is active: outside a tenant the default connection is central's;
     * - the target is not central's: neither the `central` connection nor one whose LIVE session is central's (the same
     *   server, database and current schema on Postgres; the same file on SQLite), so a `url`, a host alias or a port
     *   spelled on one side only cannot hide it;
     * - the store's three tables (config_scopes, config_overrides and versions, which a separate migration creates) exist
     *   in the session's OWN current schema, schema-qualified, never through the `,public` fall-through every tenant
     *   schema and pool carries. Otherwise the store's unqualified queries resolve to central's OPERATOR tables, keyed by
     *   section with no tier column.
     * Known limit: a concurrent DROP between this check and the write (DDL racing a request) is not covered.
     */
    public function tenantTargetProblem(?string $connection = null): ?string
    {
        if ($this->tier() !== ConfigTier::Tenant) {
            return null;
        }

        $connection ??= $this->connectionName();

        if (! $this->tenancyActive()) {
            return 'no tenant is active, so the tenant tier has no storage of its own';
        }

        if ($connection === 'central') {
            return "the tenant's connection `{$connection}` resolves to central's own database and schema";
        }

        // Asked on every tenant read and write: cached for the tenant frame, per connection, live PDO and tenant (review
        // 05:40Z), so a hop pays its session and table queries once.
        $pdo = DB::connection($connection)->getPdo();
        $key = $connection.'|'.$this->currentTenantKey();
        $answers = $this->tenantTargets[$pdo] ?? [];

        if (array_key_exists($key, $answers)) {
            return $answers[$key] === false ? null : $answers[$key];
        }

        $problem = null;

        if ($this->sameTarget($connection, 'central')) {
            $problem = "the tenant's connection `{$connection}` resolves to central's own database and schema";
        } else {
            [$schema, $missing] = $this->missingOwnTables($connection);

            if ($missing !== []) {
                $problem = "the tenant's own schema".($schema === null ? '' : " `{$schema}`")." lacks the store's tables `".implode('`, `', $missing).'` (a table reachable only through the `,public` search path is central\'s)';
            }
        }

        $answers[$key] = $problem ?? false;
        $this->tenantTargets[$pdo] = $answers;

        return $problem;
    }

    protected function currentTenantKey(): string
    {
        try {
            return (string) app(TenancyContext::class)->currentTenantId();
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * The store's tables `$connection`'s live session does not hold in its OWN current schema (a file database: itself).
     *
     * @return array{0: string|null, 1: list<string>} the schema asked, and the missing tables
     */
    protected function missingOwnTables(string $connection): array
    {
        $db = DB::connection($connection);
        $schema = $db->getDriverName() === 'pgsql' ? (string) $db->selectOne('select current_schema() as s')->s : null;
        $missing = [];

        foreach (['config_scopes', 'config_overrides', 'versions'] as $name) {
            $table = Beam::table($name);

            if (! Schema::connection($connection)->hasTable($schema === null ? $table : "{$schema}.{$table}")) {
                $missing[] = $table;
            }
        }

        return [$schema, $missing];
    }

    /**
     * Whether two connections' LIVE sessions reach the same storage. On Postgres: the same current database AND current
     * schema. The server address is deliberately NOT compared (review 05:40Z): one server reached two ways (a unix socket
     * answers NULL, `localhost` may be ::1 or 127.0.0.1, a pooler sits on one side) would differ there, and a tenant whose
     * schema was never created (its current schema falls back to `public`) would pass as its own. The only false match,
     * an isolated tenant on another server whose database is named like central's and whose session sits in `public`,
     * fails closed. On SQLite the same file; otherwise the same parsed host, port and database.
     */
    protected function sameTarget(string $connection, string $other): bool
    {
        $a = DB::connection($connection);
        $b = DB::connection($other);

        if ($a->getDriverName() !== $b->getDriverName()) {
            return false;
        }

        if ($a->getDriverName() === 'pgsql') {
            return $this->pgSession($connection) === $this->pgSession($other);
        }

        if ($a->getDriverName() === 'sqlite') {
            $file = function (\Illuminate\Database\Connection $connection): ?string {
                $database = $connection->getConfig('database');

                return is_string($database) && $database !== '' && $database !== ':memory:' ? (realpath($database) ?: $database) : null;
            };

            return $file($a) !== null && $file($a) === $file($b);
        }

        foreach (['host', 'port', 'database'] as $key) {
            if ((string) json_encode($a->getConfig($key)) !== (string) json_encode($b->getConfig($key))) {
                return false;
            }
        }

        return true;
    }

    /**
     * A Postgres connection's live storage identity: its current database and current schema (nothing about how it
     * reached the server). Its own seam so the identity can be exercised without two servers.
     *
     * @return array{0: string, 1: string}
     */
    protected function pgSession(string $connection): array
    {
        $row = DB::connection($connection)->selectOne('select current_database() as d, current_schema() as s');

        return [(string) $row->d, (string) $row->s];
    }

    protected function tenancyActive(): bool
    {
        try {
            return app(TenancyContext::class)->isActive();
        } catch (Throwable) {
            return false;
        }
    }

    /** Put the draft back to the published version (or to nothing, when none is published). */
    public function discardDraft(string $root): ConfigOverrideScope
    {
        $this->registered($root);
        $this->guardTenantTarget();

        return DB::connection($this->connectionName())->transaction(function () use ($root): ConfigOverrideScope {
            $scope = $this->lockedScope($root);
            $published = $scope->published_version === null ? null : $this->versions->version($scope, $scope->published_version);
            $scope->restoreVersionSnapshot($published->snapshot ?? ['draft' => []]);
            $scope->head_version = $scope->published_version;
            $scope->save();

            return $scope->refresh();
        });
    }

    /** Rehydrate the draft from a version and save it as a new version: a restore is a draft, never a publish. */
    public function restore(string $root, string $version): ConfigOverrideScope
    {
        $this->registered($root);
        $this->guardTenantTarget();

        return DB::connection($this->connectionName())->transaction(function () use ($root, $version): ConfigOverrideScope {
            $scope = $this->lockedScope($root);
            $target = $this->versions->version($scope, $version)
                ?? throw ConfigOverrideRefusal::because('version', "{$root} has no version {$version}.");
            $this->versions->restore($scope, $target->id);
            $scope->refresh()->snapshotVersion("restored from v{$target->version}");

            return $scope->refresh();
        });
    }

    /** @return list<Version> a section's versions, newest first */
    public function versionsOf(string $root): array
    {
        $scope = $this->scope($root);

        return $scope === null ? [] : array_values($this->versions->versions($scope)->all());
    }

    /** The section's scope on this tier, or null. A tenant tier with no safe storage has none (never central's). */
    public function scope(string $root): ?ConfigOverrideScope
    {
        if ($this->tier() === ConfigTier::Tenant && $this->tenantTargetProblem() !== null) {
            return null;
        }

        return $this->scopes()->where('section', $root)->first();
    }

    /** The version number a version id names, or null. */
    public function versionNumber(?ConfigOverrideScope $scope, ?string $id): ?int
    {
        return $scope === null || $id === null ? null : $this->versions->version($scope, $id)?->version;
    }

    /**
     * Every section's PUBLISHED layer, as the overlay applies it.
     *
     * @return array<string, array<string, ConfigOverrideEntry>> root => pointer => entry
     */
    public function publishedLayers(): array
    {
        // Before the host has run the shared migration there is nothing published, and that is not a failure: boot,
        // `migrate` and a fresh install read the package and host tiers without a warning.
        if (! $this->hasStore()) {
            return [];
        }

        $layers = [];

        foreach ($this->scopes()->whereNotNull('published_version')->get() as $scope) {
            $layers[$scope->section] = $this->publishedLayer($scope);
        }

        return $layers;
    }

    /** @return array<string, ConfigOverrideEntry> pointer => entry */
    public function publishedLayer(ConfigOverrideScope $scope): array
    {
        if ($scope->published_version === null) {
            return [];
        }

        // Through the version store, which reads on the scope's own connection (`central` for the operator). A bare
        // Version query would read the DEFAULT connection, which is the tenant's during a tenant request.
        $version = $this->versions->version($scope, $scope->published_version);
        $draft = $version?->snapshot['draft'] ?? [];

        return $this->entries(is_array($draft) ? $draft : [], $version?->version);
    }

    /**
     * @param  array<string, array<string, mixed>>  $draft
     * @return array<string, ConfigOverrideEntry>
     */
    protected function entries(array $draft, ?int $version): array
    {
        $secretIds = array_values(array_filter(array_map(
            fn (array $entry): ?string => ($entry['secret'] ?? false) && ($entry['op'] ?? null) === 'set' ? ($entry['revision'] ?? null) : null,
            $draft,
        )));
        $secrets = $secretIds === [] ? collect() : $this->revisions()->whereKey($secretIds)->get()->keyBy('id');
        $entries = [];

        foreach ($draft as $pointer => $entry) {
            $op = ConfigOverrideOp::from($entry['op']);
            $secret = (bool) ($entry['secret'] ?? false);
            $value = match (true) {
                $op !== ConfigOverrideOp::Set => null,
                $secret => $secrets->get($entry['revision'] ?? '')?->settingValue(),
                default => $entry['value'] ?? null,
            };
            $inherit = is_array($entry['inherit'] ?? null) ? array_values(array_map('strval', $entry['inherit'])) : [];
            $entries[(string) $pointer] = new ConfigOverrideEntry((string) $pointer, $op, $value, $secret, (string) ($entry['revision'] ?? ''), $version, $entry['setAt'] ?? null, $inherit);
        }

        ksort($entries);

        return $entries;
    }

    /**
     * Validate each op's shape and tier ceilings before any write, and split declared secrets out of object values.
     *
     * - **Ceilings at every depth.** An op is checked against the ceiling of every LEAF it touches, not only the
     *   pointer it names: a `set` of an object (or a `null` that clears one) is refused, naming the child pointer,
     *   when it would CHANGE a leaf whose ceiling does not admit the operator tier. A leaf that stays value-equal to
     *   its effective lower-tier value is allowed, so an operator may send a whole object back with its host-only
     *   children untouched. Those children are then taken OUT of the stored object and inherited from the tier below
     *   ({@see ConfigOverrideEntry::$inherit}), so the override never pins the host's value of the day.
     * - **Secrets at every depth.** A declared secret leaf inside an object `set` is taken out of the object and
     *   written as its own `set` at its own pointer, through the same encrypted path a direct secret write takes, so
     *   it never reaches a plain override value, a scope draft or a version snapshot. The derived op carries `via`
     *   (the parent pointer), so the parent's next op replaces it; the parent inherits the leaf, so unsetting the
     *   derived op falls back to the tier below. Except inside a LIST item (any list index in the absolute pointer): such a
     *   leaf is never inherited, so unsetting it leaves the item without the secret (config-sections 20 r5).
     *
     * @param  list<array<string, mixed>>  $ops
     * @param  array<array-key, mixed>  $base  the section root without the operator tier
     * @return list<array{0: string, 1: ConfigOverrideOp, 2: mixed, 3: bool, 4: ConfigMerge, 5: ?string, 6: list<string>}> 6 = the leaves the entry inherits
     */
    protected function normalize(RegisteredConfigSection $registered, array $ops, array $base): array
    {
        if ($ops === []) {
            throw ConfigOverrideRefusal::because('ops', 'A draft needs at least one op.');
        }

        $normalized = [];
        $locks = $this->tier() === ConfigTier::Tenant ? $this->locks($registered->root()) : [];

        foreach ($ops as $i => $op) {
            $pointer = $op['pointer'] ?? null;

            if (! is_string($pointer) || ! str_starts_with($pointer, '/') || $pointer === '/') {
                throw ConfigOverrideRefusal::because("ops.{$i}.pointer", 'A pointer is an RFC 6901 pointer below the section root, such as /releases/review_required.');
            }

            try {
                JsonPointer::toJsonPath($pointer);
            } catch (InvalidArgumentException $e) {
                throw ConfigOverrideRefusal::because("ops.{$i}.pointer", $e->getMessage());
            }

            $kind = $op['op'] ?? null;
            $kind = $kind instanceof ConfigOverrideOp ? $kind : (is_string($kind) ? ConfigOverrideOp::tryFrom($kind) : null)
                ?? throw ConfigOverrideRefusal::because("ops.{$i}.op", 'An op is set, null or unset.');

            $declared = $registered->section::keyDeclaration(JsonPointer::segments($pointer));

            // An `unset` of a pointer the operator has since locked is allowed: it only gives the key back to the
            // tiers below, which is what the lock asks for.
            if (($refused = $this->refusal($registered, $pointer, [], $locks)) !== null && ! ($kind === ConfigOverrideOp::Unset && $declared->ceiling->admits($this->tier()))) {
                throw ConfigOverrideRefusal::because("ops.{$i}.pointer", $refused);
            }

            $value = $kind === ConfigOverrideOp::Set ? ($op['value'] ?? null) : null;

            // config-sections 08 import: a mirror file records a parent set's INHERIT (the leaves it does NOT own) with
            // the owned value stripped of them. Refill those leaves from the base BEFORE the ceiling/host checks, so the
            // set is not seen as REMOVING an inherited host/locked child; hostOwnedOut below takes them back out and
            // re-derives the inherit, and the union keeps any the importing host would not itself strip.
            // config-sections 20 r5: an item of the op's own inherit that passes through a LIST index is IGNORED (filtered
            // once, here, before the refill): a list position is not a stable identity across tiers, so refilling it would
            // copy the tier below's record at that index (its secret then split out and stored as this op's own).
            $named = is_array($op['inherit'] ?? null)
                ? array_values(array_filter(array_map('strval', $op['inherit']), fn (string $rel): bool => ! $this->listPath($registered, $pointer.$rel)))
                : null;

            if ($kind === ConfigOverrideOp::Set && is_array($value) && $named !== null) {
                $value = ConfigOverrideEntry::fill($value, $pointer, $named, $base);
            }

            if ($kind !== ConfigOverrideOp::Unset) {
                $this->guardLeafCeilings($registered, $pointer, $value, $base, $i, $locks);
            }

            $derived = [];
            $inherit = [];

            if ($kind === ConfigOverrideOp::Set && is_array($value) && ! $declared->secret) {
                [$value, $inherit] = $this->hostOwnedOut($registered, $pointer, $value, $locks);
                [$value, $derived] = $this->splitSecrets($registered, $pointer, $value);

                foreach ($derived as [$child]) {
                    $relative = substr($child, strlen($pointer));

                    // config-sections 20 r4: a pointer through a LIST index never goes into inherit. A list index is not
                    // a stable identity across tiers either: unsetting that child would otherwise fill the slot from the
                    // tier below's list at the same index, another record's secret (the operator's, at the tenant tier).
                    // Removed, the item simply has no secret.
                    if (! $this->listPath($registered, $pointer.$relative)) {
                        $inherit[] = $relative;
                    }
                }

                // config-sections 08 import: a mirror file records the inherit (the leaves the set does NOT own). UNION it
                // onto what this host derives — a leaf the recording host owned/split but this host would not is still kept
                // as inherit (the imported value is already stripped of those leaves), so the stored entry reproduces
                // exactly. The owned value above went through the full host-owned/secret/ceiling checks first, so an
                // explicit `inherit` can only REDUCE ownership — a crafted file cannot use it to sneak a refused owned key
                // past the checks ({@see guardLeafCeilings}, which ran on the owned value).
                if ($named !== null) {
                    // Never a pointer through a list index (filtered above, on the full pointer), whoever names it.
                    $inherit = array_values(array_unique(array_merge($inherit, $named)));
                }
            }

            $normalized[] = [$pointer, $kind, $value, $declared->secret, $declared->merge, null, $inherit];

            foreach ($derived as [$child, $leaf, $merge]) {
                $normalized[] = [$child, ConfigOverrideOp::Set, $leaf, true, $merge, $pointer, []];
            }
        }

        return $normalized;
    }

    /**
     * Why the writing tier may not write `$pointer` (+ `$path` below it), or null when it may: the key's declared
     * ceiling does not admit the tier, or (tenant tier) an operator lock covers it, with the operator's reason.
     *
     * @param  list<string>  $path  below `$pointer`
     * @param  array<string, ConfigOverrideLock>  $locks  the section's locks (the tenant tier's guard only)
     */
    protected function refusal(RegisteredConfigSection $registered, string $pointer, array $path, array $locks): ?string
    {
        $full = $path === [] ? $pointer : $pointer.JsonPointer::from($path);
        $declared = $registered->section::keyDeclaration(JsonPointer::segments($full));
        $tier = $this->tier();

        if (! $declared->ceiling->admits($tier)) {
            return "{$full} has the ceiling {$declared->ceiling->value}; the {$tier->value} tier may not override it.";
        }

        foreach ($locks as $lock) {
            if ($lock->covers($full)) {
                return "{$full} is locked by the operator: {$lock->reason}";
            }
        }

        return null;
    }

    /**
     * Refuse an op that would change a leaf below `$pointer` the writing tier may not write (its ceiling, or an operator
     * lock). Compares the leaves of the base object and of the new value (a leaf missing from either side is a change).
     *
     * @param  array<array-key, mixed>  $base
     * @param  array<string, ConfigOverrideLock>  $locks
     */
    protected function guardLeafCeilings(RegisteredConfigSection $registered, string $pointer, mixed $value, array $base, int $i, array $locks = []): void
    {
        [$exists, $old] = JsonPointer::get($base, $pointer);
        $old = $exists && is_array($old) ? $old : [];
        $new = is_array($value) ? $value : [];

        if ($old === [] && $new === []) {
            return;
        }

        foreach (array_unique([...JsonPointer::leaves($old), ...JsonPointer::leaves($new)]) as $leaf) {
            $refused = $this->refusal($registered, $pointer, JsonPointer::segments($leaf), $locks);

            if ($refused === null) {
                continue;
            }

            if (json_encode(JsonPointer::get($old, $leaf)) !== json_encode(JsonPointer::get($new, $leaf))) {
                $declared = $registered->section::keyDeclaration([...JsonPointer::segments($pointer), ...JsonPointer::segments($leaf)]);
                $tier = $this->tier();

                throw ConfigOverrideRefusal::because("ops.{$i}.value", $declared->ceiling->admits($tier)
                    ? "{$refused} (inside a write to {$pointer})"
                    : "{$pointer}{$leaf} has the ceiling {$declared->ceiling->value}; the {$tier->value} tier may not change it, here inside a write to {$pointer}.");
            }
        }
    }

    /**
     * Take every key the writing tier may not write (its ceiling, or an operator lock) out of an object value, by
     * {@see ConfigTierBoundary::split()}, the same walk the tenant tier's runtime cuts with. {@see guardLeafCeilings()}
     * has already refused any change below such a key, so what is taken out is a value-equal resend, which the entry
     * INHERITS. The tenant tier treats an operator-locked key exactly as one above its ceiling: inherited, never pinned.
     *
     * @param  array<array-key, mixed>  $value
     * @param  array<string, ConfigOverrideLock>  $locks
     * @return array{0: array<array-key, mixed>, 1: list<string>} the object without them, and their relative pointers
     */
    protected function hostOwnedOut(RegisteredConfigSection $registered, string $pointer, array $value, array $locks = []): array
    {
        return ConfigTierBoundary::split($registered, $pointer, $value, $this->tier(), $locks);
    }

    /**
     * Take every declared secret leaf out of an object value.
     *
     * @param  array<array-key, mixed>  $value
     * @return array{0: array<array-key, mixed>, 1: list<array{0: string, 1: mixed, 2: ConfigMerge}>} the object without them, and [pointer, value, merge] per secret leaf
     */
    protected function splitSecrets(RegisteredConfigSection $registered, string $pointer, array $value): array
    {
        $derived = [];

        foreach ($this->secretCandidates($registered, $pointer, $value) as $leaf) {
            $segments = JsonPointer::segments($leaf);
            $declared = $registered->section::keyDeclaration([...JsonPointer::segments($pointer), ...$segments]);

            if (! $declared->secret) {
                continue;
            }

            [, $secret] = JsonPointer::get($value, $leaf);
            $derived[] = [$pointer.$leaf, $secret, $declared->merge];
            $this->forgetAt($value, $segments);
        }

        return [$value, $derived];
    }

    /**
     * Whether an ABSOLUTE pointer (from the section root, the op's own pointer included) passes through a LIST index, as
     * the section's declaration says ({@see ConfigSection::throughListIndex()}; config-sections 20 r5). A list index is
     * not a stable identity, so a pointer through one is never inherited from the tier below. A `#[MapValues]` key, even
     * an all-digit one, is a stable name and inherits normally; an undeclared shape falls back to "any all-digit
     * segment is an index", the safe direction.
     */
    private function listPath(RegisteredConfigSection $registered, string $pointer): bool
    {
        return $registered->section::throughListIndex(JsonPointer::segments($pointer));
    }

    /**
     * The relative pointers {@see splitSecrets()} asks about: every leaf of `$value` ({@see JsonPointer::leaves()}), and,
     * where a leaf is a non-empty LIST below a key that is not itself secret, the leaves of each of its records under an
     * index segment, at any depth (config-sections 20 r3). `leaves()` treats a list as one value, which the ceiling and
     * boundary walks need; secrecy cannot, or a declared secret inside a list of records BELOW the pointer written (a list
     * in a nested Data, a map entry or a union member) stayed in the owned value in plain. A list declared secret itself
     * stays one leaf and is split whole, as before.
     *
     * @param  array<array-key, mixed>  $value
     * @return list<string>
     */
    private function secretCandidates(RegisteredConfigSection $registered, string $pointer, array $value, string $prefix = ''): array
    {
        $candidates = [];

        foreach (JsonPointer::leaves($value) as $leaf) {
            [, $item] = JsonPointer::get($value, $leaf);

            if (is_array($item) && $item !== [] && array_is_list($item)
                && ! $registered->section::keyDeclaration(JsonPointer::segments($pointer.$prefix.$leaf))->secret) {
                foreach ($item as $index => $element) {
                    if (is_array($element) && $element !== []) {
                        array_push($candidates, ...$this->secretCandidates($registered, $pointer, $element, $prefix.$leaf.'/'.$index));
                    }
                }

                continue;
            }

            $candidates[] = $prefix.$leaf;
        }

        return $candidates;
    }

    /**
     * @param  array<array-key, mixed>  $doc
     * @param  list<string>  $segments
     */
    protected function forgetAt(array &$doc, array $segments): void
    {
        $key = array_shift($segments);

        if (! array_key_exists($key, $doc)) {
            return;
        }

        if ($segments === []) {
            unset($doc[$key]);
        } elseif (is_array($doc[$key])) {
            $this->forgetAt($doc[$key], $segments);
        }
    }

    /**
     * The section document the draft would produce: the base with every draft entry applied in pointer order.
     *
     * @param  array<array-key, mixed>  $base
     * @param  array<string, array<string, mixed>>  $draft
     * @param  list<array{0: string, 1: ConfigOverrideOp, 2: mixed, 3: bool}>  $pending  this save's ops, values in hand
     * @return array<array-key, mixed>
     */
    protected function document(array $base, array $draft, array $pending): array
    {
        $values = [];

        foreach ($pending as [$pointer, $op, $value]) {
            $values[$pointer] = $value;
        }

        $known = $this->entries(array_diff_key($draft, $values), null);
        $doc = $base;
        $mutator = new JsonPathMutator;
        ksort($draft);

        foreach ($draft as $pointer => $entry) {
            $value = array_key_exists($pointer, $values) ? $values[$pointer] : ($known[$pointer]->value ?? null);
            $value = ConfigOverrideEntry::fill($value, (string) $pointer, is_array($entry['inherit'] ?? null) ? array_values(array_map('strval', $entry['inherit'])) : [], $doc);
            $mutator->override($doc, JsonPointer::toJsonPath((string) $pointer), $entry['op'] === 'null' ? null : $value);
        }

        return $doc;
    }

    /**
     * The part of a section's document its schema governs. A record-shaped section describes the keys it declares;
     * every other key of the root is undeclared, deploy-only and not overridable (ADR-0245 §Consequences), so it is not
     * validated against a schema that does not describe it. A map-shaped root is governed whole.
     *
     * @param  array<array-key, mixed>  $doc
     * @param  array<string, mixed>  $schema
     * @return array<array-key, mixed>
     */
    protected function governed(array $doc, array $schema): array
    {
        $properties = $schema['properties'] ?? null;

        return is_array($properties) && $properties !== [] ? array_intersect_key($doc, $properties) : $doc;
    }

    /**
     * The section root the writing tier overrides: without the operator tier for the operator (the overlay's base), and
     * the CENTRAL effective value (the operator tier included, no tenant tier) for a tenant.
     *
     * @return array<array-key, mixed>
     */
    protected function base(string $root): array
    {
        $base = $this->tier() === ConfigTier::Operator
            ? app(ConfigOverlay::class)->base($root)
            : app(ConfigTenantOverlay::class)->central($root);

        return is_array($base) ? $base : [];
    }

    protected function lockedScope(string $root): ConfigOverrideScope
    {
        $scope = $this->scopes()->where('section', $root)->lockForUpdate()->first();

        return $scope ?? $this->scopes()->create(['section' => $root, 'draft' => []]);
    }

    /**
     * The operator's locks on a section, pointer-keyed, from `central` (operator locks are central data, whatever tier
     * asks). No locks table yet reads as no locks.
     *
     * @return array<string, ConfigOverrideLock>
     */
    public function locks(string $root): array
    {
        if (! Schema::connection('central')->hasTable(Beam::table('config_locks'))) {
            return [];
        }

        return ConfigOverrideLock::query()->where('section', $root)->orderBy('pointer')->get()->keyBy('pointer')->all();
    }

    /**
     * Lock `$pointer` of a section for the tenant tier, with the reason a tenant is shown (operator only). Locking a
     * pointer already locked replaces its reason. A key whose declared ceiling already excludes the tenant tier has
     * nothing to lock and is refused, so a lock always names something a tenant could otherwise change.
     */
    public function lock(string $root, string $pointer, string $reason, ?string $actor = null): ConfigOverrideLock
    {
        $registered = $this->registered($root);
        $this->guardOperatorOnly('lock');
        $this->guardPointer($pointer, 'pointer');

        if (trim($reason) === '') {
            throw ConfigOverrideRefusal::because('reason', 'A lock needs a reason; the tenant is shown it beside the locked key.');
        }

        $declared = $registered->section::keyDeclaration(JsonPointer::segments($pointer));

        if (! $declared->ceiling->admits(ConfigTier::Tenant) && ! $this->tenantWritableBelow($registered, $pointer)) {
            throw ConfigOverrideRefusal::because('pointer', "{$pointer} has the ceiling {$declared->ceiling->value}; a tenant cannot override it, so there is nothing to lock.");
        }

        return ConfigOverrideLock::query()->updateOrCreate(
            ['section' => $root, 'pointer' => $pointer],
            ['reason' => trim($reason), 'locked_by' => $actor],
        );
    }

    /** Remove the lock on `$pointer` of a section (operator only); a pointer with no lock is refused. */
    public function unlock(string $root, string $pointer): void
    {
        $this->registered($root);
        $this->guardOperatorOnly('unlock');

        $deleted = ConfigOverrideLock::query()->where('section', $root)->where('pointer', $pointer)->delete();

        if ($deleted === 0) {
            throw ConfigOverrideRefusal::because('pointer', "{$pointer} of {$root} is not locked.");
        }
    }

    protected function guardOperatorOnly(string $verb): void
    {
        if ($this->tier() !== ConfigTier::Operator) {
            throw ConfigOverrideRefusal::because('root', "Only the operator may {$verb} a key; a tenant cannot.");
        }
    }

    protected function guardPointer(mixed $pointer, string $field): void
    {
        if (! is_string($pointer) || ! str_starts_with($pointer, '/') || $pointer === '/') {
            throw ConfigOverrideRefusal::because($field, 'A pointer is an RFC 6901 pointer below the section root, such as /releases/review_required.');
        }

        try {
            JsonPointer::toJsonPath($pointer);
        } catch (InvalidArgumentException $e) {
            throw ConfigOverrideRefusal::because($field, $e->getMessage());
        }
    }

    /**
     * Whether any leaf the configuration holds below `$pointer` admits the tenant tier by its nearest declaration (a
     * record whose own ceiling excludes tenants but which declares a tenant-writable child).
     */
    protected function tenantWritableBelow(RegisteredConfigSection $registered, string $pointer): bool
    {
        [$exists, $value] = JsonPointer::get($this->operatorBase($registered->root()), $pointer);

        return $exists && is_array($value)
            && ConfigTierBoundary::admitsBelow($registered, JsonPointer::segments($pointer), $value, ConfigTier::Tenant);
    }

    /** @return array<array-key, mixed> */
    protected function operatorBase(string $root): array
    {
        $base = app(ConfigOverlay::class)->base($root);

        return is_array($base) ? $base : [];
    }

    protected function registered(string $root): RegisteredConfigSection
    {
        return $this->sections->get($root) ?? throw ConfigOverrideRefusal::because('root', "No config section is registered for {$root}.");
    }

    /**
     * After a publish COMMITS: put the new published token in the cache, and apply the publish in THIS process at once,
     * whatever the cache does. A cache write that fails never undoes or hides the publish: other warm processes catch up
     * within `beam.core.config_overrides.recheck_seconds` ({@see ConfigOverlay::sync()}).
     */
    protected function announce(string $root): void
    {
        // A tenant publish needs no counter: every tenant hop reads the tenant tier afresh. This process's own hop
        // re-applies at once.
        if ($this->tier() === ConfigTier::Tenant) {
            app(ConfigTenantOverlay::class)->refresh();

            return;
        }

        $overlay = app(ConfigOverlay::class);

        try {
            $this->counter->put($this->publishedToken());
        } catch (Throwable $e) {
            $this->propagationNotice = self::cacheWriteFailedNotice();
            Log::warning($this->propagationNotice, [
                'root' => $root,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }

        $overlay->recheckNow();
        $overlay->sync();
    }
}
