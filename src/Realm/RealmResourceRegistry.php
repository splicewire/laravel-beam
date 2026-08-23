<?php

namespace Splicewire\Beam\Realm;

use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\RegistryArity;
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
class RealmResourceRegistry
{
    /**
     * Overlays indexed by realm then resource key: `['<realm>' => ['<key>' => RealmResourceOverride]]`.
     *
     * @var array<string, array<string, RealmResourceOverride>>
     */
    private array $overrides = [];

    public function __construct(private RealmRegistry $realms) {}

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
        $this->overrides[$realm][$key] = $override;

        return $this;
    }

    /** Whether ANY overlay applies to `$key` in `$realm` (following the realm's stack chain). */
    public function has(string $key, string $realm): bool
    {
        foreach ($this->chain($realm) as $through) {
            if (isset($this->overrides[$through][$key])) {
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
            $override = $this->overrides[$through][$base->key] ?? null;

            if ($override !== null) {
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
        $stack = $this->realms->has($realm) ? $this->realms->resolve($realm)->stack : [];

        return [...$stack, $realm];
    }
}
