<?php

namespace Splicewire\Beam\Particle\Fixtures;

use Schemastud\DataSchemas\Fixtures\HasFixtures;
use Splicewire\Beam\Particle\Backing\DataResourceIndex;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * Beam's tier of the fixture seam: a declared shape keys by its short RESOURCE name, and everything
 * else falls back to the class key `schemastud/laravel-data-schemas` supplies.
 *
 * The whole tier override is one method. That is the shape {@see HasFixtures} was built for — its two
 * seams are `factoryRegistry()` (which registry answers) and `fixtureKey()` (what this class is keyed
 * by there) — and this changes only the second.
 *
 * ## A trait, for the reason the tier below already established
 *
 * Not a method on {@see \Splicewire\Beam\Data\Data}. `StudData`'s docblock records that the `extends`
 * slot is contested — six abstract Data bases exist in this family, `SyncData` among them — so a
 * base-class method reaches only what already extends that base. A trait reaches anything, and
 * `DerivesJsonSchema` is the precedent.
 *
 * ## Fallback, not refusal
 *
 * A shape with no declared resource is **not an error**; it simply has no shorter name to be known
 * by, and the class key names it unambiguously. Refusing would make this tier's opinion about
 * declaration into a precondition for having fixtures at all, which is a larger claim than the seam
 * needs and one the tier below deliberately does not make.
 *
 * ## Ancestry
 *
 * A resource names the ancestor, so a host narrowing a package DTO — `class MyPlanData extends
 * PlanEditData` — must still resolve to `plans`. The walk is why: without it the seam works on every
 * declared class and breaks the moment anyone subclasses one, which is exactly the shape the
 * registry-contributed-states design exists to serve.
 */
trait HasParticleFixtures
{
    use HasFixtures {
        fixtureKey as protected classFixtureKey;
    }

    protected static function fixtureKey(): string
    {
        $index = new DataResourceIndex(app(ParticleResourceRegistry::class));

        foreach ([static::class, ...array_values(class_parents(static::class) ?: [])] as $candidate) {
            $key = $index->keyFor($candidate);

            if ($key !== null) {
                return $key;
            }
        }

        return static::classFixtureKey();
    }
}
