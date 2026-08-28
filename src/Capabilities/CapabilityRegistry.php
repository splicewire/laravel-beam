<?php

namespace Splicewire\Beam\Capabilities;

use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryArity;
use Rushing\Popcorn\Registries\RegistryKey;

/**
 * Generic registry of {@see GatedCapability} declarations (app ADR-0023).
 *
 * The single lookup the Gate and Tenant Sync pre-flight share. It carries the
 * register()/get()/byEntitlement()/all() vocabulary typed against the beam
 * {@see GatedCapability} contract, and it registers NOTHING by default: beam
 * mints no capability of its own.
 *
 * ## Contributors seed it from their own boot; there is no subclass hook
 *
 * A package that ships capabilities registers them into THIS registry from its
 * own `boot()` — `app(CapabilityRegistry::class)->register(new WebSearchCapability)`
 * — the way every other package in the estate contributes to a registry it does
 * not own. `Splicewire\Tower\TowerServiceProvider::packageBooted()` is the live
 * example.
 *
 * It used to be an overridable `registerDefaults()` hook with one subclass
 * (`Splicewire\Tower\Capabilities\CapabilityRegistry`, deleted 2026-08-28). The
 * subclass's own docblock already conceded it was *"not a second registry, it is
 * a second seeding site"* — and a seeding site does not need a type. What it
 * cost was real: it was the estate's live instance of an undeclared subclass of
 * a declared registry, which is what forced `IsRegistry::of()` to walk the parent
 * chain (registry-kernel ticket 42) and `collidesOnRoot()` to become
 * inheritance-aware. That walk stays — `InheritingFixtureRegistry` pins it, and
 * beam-core still ships subclassing as a legal extension shape elsewhere — but
 * this root no longer exercises it, and one live object seeded from a provider is
 * strictly easier to reason about than one seeded from a constructor whose
 * subclass the host has to remember to bind.
 *
 * ## Composed, not inherited
 *
 * {@see BasicRegistry} is held as a FIELD (registry-kernel ticket 01 D1) — its own
 * docblock closes the extending door, and this class publishes caller-facing sugar
 * (`get()`/`byEntitlement()`/`all()`) that no kernel base could supply.
 */
#[IsRegistry(
    root: 'beam.capabilities',
    of: 'gated capabilities by key + entitlement (web search, schema-LLM migration, node types)',
    arity: RegistryArity::PickOne,
    entryType: GatedCapability::class,
    onDuplicate: OnDuplicate::Supersede,
    note: 'The estate has several classes whose SHORT name is CapabilityRegistry — tower\'s '
        .'ConduitCapabilityRegistry (root `conduit`) and CircuitCapabilityRegistry (root '
        .'`circuit.capabilities`) among them — which is why the index keys on ROOT and never on short '
        .'name. Two of the historical claimants are gone: registry-kernel ticket 44 (2026-08-26) '
        .'renamed Tower\Circuit\Capabilities\CapabilityRegistry to CapabilityLadder, and this ticket '
        .'(2026-08-28) deleted Tower\Capabilities\CapabilityRegistry, the attribute-less SUBCLASS of '
        .'this class, in favour of provider-tier seeding. So this root now has exactly ONE class on it '
        .'and no inherited declaration to resolve. Earlier readings of this note claimed the subclass '
        .'declared its own root and its own OnDuplicate::Admit — false against the file even then, and '
        .'the standing evidence that a restated argument is a place to drift (41 D11 found it).',
    order: 15,
)]
class CapabilityRegistry implements Registry
{
    /**
     * The stored declarations, keyed by {@see GatedCapability::key()}.
     *
     * @var BasicRegistry<GatedCapability>
     */
    private BasicRegistry $store;

    public function __construct()
    {
        $this->store = BasicRegistry::for($this);
    }

    // ── The caller-facing vocabulary (unchanged signatures) ──────────────────────────────────────

    /**
     * Write a capability.
     *
     * **One-arg self-keying is the primary form** — `register(new WebSearchCapability)` keys off
     * `$capability->key()`, the shape `InvocableRegistry` established and every existing call site
     * uses. The kernel's `(key, entry)` form is accepted too so this satisfies {@see Registry}
     * without a second method; the widened first parameter is contravariant, so nothing that
     * type-checks against the contract breaks.
     */
    public function register(RegistryKey|string|GatedCapability $key, mixed $entry = null, ?string $by = null, ?string $ability = null): static
    {
        if ($key instanceof GatedCapability) {
            $entry = $key;
            $key = $entry->key();
        }

        $this->store->register($key, $entry, $by, $ability);

        return $this;
    }

    /**
     * The capability at `$key`, or null.
     *
     * The nullable half of the pair on purpose, and it stays nullable: every live caller feeds this
     * a key that came from OUTSIDE the code — a chat manifest's `requiredEntitlement`, a sync
     * artifact's declared capability list, a gate's `string` overload — which is exactly the
     * `tryResolve()` side of {@see Registry::tryResolve()}'s rule. {@see resolve()} is the throwing
     * half for a key the code chose.
     *
     * A string that is not a legal key answers "absent" rather than throwing, for the same reason:
     * at a request-facing lookup, "not a key" and "no such key" are one answer.
     */
    public function get(string $key): ?GatedCapability
    {
        if (Key::tryParse($key) === null) {
            return null;
        }

        return $this->store->tryResolve($key);
    }

    /**
     * The first capability requiring `$entitlement`, or null.
     *
     * Registration order, guaranteed by {@see Registry::matches()} — so a superseding registration
     * keeps the original slot rather than jumping the queue.
     */
    public function byEntitlement(string $entitlement): ?GatedCapability
    {
        foreach ($this->all() as $capability) {
            if ($capability->requiredEntitlement() === $entitlement) {
                return $capability;
            }
        }

        return null;
    }

    /**
     * Every registered capability, registration order.
     *
     * Returns the ENTRIES, not their keys — deliberately unlike {@see keys()}, which returns
     * absolute {@see RegistryKey}s under `beam.capabilities`. The two are not interchangeable and
     * never were (registry-kernel ticket 38, recipe amendment 4).
     *
     * @return list<GatedCapability>
     */
    public function all(): array
    {
        return $this->store->matches($this->store->root());
    }

    // ── The kernel contract ─────────────────────────────────────────────────────────────────────

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

    /** @return list<GatedCapability> */
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
        $unfiltered = clone $this;
        $unfiltered->store = $this->store->unfiltered();

        return $unfiltered;
    }
}
