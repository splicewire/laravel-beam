<?php

namespace Splicewire\Beam\Realm;

use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\Exceptions\InvalidRegistryKey;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryArity;
use Rushing\Popcorn\Registries\RegistryKey;
use Schemastud\Frame\Registry\ResourceDefinition;

/**
 * The per-realm presentation-override registry (RDU-03) — the overlay layer behind the `?realm` seam.
 *
 * Holds {@see RealmResourceOverride}s keyed by `(realm, key)` and, given a base {@see ResourceDefinition}
 * and a target realm, returns the base with the applicable overlay merged in ({@see apply()}). It ships
 * INERT: with no overlay registered for a `(realm, key)`, {@see apply()} returns the base UNCHANGED, so a
 * simple spin-up (empty `frame.realm_resource_overrides`) sees no behavior change at all — every realm
 * gets the identical projection.
 *
 * ## Resolution follows `RealmRegistry::effective()`/stack order
 *
 * A realm COMPOSES THROUGH the realms in its `stack` (bottom→top; e.g. a collapsed `user` realm stacks on
 * `['tenant']`). Overlay resolution walks that same chain: the applicable overlays are, in order,
 * `[...stack, self]` — least-specific (bottom of stack) first, the requested realm itself LAST and
 * therefore winning. So a `user`-realm override wins over a `tenant`-realm one for the `user` realm, while
 * a resource with only a `tenant` override still picks it up when presented in the collapsed `user` realm.
 * A stackless realm (`admin`, `site`, or a separated `user`) resolves against its own key alone.
 *
 * Frame never sees this: {@see apply()} hands back a finished {@see ResourceDefinition} (merged via
 * {@see RealmResourceOverride::mergeInto()} → {@see ResourceDefinition::withOverrides()}). The realm
 * concept lives entirely in beam.
 */
#[IsRegistry(
    root: 'beam.realm.resource-overrides',
    of: 'per-realm presentation OVERRIDES merged into a resource declaration as it is projected for a realm',
    arity: RegistryArity::ComposeMany,
    entryType: RealmResourceOverride::class,
    onDuplicate: OnDuplicate::Supersede,
    note: 'A PRECEDENCE registry — registry-kernel ticket 15 archetype (d), never sweepable. Its state is '
        .'`[realm][key]`, two DIMENSIONS rather than a dotted key, and `apply()` walks the realm\'s '
        .'declared `[...stack, self]` ancestry — a DECLARED chain, not lexical reach (ticket 05). It is '
        .'also config-driven and mutable at runtime, so it cannot be baked. Ticket 36 owns the shape.',
    order: 18,
)]
/**
 * @implements Registry<RealmResourceOverride>
 */
class RealmResourceRegistry implements Registry
{
    /**
     * Overlays at `<realm>.<key>` — the two DIMENSIONS the declaration's note describes, expressed as
     * one dotted address so the kernel can hold them. Both halves are ordinary bare keys (`tenant`,
     * `widgets`), so no URI or class key type is needed.
     *
     * @var BasicRegistry<RealmResourceOverride>
     */
    private BasicRegistry $store;

    public function __construct(private RealmRegistry $realms)
    {
        $this->store = BasicRegistry::for($this);
    }

    /**
     * Hydrate the registry from the `frame.realm_resource_overrides` config shape
     * `['<realm>' => ['<key>' => ['<field>' => <value>]]]`. Additive — merges onto whatever is already
     * registered (last-wins per `(realm, key)`). The default config ships EMPTY, so this is a no-op until
     * a host (RDU-05) seeds real overlays.
     *
     * @param  array<string, array<string, array<string, mixed>>>  $config
     */
    public function loadConfig(array $config): self
    {
        foreach ($config as $realm => $keys) {
            if (! is_array($keys)) {
                continue;
            }

            foreach ($keys as $key => $fields) {
                if (! is_array($fields)) {
                    continue;
                }

                $this->override((string) $key, (string) $realm, RealmResourceOverride::fromArray($fields));
            }
        }

        return $this;
    }

    /**
     * The fluent escape hatch: register an overlay for a `(key, realm)` imperatively. Last-wins per pair.
     */
    public function override(string $key, string $realm, RealmResourceOverride $override): self
    {
        $this->store->register($realm.'.'.$key, $override, by: 'override()');

        return $this;
    }

    /**
     * The one dotted address a `(realm, key)` pair lives at, or null when either half is not a legal
     * key.
     *
     * Nullable on purpose. `$realm` reaches `apply()` off a request-derived resource read, and
     * {@see Key::parse()} throws {@see InvalidRegistryKey}
     * on an illegal string BEFORE any miss is considered. The array lookup this replaced simply
     * missed, so gating with `tryParse()` is what keeps a garbage `?realm=` a no-op overlay rather
     * than a 500 (the rule is written on {@see Registry::tryResolve()}).
     */
    private function address(string $realm, string $key): ?Key
    {
        return Key::tryParse($realm.'.'.$key);
    }

    /**
     * Whether ANY overlay applies to `$key` in `$realm` (following the realm's stack chain).
     *
     * ⚠️ RENAMED from `has()` by registry-kernel 38, and this one could not have been widened: it takes
     * TWO required arguments and walks a chain, while {@see Registry::has()} takes one key and means
     * "exactly here". No signature satisfies both, so the domain method moves and the contract's
     * {@see has()} sits beside it.
     */
    public function hasOverride(string $key, string $realm): bool
    {
        foreach ($this->chain($realm) as $through) {
            $address = $this->address($through, $key);

            if ($address !== null && $this->store->has($address)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return `$base` with the applicable per-realm overlay merged in — or `$base` UNCHANGED when no
     * overlay applies. Overlays are resolved along the realm's effective/stack chain (`[...stack, self]`)
     * and merged bottom→top, so the requested realm's own overlay wins. Inert by default.
     */
    public function apply(ResourceDefinition $base, ?string $realm): ResourceDefinition
    {
        if ($realm === null) {
            return $base;
        }

        $definition = $base;

        foreach ($this->chain($realm) as $through) {
            $address = $this->address($through, $base->key);
            $override = $address === null ? null : $this->store->tryResolve($address);

            if ($override instanceof RealmResourceOverride) {
                $definition = $override->mergeInto($definition);
            }
        }

        return $definition;
    }

    /**
     * The realm keys an overlay is resolved against, in merge order (bottom→top): the realm's `stack`
     * (least-specific first) then the realm ITSELF (most-specific, wins). Mirrors
     * {@see RealmRegistry::effective()}'s stack-walk. An unregistered realm resolves against its bare key
     * (no stack) — the override layer never fails a lookup just because a key is unknown to the realm
     * registry.
     *
     * @return list<string>
     */
    private function chain(string $realm): array
    {
        // `$realm` reaches here off a config key or a request-derived resource read, so it is an OUTSIDE
        // key and the nullable half is the one that fits (registry-kernel 61: a key the code chose is a
        // `resolve()`, a key that came from outside is a `tryResolve()`). This was written as
        // `has()`-then-`resolve()`, the double lookup 61 D3 names as the cost of an unreachable nullable
        // half — and it is the finding 63's `miss-pair` check raised here, its first live catch.
        $stack = $this->realms->tryResolve($realm)?->stack ?? [];

        return [...$stack, $realm];
    }

    /* ---------------- Registry contract ---------------- */

    /**
     * Register an overlay at a full `<realm>.<key>` address. The fluent, dimension-named
     * {@see override()} is what every live caller uses and stays exactly as it was; this is the
     * kernel's door onto the same store.
     */
    public function register(
        RegistryKey|string $key,
        mixed $override = null,
        ?string $by = null,
        ?string $ability = null,
    ): static {
        $this->store->register($key, $override, $by, $ability);

        return $this;
    }

    /**
     * Whether a visible overlay sits at EXACTLY this address — a full `<realm>.<key>`, and no stack
     * walk. {@see hasOverride()} is the chain-following predicate this registry is built for.
     */
    public function has(RegistryKey|string $key): bool
    {
        return $this->store->has($key);
    }

    public function resolve(RegistryKey|string $key): mixed
    {
        return $this->store->resolve($key);
    }

    public function tryResolve(RegistryKey|string $key): mixed
    {
        return $this->store->tryResolve($key);
    }

    /** @return list<RealmResourceOverride> */
    public function matches(RegistryKey|string $key): array
    {
        return $this->store->matches($key);
    }

    /** @return list<RegistryKey> */
    public function keys(): array
    {
        return $this->store->keys();
    }

    public function unfiltered(): Registry
    {
        return $this->store->unfiltered();
    }
}
