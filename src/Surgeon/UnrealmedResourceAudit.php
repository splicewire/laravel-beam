<?php

namespace Splicewire\Beam\Surgeon;

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
 */
class UnrealmedResourceAudit implements DoctorAudit
{
    /** A framed resource whose membership is empty on both rungs: filtered out of every realm. */
    public const CHECK_UNREALMED = 'resource.realm.unrealmed';

    /** A `config('frame.realms')` entry naming a key nothing registered: silently dropped. */
    public const CHECK_UNREGISTERED = 'resource.realm.unregistered';

    /** The census line, emitted whether or not anything warned. */
    public const CHECK_CENSUS = 'resource.realm';

    public function __construct(
        protected ParticleResourceRegistry $registry,
    ) {}

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        $framed = array_values(array_filter(
            $this->registry->all(),
            fn (ParticleResource $resource) => $resource->isFramed(),
        ));

        if ($framed === []) {
            return [Finding::pass(
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
            return [Finding::pass(
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
                    .'belongs to in config(\'frame.realms\'), or name them where it registers: '
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
                        'config(\'frame.realms.%s\') names [%s], which no registered particle resource '
                        .'claims. keysForRealm() returns the intersection with what is registered, so the '
                        .'entry is dropped with no error — this line either has a typo or outlived the '
                        .'resource it named.',
                        $realm,
                        $key,
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
     * The `realm-map` rung's source. Read from config because the registry does not expose the map it
     * was seeded with, and this is its ONE seed —
     * `BeamServiceProvider` binds the singleton as
     * `(new ParticleResourceRegistry(...))->loadRealmMap((array) config('frame.realms', []))`.
     *
     * @return array<string, mixed>
     */
    protected function realmMap(): array
    {
        $map = config('frame.realms', []);

        return is_array($map) ? $map : [];
    }

    /** The Data class a key projects from — the address a reader needs to go fix it. */
    protected function dataClassFor(string $key): string
    {
        $resource = $this->registry->find($key);

        return $resource?->data ?? 'unknown';
    }
}
