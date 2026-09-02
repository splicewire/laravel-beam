<?php

namespace Splicewire\Beam\Surgeon;

use Closure;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Doctor\FrameManifestAudit;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * A framed resource that belongs to **no realm** is registered and unreachable: it is in the registry,
 * it projects a `ResourceDefinition` perfectly well, and {@see ParticleResourceRegistry::definitions()}
 * filters it out of **every** realm — because that filter is `in_array($realm, realmsFor($key))` and an
 * empty membership list intersects nothing. There is no realm you can ask for that returns it.
 *
 * ## Why counting could never find this
 *
 * {@see FrameManifestAudit} is the instrument that should have caught it and structurally cannot. It
 * resolves the registry, calls `all()`, and reports `"frame manifest resolves (N resources)"` — a
 * cardinality. N is right. N was right on 2026-08-26 at `~/Herd/splicewire-app` while **16 of the 36
 * framed resources there reached no realm at all**, and a bigger N is exactly what you get by adding
 * another one. A count cannot see membership; only naming the keys can. So this audit names them.
 *
 * ## It is scoped to FRAMED resources, deliberately
 *
 * {@see ParticleResourceRegistry::keysForRealm()} has no `isFramed()` filter, and its docblock argues
 * correctly that membership is not a manifest concept. But the *defect* here is specifically that
 * `definitions($realm)` drops the resource, and `definitions()` is the only thing in beam that filters
 * by realm. A REST-only resource is served by its mounted route whatever its membership, so including
 * one here would double the row count with a different consequence silently attached to it.
 *
 * ## The skip branch is what keeps this from being noise, and it is a measurement
 *
 * Swept across the 20 bootable `~/Herd` roots on 2026-08-30: **`~/Herd/splicewire-app` is the only host
 * in the estate that declares `frame.realms` at all.** Every other root has an empty map, so every
 * framed resource it registers is unrealmed — `~/Herd/tower` would report 33 rows, `~/Herd/beam` 12 —
 * and none of it means anything, because those hosts never call `definitions($realm)`; they take the
 * `$realm === null` whole-catalog projection. A host that declares no membership on either rung has
 * nothing for a resource to be *outside of*.
 *
 * So the population gate is "does ANY registered resource have a realm here", computed from
 * {@see ParticleResourceRegistry::realmsFor()} — the declared authority — rather than re-derived from
 * `config('frame.realms')`, which is only one of its two rungs. When the answer is no, this reports a
 * Pass that NAMES the empty population, per {@see DoctorAudit}'s obligation.
 *
 * ## The inverse: a realm naming a resource nothing registered
 *
 * `keysForRealm()` returns the INTERSECTION of the map with what is registered — deliberately, so a
 * host's typo cannot admit a key the registry could not serve. The cost is that the typo is then
 * completely invisible: the key is silently dropped and nothing anywhere says so. That is reported here
 * as a **separate** check ({@see CHECK_UNREGISTERED}), because it is a different defect with a different
 * repair — a config line to delete or spell correctly, versus a resource to give a realm.
 *
 * Zero live instances across the estate on 2026-08-30 (the flagship's 24 mapped keys all resolve). That
 * is the argument FOR the check, not against it, and it is the same argument
 * {@see ListedResourceDisplacementAudit} makes for its own `resource.listing.displaced` verdict: a
 * defect with no current instance needs an instrument rather than a memory.
 *
 * Only the `realm-map` rung can produce one. The `explicit` rung takes its key from the
 * `ParticleResource` being registered, so an explicitly-realmed key is a registered key by construction.
 *
 * ## Advisory, permanently
 *
 * Whether a resource is realmed HERE is a fact about the host — which realms it declares, which packages
 * it composes, and whether it uses the realm axis at all — which is `rushing/laravel-doctor`'s textbook
 * advisory case. Nothing here is grammar the declaration's author could have gotten right without
 * knowing which host would load it: the same `#[ParticleResource]` in `laravel-beam-ux` is unrealmed at
 * the flagship and unrealmable at `~/Herd/tower`, which declares no realms to join. The estate bought
 * this rule with an outage — an event catalog that threw at boot on an unregistered resource prefix,
 * true at the flagship and false at tower, where it meant the host could not boot at all.
 *
 * Everything is computed **on read**. Nothing is stamped at registration, so a resource registered after
 * beam's own boot — a consumer package's provider, a host's `AppServiceProvider` — clears its own
 * finding instead of having load order recorded as truth about it.
 *
 * ## The second fact: a registration whose table exists nowhere (api-surface-coherence 139)
 *
 * Measured 2026-09-01 at `~/Herd/splicewire-app`: of 18 registered resources with no routed presence,
 * three — `teams`, `access-grants`, `view-requests` — declare a model whose table exists on **no**
 * connection and in **no** schema at that host (`beam_teams`, `beam_access_grants`, `beam_view_requests`;
 * the host runs its own team system and declares the beam-accounts estate `'absent'`). They are not
 * unrealmed-by-design and not awaiting a mount; they are **dead registrations**, and any route or realm
 * that ever serves them answers with a query error. The ticket considered a "deliberately API-less"
 * declaration and rejected it precisely for these three: a flag would have let someone stamp them
 * intentional and close over the defect. Whether a table exists is a fact nobody declares, so the
 * instrument is a probe of the backing, and this is where it lives — the same registry walk, one fact
 * further in.
 *
 * Its population is **every registration that names a model, framed or not** — two of the three live
 * instances are REST-only, which the realm checks above deliberately exclude — and it runs whether or
 * not the host uses the realm axis at all: `~/Herd/tower` declares no realms and must still hear about
 * a missing table. A source-backed resource (`members`, `review-queue`) declares no model and is
 * counted, not probed.
 *
 * ⚠️ The probe has to be able to say "did not look". A multi-tenant host keeps most of its tables in
 * tenant schemas that `Schema::hasTable()` on the central connection cannot see, so the default probe
 * asks the model's own connection first and then, on Postgres, `information_schema.tables` across every
 * schema on that connection — the flagship's 54 registrations read 51 backed, 3 absent, 0 unknown that
 * way. A connection that will not open, a driver whose catalog is not consulted, a model that will not
 * construct: those are **inconclusive**, named on the census line and never warned, because an
 * instrument whose "absent" and "could not look" are spelled identically is the estate's signature
 * defect.
 */
class UnrealmedResourceAudit implements DoctorAudit
{
    /** A model-backed resource whose table exists on no connection or schema this host can see. */
    public const CHECK_UNBACKED = 'resource.backing.absent';

    /** The backing census line: backed / absent / unprobeable / source-backed. */
    public const CHECK_BACKING = 'resource.backing';

    /** A framed resource whose membership is empty on both rungs: filtered out of every realm. */
    public const CHECK_UNREALMED = 'resource.realm.unrealmed';

    /** A `config('frame.realms')` entry naming a key nothing registered: silently dropped. */
    public const CHECK_UNREGISTERED = 'resource.realm.unregistered';

    /** The census line, emitted whether or not anything warned. */
    public const CHECK_CENSUS = 'resource.realm';

    /**
     * @param  (Closure(?string $connection, string $table): ?bool)|null  $tableExists  a stand-in for the
     *                                                                                  database — true exists, false
     *                                                                                  absent everywhere, null could
     *                                                                                  not look. Tests use it; a host
     *                                                                                  gets the default probe.
     */
    public function __construct(
        protected ParticleResourceRegistry $registry,
        protected ?DatabaseManager $db = null,
        protected ?Closure $tableExists = null,
    ) {}

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        return array_merge($this->backingFindings(), $this->realmFindings());
    }

    // ── backing applicability ───────────────────────────────────────────────────────────────────────

    /**
     * @return list<Finding>
     */
    protected function backingFindings(): array
    {
        $backed = [];
        /** @var array<string, array{model: string, table: string, connection: ?string}> $absent */
        $absent = [];
        /** @var array<string, string> $unknown key => why */
        $unknown = [];
        $sourceBacked = 0;

        foreach ($this->registry->all() as $resource) {
            try {
                $model = $resource->modelClass();
            } catch (\Throwable $e) {
                $unknown[$resource->key] = 'backing did not resolve: '.$e->getMessage();

                continue;
            }

            if ($model === null) {
                $sourceBacked++;

                continue;
            }

            if (! class_exists($model) || ! is_subclass_of($model, Model::class)) {
                $unknown[$resource->key] = $model.' is not a loadable Eloquent model';

                continue;
            }

            try {
                $instance = new $model;
                $table = $instance->getTable();
                $connection = $instance->getConnectionName();
            } catch (\Throwable $e) {
                $unknown[$resource->key] = $model.' would not construct: '.$e->getMessage();

                continue;
            }

            $exists = $this->tableExists($connection, $table);

            if ($exists === true) {
                $backed[] = $resource->key;
            } elseif ($exists === false) {
                $absent[$resource->key] = ['model' => $model, 'table' => $table, 'connection' => $connection];
            } else {
                $unknown[$resource->key] = sprintf('could not probe [%s] on connection [%s]', $table, $connection ?? 'default');
            }
        }

        if ($backed === [] && $absent === [] && $unknown === []) {
            return [Finding::inconclusive(
                self::CHECK_BACKING,
                sprintf(
                    'No registered particle resource names an Eloquent model (%d source-backed), so there '
                    .'is no table to look for. Nothing was measured.',
                    $sourceBacked,
                ),
            )];
        }

        $findings = [];

        foreach ($absent as $key => $row) {
            $findings[] = Finding::warn(
                self::CHECK_UNBACKED,
                sprintf(
                    '[%s] (%s) is registered here and its table [%s] exists on no connection or schema this '
                    .'host can see (probed connection [%s], then every schema on it). It is a dead '
                    .'registration: any route or realm that serves it answers with a query error, and no '
                    .'declaration can make that intentional. Either run the migration that creates the '
                    .'table, or — if this host binds its own model for the concept — register the '
                    .'replacement resource and stop discovering this one.',
                    $key,
                    $row['model'],
                    $row['table'],
                    $row['connection'] ?? 'default',
                ),
            );
        }

        $summary = sprintf(
            '%d model-backed resource%s: %d backed, %d absent, %d could not be probed; %d source-backed not probed.',
            count($backed) + count($absent) + count($unknown),
            count($backed) + count($absent) + count($unknown) === 1 ? '' : 's',
            count($backed),
            count($absent),
            count($unknown),
            $sourceBacked,
        );

        if ($unknown !== []) {
            $findings[] = Finding::inconclusive(
                self::CHECK_BACKING,
                $summary.' Not looked at: '.implode('; ', array_map(
                    fn (string $key, string $why) => sprintf('[%s] %s', $key, $why),
                    array_keys($unknown),
                    $unknown,
                )),
            );
        } else {
            $findings[] = Finding::pass(self::CHECK_BACKING, $summary);
        }

        return $findings;
    }

    /**
     * Does the table exist anywhere this host can see? true / false / null = could not look.
     *
     * The model's own connection first, via the schema builder (which honours the prefix and, on a
     * tenant-initialised connection, the tenant's `search_path`). Then, on Postgres only, every schema
     * on that connection — a multi-tenant host keeps most of its tables in `tenant_*` schemas the central
     * `search_path` cannot see, and "exists in some schema" is the question this check asks. Any other
     * driver stops at the schema builder's answer. Anything that throws is null.
     */
    protected function tableExists(?string $connection, string $table): ?bool
    {
        if ($this->tableExists !== null) {
            return ($this->tableExists)($connection, $table);
        }

        if ($this->db === null) {
            return null;
        }

        try {
            $conn = $this->db->connection($connection);

            if ($conn->getSchemaBuilder()->hasTable($table)) {
                return true;
            }

            if ($conn->getDriverName() === 'pgsql') {
                $rows = $conn->select(
                    'select 1 from information_schema.tables where table_name = ? limit 1',
                    [$conn->getTablePrefix().$table],
                );

                return $rows !== [];
            }

            return false;
        } catch (\Throwable) {
            return null;
        }
    }

    // ── realm membership ────────────────────────────────────────────────────────────────────────────

    /**
     * @return list<Finding>
     */
    protected function realmFindings(): array
    {
        $framed = array_values(array_filter(
            $this->registry->all(),
            fn (ParticleResource $resource) => $resource->isFramed(),
        ));

        if ($framed === []) {
            return [Finding::inconclusive(
                self::CHECK_CENSUS,
                'No framed particle resources are registered in this host, so there is no manifest for a '
                .'realm filter to drop anything from. Nothing was measured.'
            )];
        }

        /** @var array<string, list<string>> $membership */
        $membership = [];
        foreach ($framed as $resource) {
            $membership[$resource->key] = $this->registry->realmsFor($resource->key);
        }

        $unrealmed = array_keys(array_filter($membership, fn (array $realms) => $realms === []));
        $realmed = array_keys(array_filter($membership, fn (array $realms) => $realms !== []));

        // The population gate. Read off the declared authority, not off config: `realmsFor()` climbs
        // BOTH rungs, and a host could in principle realm every resource at its `register()` call and
        // ship no `frame.realms` map at all.
        if ($realmed === []) {
            return [Finding::inconclusive(
                self::CHECK_CENSUS,
                sprintf(
                    'This host declares no realm membership on either rung (%s) for any of its %d framed '
                    .'resource%s, so it does not use the realm axis and nothing can be outside it — the '
                    .'manifest is served unfiltered via definitions(null). Nothing was measured.',
                    implode('/', $this->registry->rungs()),
                    count($framed),
                    count($framed) === 1 ? '' : 's',
                ),
            )];
        }

        $realms = $this->declaredRealms($membership);
        $findings = [];

        foreach ($unrealmed as $key) {
            $findings[] = Finding::warn(
                self::CHECK_UNREALMED,
                sprintf(
                    '[%s] (%s) is a framed resource belonging to no realm, so definitions($realm) filters '
                    .'it out of every one of this host\'s realms (%s) — it is registered and unreachable '
                    .'through the manifest, silently. Add the key under whichever of those realms it '
                    .'belongs to in config(\'frame.realms\') or config(\'beam.core.resources.realm_map\'), '
                    .'or name them where it registers: '
                    .'register($resource, [<realm>, …]). If it belongs to none of them, it is REST-only '
                    .'and should not be framed.',
                    $key,
                    $this->dataClassFor($key),
                    implode(', ', $realms),
                ),
            );
        }

        foreach ($this->unregisteredMappedKeys() as $realm => $keys) {
            foreach ($keys as $key) {
                $findings[] = Finding::warn(
                    self::CHECK_UNREGISTERED,
                    sprintf(
                        'The realm map puts [%s] in realm [%s], and no registered particle resource claims '
                        .'that key. keysForRealm() returns the intersection with what is registered, so '
                        .'the entry is dropped with no error — whichever seed named it (config '
                        .'`frame.realms`, config `beam.core.resources.realm_map`, or a host loadRealmMap() '
                        .'call) either has a typo or outlived the resource it named.',
                        $key,
                        $realm,
                    ),
                );
            }
        }

        $findings[] = Finding::pass(
            self::CHECK_CENSUS,
            sprintf(
                '%d framed resource%s across %d realm%s (%s): %d realmed, %d reachable through no realm.',
                count($framed),
                count($framed) === 1 ? '' : 's',
                count($realms),
                count($realms) === 1 ? '' : 's',
                implode(', ', $realms),
                count($realmed),
                count($unrealmed),
            ),
        );

        return $findings;
    }

    /**
     * Every realm name this host actually uses, from the membership it just computed UNION the raw map —
     * so a realm that is declared in config and happens to hold only unregistered keys is still named as
     * a realm the unrealmed resources could have joined.
     *
     * @param  array<string, list<string>>  $membership
     * @return list<string>
     */
    protected function declaredRealms(array $membership): array
    {
        $realms = array_merge(...array_values($membership));

        foreach (array_keys($this->realmMap()) as $realm) {
            $realms[] = (string) $realm;
        }

        $realms = array_values(array_unique($realms));
        sort($realms);

        return $realms;
    }

    /**
     * The mapped keys that no registered resource claims, `realm => [keys]`, empty realms omitted.
     *
     * Reads EVERY registered key, framed or not: a REST-only resource is a perfectly legitimate member
     * of a realm ({@see ParticleResourceRegistry::keysForRealm()}'s first documented property), so
     * narrowing this to the framed set would report every REST-only member as a phantom.
     *
     * @return array<string, list<string>>
     */
    protected function unregisteredMappedKeys(): array
    {
        $registered = array_map(
            fn (ParticleResource $resource) => $resource->key,
            $this->registry->all(),
        );

        $phantom = [];

        foreach ($this->realmMap() as $realm => $keys) {
            $missing = array_values(array_diff(
                array_map('strval', array_filter((array) $keys, 'is_scalar')),
                $registered,
            ));

            if ($missing !== []) {
                $phantom[(string) $realm] = $missing;
            }
        }

        return $phantom;
    }

    /**
     * The `realm-map` rung's source, read off the REGISTRY rather than off config.
     *
     * ⚠️ This used to read `config('frame.realms')` directly, and said so on the explicit premise that
     * "the registry does not expose the map it was seeded with, and this is its ONE seed". That premise
     * died with particle-manifest-repatriation ticket 02, which added `beam.core.resources.realm_map` as
     * an additive second source and a host `boot()` seam as a third. Left alone, this method would have
     * gone silently blind to every resource realmed through either — reporting a correctly realmed
     * resource as belonging to no realm, and a legitimately mapped key as a phantom. The registry is the
     * declared authority for its own seeds; the number of them is not this audit's business.
     *
     * @return array<string, mixed>
     */
    protected function realmMap(): array
    {
        return $this->registry->realmMap();
    }

    /** The Data class a key projects from — the address a reader needs to go fix it. */
    protected function dataClassFor(string $key): string
    {
        $resource = $this->registry->find($key);

        return $resource?->data ?? 'unknown';
    }
}
