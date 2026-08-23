<?php

namespace Splicewire\Beam\Particle\Contribution;

use RuntimeException;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\RegistryArity;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Realm\RealmResourceRegistry;

/**
 * The registry of {@see ResourceContribution}s — every package's named slice of every OTHER package's
 * read projection, indexed `[owner key][as]`.
 *
 * ## Why `Reject` and not `Supersede`
 *
 * Its sibling {@see RealmResourceRegistry} is `Supersede` because an overlay's whole job is last-wins:
 * a later layer presenting the same resource differently is the feature. Here it is the opposite. Two
 * packages both claiming `tenants.commerce` is a wiring conflict, and there is no correct silent
 * resolution — whichever won, the loser's slice would vanish from the wire with no error anywhere. So a
 * duplicate `(key, as)` THROWS at registration, which is the third instance of one house rule on this
 * map (tickets 01, 09, 11) and the same shape
 * {@see ParticleResourceRegistry::register()}'s capability assertion already uses: a declaration error
 * surfaces at boot, not as a missing field on the first read.
 *
 * ## Absent vs. present-and-null (ticket 04 §A7)
 *
 * The two carry DIFFERENT information and the seam preserves the distinction:
 *
 *  - the `as` key is **absent from the row** ⟺ no contribution is registered for it, i.e. the
 *    contributing package is not installed. A bare beam host with tenancy but no commerce serves a
 *    `tenants` row with no `commerce` key at all.
 *  - the `as` key is **present and `null`** ⟺ the contribution ran and returned null — the package IS
 *    installed and this particular record has no slice (a tenant with no subscription).
 *
 * A consumer can therefore tell "commerce is not part of this deployment" from "this tenant is not on a
 * plan", which the `AuthUserExtrasContributor` port this seam supersedes could never express.
 *
 * ## Keyed by string, resolved at read time
 *
 * A contribution stores its owner's resource KEY, not a reference to a declaration, because the
 * contributor boots BEFORE the owner (alphabetical provider order, permanently: beam-commerce at 4,
 * beam-tenancy at 13 — ticket 07). Nothing here reads {@see ParticleResourceRegistry}; the fold happens
 * where the declaration is looked up, not where the contribution is stored.
 */
#[IsRegistry(
    root: 'beam.particle.resource-contributions',
    of: 'cross-package slices of a resource read projection — the contributor declares, the owner names nothing',
    arity: RegistryArity::ComposeMany,
    entryType: ResourceContribution::class,
    onDuplicate: OnDuplicate::Reject,
    note: 'Reject is DECLARED, deliberately unlike the RealmResourceRegistry overlay beside it: an '
        .'overlay means last-wins, a contribution means compose, and two packages claiming one '
        .'sub-projection key has no correct silent resolution. State is `[key][as]`, two DIMENSIONS '
        .'rather than a dotted key, mirroring the overlay registry\'s shape. Read-time only — nothing '
        .'here is merged into a declaration at boot, because the contributor boots first.',
    order: 13,
)]
class ResourceContributionRegistry
{
    /**
     * Contributions indexed by owner resource key then sub-projection key.
     *
     * @var array<string, array<string, ResourceContribution>>
     */
    private array $contributions = [];

    /**
     * Register one package's slice of another's projection.
     *
     * @throws RuntimeException when `(key, as)` is already claimed — see the class docblock.
     */
    public function register(ResourceContribution $contribution): self
    {
        $existing = $this->contributions[$contribution->key][$contribution->as] ?? null;

        if ($existing !== null) {
            throw new RuntimeException(
                "Resource contribution [{$contribution->key}.{$contribution->as}] is already registered by ["
                .$existing->data.']; ['.$contribution->data.'] cannot claim the same sub-projection key. '
                .'Contributions COMPOSE — a duplicate is a wiring conflict, not an override.'
            );
        }

        $this->contributions[$contribution->key][$contribution->as] = $contribution;

        return $this;
    }

    /** Whether ANY package contributes to `$key`. The inert-by-default predicate every fold point asks first. */
    public function has(string $key): bool
    {
        return ($this->contributions[$key] ?? []) !== [];
    }

    /**
     * Every contribution to `$key`, registration order. Empty for a resource nobody contributes to —
     * which is the overwhelmingly common case, and why each fold point is a no-op by default.
     *
     * @return list<ResourceContribution>
     */
    public function for(string $key): array
    {
        return array_values($this->contributions[$key] ?? []);
    }

    /**
     * Every owner key that has at least one contribution — for auditors and the doctor sweep, which
     * need the declared set rather than a lookup.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->contributions);
    }
}
