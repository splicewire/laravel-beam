<?php

namespace Splicewire\Beam\Nav;

use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\Gated;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\Optionality;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryKey;
use Splicewire\Beam\BeamServiceProvider;
use Splicewire\Beam\Realm\RealmOverlayRegistry;
use Splicewire\Beam\Realm\RealmRegistry;

/**
 * The seam a package declares a top-level NAV SEAT through — the half of nav declaration that did not
 * exist until now.
 *
 * `#[ParticleResource(section: 'ops')]` says which section a resource sits under; it cannot seat that
 * section. Measured 2026-09-05: 11 of the family's 30 package-declared sections name a section no host
 * seats, so they are correctly declared and invisible. {@see NavSection} is the declaration; this is
 * where it is collected.
 *
 * A SINGLETON (bound in {@see BeamServiceProvider}), so a capability package registering a seat from its
 * own provider mutates the shared instance a projector reads — the same lifetime and the same reason as
 * {@see RealmOverlayRegistry}.
 *
 * Inert by default: an empty registry seats nothing, so a host that registers no package seats projects
 * a navigation byte-for-byte identical to the one it hand-authored.
 *
 * ## Additive, self-keyed by REALM
 *
 * A seat self-keys off `$section->realm`, not off its own `key`, because the question a projector asks
 * is *"what seats does the `tenant` region carry?"* Several packages seating several sections in one
 * region is the DESIGN, not a collision — hence `OnDuplicate::Admit`. Registration
 * order is not the read order: {@see for()} sorts by declared `order` then by `key`, so two packages
 * whose providers boot in either order project the same navigation.
 *
 * ## A registrar NEVER creates a REALM here
 *
 * This registry mirrors {@see RealmOverlayRegistry}'s posture exactly, one rung out: *"A registrar NEVER
 * creates a realm here… An overlay whose `realmKey` is not registered is a silent no-op at projection
 * time."* The same holds for a seat. This registry has no realm-creation verb; a seat declared for a
 * realm {@see RealmRegistry} does not ship is accepted, stored, and never read — a projector iterates the
 * realms the host HAS and asks {@see for()} about each, so a seat for an absent realm is structurally
 * unreachable rather than an error. A package cannot conjure a region into being by naming one.
 *
 * ## No admission gate, deliberately
 *
 * There is no opt-in list a host must add a package to before its seats appear. The owner's ruling is
 * *"installed means enabled"*, and the existing `config/frame.php` realm list is the kill switch: a
 * realm the host does not ship gets no seats by the no-op above, and a resource absent from a realm is
 * already not surfaced. Nav does not get a second switch. Do not add one here without reversing that
 * ruling first.
 */
#[IsRegistry(
    root: 'beam.nav.sections',
    entryType: NavSection::class,
    onDuplicate: OnDuplicate::Admit,
    optionality: Optionality::Optional,
    description: 'Package-declared navigation sections grouped by realm. Reads combine all seats for a realm in registration order. Multiple seats per realm are retained. An empty registry preserves the navigation authored by the host; seats for unknown realms have no effect.',
    order: 18,
)]
/**
 * @implements Registry<NavSection>
 */
class NavSectionRegistry implements Gated, Registry
{
    /** @var BasicRegistry<NavSection> realm key => the seats declared for it, in registration order. */
    private BasicRegistry $store;

    public function __construct()
    {
        $this->store = BasicRegistry::for($this);
    }

    /**
     * Declare a top-level nav seat. Additive — a second seat for the same realm joins the first rather
     * than replacing it, which is what `OnDuplicate::Admit` above buys.
     *
     * The seat SELF-KEYS off `$section->realm`, so the ergonomic call is a bare positional
     * `register(new NavSection(...))`. The first parameter is widened rather than replaced so that shape
     * satisfies {@see Registry::register()}'s `(key, entry, by, ability)` contract at the same time.
     *
     * A realm key is a bare {@see Key} — `tenant`, `operator`, `user`, `admin`, `site` — so no URI or
     * class key type is needed here.
     */
    public function register(
        RegistryKey|string|NavSection $key,
        mixed $section = null,
        ?string $by = null,
        ?string $ability = null,
    ): static {
        if ($key instanceof NavSection) {
            $section = $key;
            $key = $key->realm;
        }

        $this->store->register($key, $section, $by, $ability);

        return $this;
    }

    /**
     * The seats declared for a realm, in PROJECTION order — declared `order` ascending, ties broken by
     * `key`. Empty when none, which is the common case and the whole of the no-op above: a projector
     * asking about a realm nobody seated, or about a realm that does not exist, gets `[]` either way.
     *
     * Deliberately NOT registration order (unlike {@see RealmOverlayRegistry::for()}, whose overlays
     * FOLD and so must keep it): two packages whose providers boot in either order must project one
     * navigation, so the read imposes a total order rather than inheriting boot order.
     *
     * @return list<NavSection>
     */
    public function for(string $realmKey): array
    {
        $sections = $this->store->matches($realmKey);

        usort($sections, NavSection::compare(...));

        return array_values($sections);
    }

    /**
     * Every declared seat, across every realm — realm key ascending, then the same total order
     * {@see for()} imposes within a realm. For diagnostics and for a projector that wants the whole set
     * in one read; it does NOT assert those realms exist.
     *
     * @return list<NavSection>
     */
    public function all(): array
    {
        $realms = $this->targetedRealmKeys();
        sort($realms);

        return array_merge(...array_map($this->for(...), $realms)) ?: [];
    }

    /**
     * Every realm key some seat targets, as CALLERS spell them — bare, root-stripped, deduplicated.
     * Diagnostics only: a projector iterates the realm registry, not this, so a seat for an
     * unregistered realm never surfaces through it.
     *
     * ⚠️ Deliberately not {@see keys()}, which answers with ABSOLUTE `RegistryKey`s
     * (`beam.nav.sections.tenant`). The two are signature-compatible and mean different things
     * (registry-kernel ticket 38, recipe amendment 4).
     *
     * @return list<string>
     */
    public function targetedRealmKeys(): array
    {
        return array_values(array_unique($this->store->relativeKeys()));
    }

    /* ---------------- Registry contract ---------------- */

    /** Whether any seat is declared for the given realm. */
    public function has(RegistryKey|string $key): bool
    {
        return $this->store->has($key);
    }

    /**
     * ⚠️ `Admit` is declared, so a realm carrying MORE than one seat — the design, not an accident —
     * makes this throw `AmbiguousRegistryMatch`. {@see for()} is the read this registry is actually built
     * for; `resolve()` exists because the contract requires it, and it is honest about the multiplicity
     * rather than silently returning the first.
     */
    public function resolve(RegistryKey|string $key): mixed
    {
        return $this->store->resolve($key);
    }

    public function tryResolve(RegistryKey|string $key): mixed
    {
        return $this->store->tryResolve($key);
    }

    /**
     * ⚠️ Registration order, NOT projection order — this is the raw contract read. Use {@see for()} for
     * anything a user will see.
     *
     * @return list<NavSection>
     */
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

    public function authorizeWith(?Authorizer $authorizer): static
    {
        $this->store->authorizeWith($authorizer);

        return $this;
    }
}
