<?php

namespace Splicewire\Beam\Particle\Backing;

use InvalidArgumentException;

/**
 * Turns whatever a declaration put in its `backing:` slot into a {@see ResourceBacking}.
 *
 * ## The slot is polymorphic on what you hand it
 *
 * Three accepted forms, and the discrimination is by TYPE rather than by a discriminator field — which
 * is the whole point of ticket 11's answer (`sourceKind` was a string standing in for a type test that
 * nothing ever branched on):
 *
 * | given | meaning |
 * |---|---|
 * | a {@see ResourceBacking} instance | used as-is |
 * | a `ResourceBacking` class-string | container-resolved at REQUEST time, so it can take constructor injection |
 * | any other class-string | read as a model class, wrapped in {@see EloquentBacking} — the ordinary case |
 *
 * That last row is what keeps ~30 ordinary declarations from having to name a backing class to state
 * the least interesting thing about themselves: `backing: Silo::class` reads as before while the
 * declaration carries no model field.
 *
 * ## Resolved at request time, never at boot
 *
 * A class-string is resolved through the container **per request**, matching what
 * `Schemastud\Frame\Contracts\UnionSource` documented and did (*"container-resolved at REQUEST time
 * (`app($def->source)`), never eagerly at boot, so it can take constructor injection"*). A backing that
 * needs the tenant connection or its sub-source repos gets them.
 *
 * Registration-time validation ({@see assertAffordancesWithinCapability()}) therefore inspects the
 * class, not an instance — it must not resolve a backing at boot just to type-check it.
 */
class BackingResolver
{
    /**
     * Resolve a declaration's `backing:` slot to a live {@see ResourceBacking}.
     *
     * @param  ResourceBacking|class-string  $backing
     */
    public function resolve(ResourceBacking|string $backing): ResourceBacking
    {
        if ($backing instanceof ResourceBacking) {
            return $backing;
        }

        if (is_a($backing, ResourceBacking::class, true)) {
            return app($backing);
        }

        // Anything else is a model class-string. Deliberately NOT validated here: the field this
        // replaced was a plain `public string $model` that nothing ever checked, and a declaration may
        // legitimately name a class this package cannot see — a host's model, or (in doc generation) a
        // deliberately fictional FQCN used to exercise the manifest without a database. Validating at
        // declaration time would reject both. A genuinely bogus class still fails at the first query,
        // exactly where `$model::query()` failed before.
        return new EloquentBacking($backing);
    }

    /**
     * The capability set a `backing:` slot WILL have once resolved, decided statically.
     *
     * Registration-time checks read this rather than resolving the backing — booting the container to
     * type-check a declaration would defeat request-time resolution and would run a backing's
     * constructor (and its injected dependencies) at boot.
     *
     * @param  ResourceBacking|class-string  $backing
     * @param  class-string  $capability
     */
    public function hasCapability(ResourceBacking|string $backing, string $capability): bool
    {
        if ($backing instanceof ResourceBacking) {
            return $backing instanceof $capability;
        }

        if (is_a($backing, ResourceBacking::class, true)) {
            return is_a($backing, $capability, true);
        }

        // Anything else is a model class-string, which becomes an EloquentBacking — every capability
        // except ResolvesRecord (a model-backed detail rides the declaration's own read projection).
        return is_a(EloquentBacking::class, $capability, true);
    }

    /**
     * The model a `backing:` slot backs, or null when it backs no single model.
     *
     * ⚠️ Unlike {@see hasCapability()} this RESOLVES a class-string backing, because
     * {@see BacksModel::modelClass()} is a method and there is no way to ask a class what it will
     * answer. That is safe here and not in the registration-time check: {@see ModelResourceIndex} is
     * built on demand, at request time, so resolving is exactly what the backing contract expects.
     *
     * A model class-string answers itself without resolving anything.
     *
     * @param  ResourceBacking|class-string  $backing
     * @return class-string|null
     */
    public function modelFor(ResourceBacking|string $backing): ?string
    {
        if (is_string($backing) && ! is_a($backing, ResourceBacking::class, true)) {
            return $backing;
        }

        $resolved = $this->resolve($backing);

        return $resolved instanceof BacksModel ? $resolved->modelClass() : null;
    }

    /**
     * Assert a declaration's affordances stay within what its backing can actually do — the
     * capability-is-the-ceiling rule (ticket 11 §A5), enforced at REGISTRATION.
     *
     * `creatable` / `editable` / `deletable` are what a resource **may** do; {@see WritesRecords} is
     * what its backing **can** do. An affordance opened against a backing that cannot write is a
     * declaration error, not a runtime 405 — the same constructor-time validation shape
     * `ParticleOperation::assertOutputMatchesKind()` already ships.
     *
     * Reads {@see hasCapability()}, so it never resolves the backing.
     *
     * @param  ResourceBacking|class-string  $backing
     * @param  array<string, bool>  $affordances  affordance name => whether the declaration opens it
     */
    public function assertAffordancesWithinCapability(string $key, ResourceBacking|string $backing, array $affordances): void
    {
        $opened = array_keys(array_filter($affordances));

        if ($opened === [] || $this->hasCapability($backing, WritesRecords::class)) {
            return;
        }

        $name = is_string($backing) ? $backing : $backing::class;

        throw new InvalidArgumentException(
            "Resource [{$key}] declares ".implode('/', $opened)." but its backing [{$name}] cannot write"
            .' (it does not implement '.WritesRecords::class.'). Capability is the ceiling: an affordance'
            .' may narrow what a backing can do, never widen it.'
        );
    }
}
