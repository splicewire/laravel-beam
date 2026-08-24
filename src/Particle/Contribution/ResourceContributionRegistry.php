<?php

namespace Splicewire\Beam\Particle\Contribution;

use ReflectionAttribute;
use ReflectionClass;
use RuntimeException;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\RegistryArity;
use Schemastud\Frame\Attributes\WidgetIn;
use Schemastud\Frame\Registry\WidgetContextProjector;
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

        $this->assertReadOnlyParticipation($contribution);

        $this->contributions[$contribution->key][$contribution->as] = $contribution;

        return $this;
    }

    /**
     * A contributed slice is LIST-COLUMN ONLY, and declaring anything else on it throws HERE —
     * at registration, at boot — rather than being dropped at render.
     *
     * The seam is a read projection by construction: {@see ContributionProjector::apply()} folds
     * onto an already-projected row, and {@see ResourceContribution} carries `includes` and
     * `value` and nothing else. So there are two ways for a slice's `#[WidgetIn]` to be a lie,
     * and both are refused:
     *
     *  - **`row-cell`** would render frame's inline `EditableCell`, whose commit calls
     *    `onCellCommit(record, 'commerce.plan', value)` — a write into a slice with NO WRITER.
     *    Ignoring it silently is precisely how that defect returns; a throw is what makes a
     *    contributor find out at boot that the seam is read-only (ticket 17 §A4).
     *  - **class-level** contexts (`list-item`, the `#[RowActions]` sugar) are whole-RECORD
     *    declarations that key to the manifest's root pointer `""`. A slice has no root — its
     *    pointers are `as.prop` — so {@see ContributionContextNodes} reflects properties only and
     *    a class-level declaration would vanish without a word. Same rule, same reason.
     *
     * ⚠️ Every OTHER context stays legal and unremarked, including `edit` and `detail`: they are
     * read-side bindings the owner's own surfaces may consult, and nothing here writes.
     *
     * @throws RuntimeException when the slice declares participation the seam cannot honour
     */
    private function assertReadOnlyParticipation(ResourceContribution $contribution): void
    {
        $reflection = new ReflectionClass($contribution->data);

        if ($reflection->getAttributes(WidgetIn::class, ReflectionAttribute::IS_INSTANCEOF) !== []) {
            throw new RuntimeException(
                "Resource contribution [{$contribution->key}.{$contribution->as}] declares CLASS-LEVEL widget "
                ."participation on [{$contribution->data}]. A contributed slice has no root node — its manifest "
                .'pointers are `'.$contribution->as.'.<property>` — so a whole-record context (list-item, '
                .'#[RowActions]) has nowhere to land and would be silently dropped. Declare per-property '
                .'participation instead.'
            );
        }

        $projector = new WidgetContextProjector;

        foreach ($reflection->getProperties() as $property) {
            if (! isset($projector->forProperty($property)['row-cell'])) {
                continue;
            }

            throw new RuntimeException(
                "Resource contribution [{$contribution->key}.{$contribution->as}] declares `row-cell` "
                ."participation on [{$contribution->data}::\${$property->getName()}]. The contribution seam is "
                .'READ-ONLY: a slice is folded onto an already-projected row and carries no write arm, so an '
                .'inline cell editor would commit into a slice nothing can persist. Use `list-column` (the '
                .'#[Column] sugar) for a contributed field.'
            );
        }
    }

    /** Whether ANY package contributes to `$key`. The inert-by-default predicate every fold point asks first. */
    public function has(string $key): bool
    {
        return ($this->contributions[$key] ?? []) !== [];
    }

    /**
     * The contribution registered for one `(key, as)` pair, or null.
     *
     * Exists for the re-entrant-boot case, and the distinction it draws is the whole reason it is not
     * just `has($key)`: a provider whose `packageBooted()` runs twice (a host re-running it, a test
     * exercising it directly) must be able to recognise ITS OWN contribution and skip, while a
     * genuinely DIFFERENT package claiming the same pair still hits {@see register()}'s throw. Making
     * `register()` itself idempotent would collapse those two cases — and cannot be done honestly
     * anyway, since a contribution carries closures and two closures cannot be compared for identity.
     */
    public function contribution(string $key, string $as): ?ResourceContribution
    {
        return $this->contributions[$key][$as] ?? null;
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
