<?php

namespace Splicewire\Beam\Particle\Contribution;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Particle\Backing\StreamsRecords;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Read\ReadContext;
use Splicewire\Beam\Realm\RealmResourceRegistry;

/**
 * ONE package's named slice of ANOTHER package's read projection — the declaration behind the
 * particle contribution seam (particle-contribution-seam ticket 04).
 *
 * ## The direction is fixed, and a dependency guard is what fixes it
 *
 * The **contributor declares**; the owner names nothing and pre-declares no hook. That is not a style
 * preference — `laravel-beam-commerce` REQUIRES `laravel-beam-tenancy`, so commerce can reach DOWN to
 * `tenants` while tenancy naming a single `Splicewire\Beam\Commerce\*` symbol would close a dependency
 * cycle (asserted mechanically by a seam guard in beam-tenancy's own suite). An owner-pre-names-a-hook
 * shape would require exactly that symbol, so contributor-declares is the only direction that leaves the
 * guard standing (ticket 04 §A1).
 *
 * It is also the only ORDER-SAFE direction available. Provider boot order is alphabetical and permanent:
 * beam-commerce boots at position 4, beam-tenancy at 13, so the contributor boots BEFORE the owner and
 * the owner's declaration does not exist yet when the contribution registers (ticket 07). A contribution
 * is therefore stored against a resource KEY — a string — and resolved at READ time, never merged into a
 * declaration at boot.
 *
 * ## The two arms, and why they are two
 *
 * A contribution carries a `data` slice class and up to two arms, because a contributed slice has a
 * QUERY concern and a VALUE concern and they resolve at different times:
 *
 *  - {@see $includes} is an **eager-load**, folded into the owner's declared includes so the slice's
 *    relations ride the one list query instead of N per-row loads. `list<string>` is the ordinary case
 *    and folds in {@see ParticleResourceRegistry::get()}, which stays a pure
 *    declaration lookup. The `Closure` form exists for the one job a static list provably cannot do —
 *    a **request-parameterized constrained eager-load** (`with(['bills' => fn ($q) => $q->forPeriod($period)])`,
 *    ticket 05 §A3) — and resolves ONCE PER REQUEST against the facet bag, never per record.
 *  - {@see $value} computes the slice for one record at projection time.
 *
 * ## The facet bag is the third argument, and that is a correction
 *
 * Ticket 04 §A5 ruled both arms take the {@see ReadContext} "which is what carries `filter[period]`".
 * It does not: `ReadContext` is `(includes, cardinality, actor, version)` and its own docblock rules the
 * facet bag out by name — *"filters/sorts … stay on the request/QueryBuilder (the context owns
 * projection, never selection)"*. So the period rides the **opaque facet array** as a third argument
 * (ticket 05 §A3), the same bag a {@see StreamsRecords} backing is
 * already handed. Do not widen `ReadContext` to carry it.
 *
 * ## What this is NOT
 *
 * Not an override. {@see ResourceContributionRegistry} rejects a duplicate `(key, as)` at registration
 * rather than superseding it — deliberately unlike the per-realm presentation overlay
 * ({@see RealmResourceRegistry}), whose whole job is last-wins. Two packages
 * claiming one sub-projection key is a wiring conflict with no correct silent resolution.
 *
 * @see ParticleResource the declaration a contribution attaches to
 */
class ResourceContribution
{
    /**
     * @param  string  $key  the OWNER resource's registry key (e.g. `'tenants'`) — a string, never a
     *                       class reference, because the owner's declaration need not exist yet when this
     *                       registers (the contributor boots first; see the class docblock).
     * @param  string  $as  the sub-projection key the slice lands under in the projected row (e.g.
     *                      `'commerce'` ⇒ `row.commerce`). Unique per `$key` — a duplicate `(key, as)`
     *                      throws at registration.
     * @param  class-string<Data>  $data  the slice's own Data class, shipped by the CONTRIBUTOR. Strongly
     *                                    typed rather than an array bag: it is what makes the slice
     *                                    generate a TypeScript type of its own.
     * @param  list<string>|(Closure(array<string, mixed> $filters): list<string>)  $includes
     *                                                                                         relations the slice needs eager-loaded onto the owner's query. A plain list
     *                                                                                         folds statically at declaration-lookup time; a Closure resolves once per
     *                                                                                         request against the facet bag, for the constrained-eager-load case only.
     * @param  (Closure(Model $record, ReadContext $ctx, array<string, mixed> $filters): ?Data)|null  $value
     *                                                                                                        computes the slice for one record. Returning `null` is MEANINGFUL and distinct
     *                                                                                                        from not contributing at all — see {@see ResourceContributionRegistry} for the
     *                                                                                                        absent-vs-present-and-null rule (ticket 04 §A7). Null (no closure) ⇒ an
     *                                                                                                        includes-only contribution, which contributes no key to the row.
     */
    public function __construct(
        public string $key,
        public string $as,
        public string $data,
        public array|Closure $includes = [],
        public ?Closure $value = null,
    ) {}

    /**
     * The relations this contribution needs eager-loaded, for a request whose facet bag is `$filters`.
     *
     * The static arm ignores `$filters` entirely — which is exactly why it can fold in a pure
     * declaration lookup with no request in scope.
     *
     * ⚠️ The return is whatever Eloquent's `->with()` accepts, and the two arms legitimately return
     * DIFFERENT shapes: the static arm a plain `list<string>`, the dynamic arm a `relation => Closure`
     * map (`['bills' => fn ($q) => $q->forPeriod($period)]`) — the constrained eager-load is the entire
     * reason the Closure form exists. So string keys are PRESERVED here; flattening them to a list
     * would silently drop the constraint and re-load every row's bills.
     *
     * @param  array<string, mixed>  $filters  the opaque facet bag (`filter[...]`)
     * @return array<array-key, string|Closure>
     */
    public function includes(array $filters = []): array
    {
        return $this->includes instanceof Closure
            ? ($this->includes)($filters)
            : array_values($this->includes);
    }

    /**
     * Whether this contribution's includes arm needs a request to resolve — i.e. whether it is the
     * constrained-eager-load form that {@see ParticleResourceRegistry::get()}
     * deliberately does NOT fold.
     */
    public function isDynamic(): bool
    {
        return $this->includes instanceof Closure;
    }
}
