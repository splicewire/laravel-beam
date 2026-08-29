<?php

namespace Splicewire\Beam\Particle\Backing;

use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * The REVERSE index: Eloquent model class → the resource key(s) backed by it.
 *
 * ## Why this exists as a class
 *
 * It was hand-rolled TWICE, identically, in `Surgeon\ParticleControllerRedundancyAudit` and
 * `Surgeon\ParticleOperationBypassAudit` — both reflecting into
 * {@see ParticleResourceRegistry}'s private `$resources` map, both carrying the same comment:
 * *"the registry exposes `has($key)` but not an enumeration."*
 *
 * ⚠️ **That belief was false.** {@see ParticleResourceRegistry::all()} exists and applies the identical
 * filter, so both copies reflected past a public method that already answered the question
 * (particle-contribution-seam ticket 11 §A7). This class is the one home; both audits read it.
 *
 * ## One-to-MANY, deliberately
 *
 * ⚠️ Two resources may legitimately share a model — a resource and its realm-varied twin — so a model
 * maps to a LIST of keys and a duplicate is not an error. This is the shape's only difference from the
 * *planned* sibling `SchemaBindingIndex` (ticket 09), which would be one-to-one and would **throw** on a
 * duplicate because a schema `$id` names exactly one Data class. Same shape, opposite cardinality;
 * inheriting that throw here would hard-fail boot on a legal declaration.
 *
 * ⚠️ **`SchemaBindingIndex` DOES NOT EXIST.** It is ticket 09's named answer and was never built — verified
 * 2026-08-29 by three differently-shaped instruments: no `class`/`interface` declaration anywhere under the
 * family package roots or any `~/Herd/<host>/app` (its only five occurrences estate-wide are prose, four of them
 * in this package); `git log -S` in beam and at the flagship names only the commits that wrote that prose;
 * and a booted `class_exists()` plus a composer classmap scan at `~/Herd/splicewire-app` resolve nothing,
 * with `BeamData` as a working control. Kept as a DESIGN NOTE because the reasoning is sound and someone
 * will build it; the sentence above no longer says it is here. This class's own cardinality argument does
 * not depend on the sibling existing — it stands on its own measurement, and reads as a comparison rather
 * than a dependency.
 *
 * ## Membership
 *
 * A resource appears here iff its backing declares {@see BacksModel} — the conditional tier rule (ticket
 * 11 §A7, restated assertably by ticket 01 §A5). A backing over pivot rows, or one genuinely spanning
 * two record types, has no model to be indexed by and is simply absent. That absence is not new: both
 * hand-rolled copies already filtered to `instanceof ParticleResource`, which excluded exactly the same
 * resources for a worse reason.
 */
class ModelResourceIndex
{
    public function __construct(private ParticleResourceRegistry $registry) {}

    /**
     * Every model→keys pair, one-to-many.
     *
     * @return array<class-string, list<string>>
     */
    public function all(): array
    {
        $models = [];

        foreach ($this->registry->all() as $resource) {
            $model = $this->modelFor($resource);

            if ($model !== null) {
                $models[$model][] = $resource->key;
            }
        }

        return $models;
    }

    /**
     * The resource key(s) backed by the given model, registration order. Empty when nothing backs it.
     *
     * @param  class-string  $model
     * @return list<string>
     */
    public function keysFor(string $model): array
    {
        return $this->all()[$model] ?? [];
    }

    /**
     * The FIRST resource key backed by the given model, or null.
     *
     * Deliberately not "the" key: the index is one-to-many, so a caller that needs a single answer is
     * making a choice, and registration order is the only ordering there is. A caller that cannot
     * tolerate ambiguity should read {@see keysFor()} and decide for itself.
     */
    public function keyFor(string $model): ?string
    {
        return $this->keysFor($model)[0] ?? null;
    }

    /**
     * The model a declaration is backed by, or null when its backing declares no single model.
     *
     * @return class-string|null
     */
    protected function modelFor(ParticleResource $resource): ?string
    {
        return $resource->modelClass();
    }
}
