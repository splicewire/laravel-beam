<?php

namespace Splicewire\Beam\Realm;

use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryArity;
use Rushing\Popcorn\Registries\RegistryKey;
use Splicewire\Beam\BeamServiceProvider;

/**
 * Frame OS ticket 14 (ADR-0014 §A2): the registry the {@see RealmManifestProjector} consults to fold
 * additive realm OVERLAYS into their target realm's descriptor BEFORE the manifest emits.
 *
 * This is the beam-side seam that keeps beam SATELLITE-AGNOSTIC. beam knows only that some caller may
 * register an overlay ON an EXISTING realm (by key); it does not know a "satellite" exists. The
 * satellite tier (`splicewire/laravel-satellite`) is one such caller — it registers an overlay here
 * from its own provider, exactly as a tenancy package re-registers a realm shape. The projector reads
 * this registry as one more input, so overlay resolution happens PHP-side, before emit, mirroring how a
 * tenant scope is resolved wholly PHP-side.
 *
 * A SINGLETON (bound in {@see BeamServiceProvider}), so a capability package
 * registering from its own provider mutates the shared instance the projector reads.
 *
 * Inert by default: an empty registry folds nothing, so every realm descriptor is byte-for-byte
 * unchanged and an existing manifest is identical. Registration is ADDITIVE — multiple overlays may
 * target the same realm; they fold in registration order (last action at a JSONPath target wins,
 * per the data-schema overlay fold).
 *
 * A registrar NEVER creates a realm here — this registry has no `register-realm` verb. It only enriches
 * a realm that {@see RealmRegistry} already ships. An overlay whose `realmKey` is not registered is a
 * silent no-op at projection time (the projector never manufactures a descriptor for it), so a
 * satellite structurally cannot own a realm or add a standalone tile.
 */
#[IsRegistry(
    root: 'beam.realm.overlays',
    of: 'additive realm OVERLAYS folded onto an EXISTING realm descriptor before the manifest emits',
    arity: RegistryArity::ComposeMany,
    entryType: RealmOverlay::class,
    onDuplicate: OnDuplicate::Admit,
    note: 'ComposeMany, not RunAll: a read is keyed by realm and the overlays for that realm FOLD in '
        .'registration order, each transforming what the previous produced (last write at a JSONPath '
        .'target wins). Admit because multiple overlays targeting one realm is the design, not a '
        .'collision. An overlay never CREATES a realm — an unregistered realmKey is a silent no-op.',
    order: 17,
)]
/**
 * @implements Registry<RealmOverlay>
 */
class RealmOverlayRegistry implements Registry
{
    /** @var BasicRegistry<RealmOverlay> realm key => the overlays targeting it, in registration order. */
    private BasicRegistry $store;

    public function __construct()
    {
        $this->store = BasicRegistry::for($this);
    }

    /**
     * Register an additive overlay ON an existing realm. Additive/append — a second overlay for the same
     * realm folds after the first, which is what `OnDuplicate::Admit` above buys.
     *
     * The overlay SELF-KEYS off `$overlay->realmKey`, which is why the first parameter is widened rather
     * than replaced: every live caller passes a bare {@see RealmOverlay} positionally
     * (`splicewire/laravel-satellite`'s `SatelliteRealmOverlay::register()`, and both packages' tests),
     * and a widened union keeps those call sites byte-identical while satisfying
     * {@see Registry::register()}'s `(key, entry, by, ability)` shape.
     *
     * A realm key is a bare {@see Key} — `tenant`, `user`, `admin`, `site`
     * — so no URI or class key type is needed here.
     */
    public function register(
        RegistryKey|string|RealmOverlay $key,
        mixed $overlay = null,
        ?string $by = null,
        ?string $ability = null,
    ): static {
        if ($key instanceof RealmOverlay) {
            $overlay = $key;
            $key = $key->realmKey;
        }

        $this->store->register($key, $overlay, $by, $ability);

        return $this;
    }

    /**
     * The overlays targeting a realm, in registration (fold) order. Empty when none — the common case.
     *
     * @return list<RealmOverlay>
     */
    public function for(string $realmKey): array
    {
        return $this->store->matches($realmKey);
    }

    /**
     * All realm keys any overlay targets, as CALLERS spell them — bare, root-stripped, deduplicated.
     * Used only for diagnostics — the projector iterates the realm registry, not this, so an overlay
     * for an unregistered realm never surfaces.
     *
     * ⚠️ Deliberately not {@see keys()}, which answers with ABSOLUTE `RegistryKey`s
     * (`beam.realm.overlays.tenant`). The two are signature-compatible and mean different things
     * (registry-kernel 38, recipe amendment 4).
     *
     * @return list<string>
     */
    public function targetedRealmKeys(): array
    {
        return array_values(array_unique($this->store->relativeKeys()));
    }

    /* ---------------- Registry contract ---------------- */

    /** Whether any overlay targets the given realm. */
    public function has(RegistryKey|string $key): bool
    {
        return $this->store->has($key);
    }

    /**
     * ⚠️ `Admit` is declared, so a realm carrying MORE than one overlay — the design, not an accident —
     * makes this throw `AmbiguousRegistryMatch`. {@see for()} is the read this registry is actually
     * built for; `resolve()` exists because the contract requires it, and it is honest about the
     * multiplicity rather than silently returning the first.
     */
    public function resolve(RegistryKey|string $key): mixed
    {
        return $this->store->resolve($key);
    }

    public function tryResolve(RegistryKey|string $key): mixed
    {
        return $this->store->tryResolve($key);
    }

    /** @return list<RealmOverlay> */
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
