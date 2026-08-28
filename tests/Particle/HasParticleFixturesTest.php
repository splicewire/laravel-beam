<?php

namespace Splicewire\Beam\Tests\Particle;

use Rushing\Popcorn\Registries\ClassKey;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\Fixtures\BeamFixtureShapeData;
use Splicewire\Beam\Tests\TestCase;

/**
 * The tier-2 half of the fixture seam: beam overrides ONE method — `fixtureKey()` — so a declared
 * shape keys by its short resource name, and everything else falls back to the class key
 * `schemastud/laravel-data-schemas` supplies.
 *
 * Fallback rather than refusal is the owner's call, and it is the right one for a tier that does not
 * own every Data class in the estate: a shape with no resource is not an error, it just has no
 * shorter name to be known by.
 */
class HasParticleFixturesTest extends TestCase
{
    private function declare(string $key, string $dataClass): void
    {
        app(ParticleResourceRegistry::class)->register(
            new ParticleResource(key: $key, backing: FakeFixturePlan::class, editData: $dataClass),
        );
    }

    public function test_a_declared_shape_keys_by_its_short_resource_name(): void
    {
        $this->declare('plans', BeamFixtureShapeData::class);

        $this->assertSame('plans', BeamFixtureShapeData::exposedFixtureKey());
    }

    /** The fallback: undeclared is not an error, it just has no shorter name. */
    public function test_an_undeclared_shape_falls_back_to_the_class_key(): void
    {
        $this->assertSame(
            (string) ClassKey::of(BeamFixtureShapeData::class),
            BeamFixtureShapeData::exposedFixtureKey(),
        );
    }

    /**
     * A resource names the ANCESTOR, so a host narrowing a package DTO must still resolve. This is the
     * case that broke the first prototype of this seam.
     */
    public function test_a_subclass_of_a_declared_shape_resolves_through_its_ancestor(): void
    {
        $this->declare('plans', BeamFixtureShapeData::class);

        $this->assertSame('plans', NarrowedBeamFixtureShapeData::exposedFixtureKey());
    }

    public function test_the_factory_builds_under_the_resource_key(): void
    {
        $this->declare('plans', BeamFixtureShapeData::class);

        app(\Schemastud\DataSchemas\Fixtures\FixtureIndex::class)
            ->defineShape('plans', fn () => ['name' => 'Starter'])
            ->defineState('plans', 'enterprise', fn (array $b) => ['name' => 'Enterprise']);

        $this->assertSame('Enterprise', BeamFixtureShapeData::factory()->enterprise()->make()->name);
    }
}

class NarrowedBeamFixtureShapeData extends BeamFixtureShapeData {}
