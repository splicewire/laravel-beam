<?php

namespace Splicewire\Beam\Particle\Backing;

use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * The REVERSE index: Data class → the resource key(s) that declare it.
 *
 * The Data-class twin of {@see ModelResourceIndex}, and deliberately the same shape: one-to-MANY,
 * "first in registration order" for the single-answer read, absent rather than erroring for a class
 * nothing declares. Sharing the shape is the point — a reader who knows one knows this.
 *
 * ## Three slots, one class
 *
 * A resource names Data classes in three places: `data` (the read projection), `input` and `editData`
 * (the write DTOs). Two of them routinely name the SAME class — `PlanEditData` is both `input` and
 * `editData` on `plans` — so a slot is not an occurrence, and a class named twice on one resource
 * lists that resource once.
 *
 * ## One-to-many, for the same reason as its sibling
 *
 * ⚠️ A realm-varied twin may declare the same Data class from a second resource, exactly as two
 * resources may legitimately share a model. So this is one-to-many and a duplicate is not an error.
 * Inheriting `SchemaBindingIndex`'s throw would hard-fail boot on a legal declaration.
 *
 * Its first caller is the fixture seam: `HasParticleFixtures::fixtureKey()` prefers a short declared
 * resource key and falls back to a class key, so the ambiguity `keyFor()` resolves by registration
 * order is a fixture naming choice, never a routing one.
 */
class DataResourceIndex
{
    public function __construct(private ParticleResourceRegistry $registry) {}

    /**
     * Every Data-class→keys pair, one-to-many.
     *
     * @return array<class-string, list<string>>
     */
    public function all(): array
    {
        $classes = [];

        foreach ($this->registry->all() as $resource) {
            foreach ($this->dataClassesFor($resource) as $class) {
                if (! in_array($resource->key, $classes[$class] ?? [], true)) {
                    $classes[$class][] = $resource->key;
                }
            }
        }

        return $classes;
    }

    /**
     * The resource key(s) declaring the given Data class, registration order. Empty when none do.
     *
     * @param  class-string  $dataClass
     * @return list<string>
     */
    public function keysFor(string $dataClass): array
    {
        return $this->all()[$dataClass] ?? [];
    }

    /**
     * The FIRST resource key declaring the given Data class, or null.
     *
     * Deliberately not "the" key, for the reason {@see ModelResourceIndex::keyFor()} gives: the index
     * is one-to-many, so a caller needing a single answer is making a choice, and registration order
     * is the only ordering there is. A caller that cannot tolerate ambiguity reads {@see keysFor()}.
     *
     * @param  class-string  $dataClass
     */
    public function keyFor(string $dataClass): ?string
    {
        return $this->keysFor($dataClass)[0] ?? null;
    }

    /**
     * The Data classes a declaration names, deduplicated across its three slots.
     *
     * @return list<class-string>
     */
    protected function dataClassesFor(ParticleResource $resource): array
    {
        $classes = [];

        foreach ([$resource->data, $resource->input, $resource->editData] as $class) {
            if (is_string($class) && $class !== '' && ! in_array($class, $classes, true)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }
}
