<?php

namespace Splicewire\Beam\Tests\Particle;

use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Particle\Backing\ModelResourceIndex;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\TestCase;

/**
 * {@see ModelResourceIndex} — the model→resource-keys reverse index that replaced two identical
 * hand-rolled reflective copies (particle-contribution-seam ticket 11 §A7).
 *
 * The cardinality case is the one that earns a test: 09's sibling `SchemaBindingIndex` THROWS on a
 * duplicate because a schema `$id` names one Data class, and inheriting that here would hard-fail boot
 * on a legal declaration — two resources sharing a model is a real, shipped shape.
 */
class ModelResourceIndexTest extends TestCase
{
    protected function registryWith(ParticleResource ...$resources): ParticleResourceRegistry
    {
        $registry = new ParticleResourceRegistry;

        foreach ($resources as $resource) {
            $registry->register($resource);
        }

        return $registry;
    }

    public function test_it_indexes_a_resource_by_its_model(): void
    {
        $index = new ModelResourceIndex($this->registryWith(
            new ParticleResource(key: 'silos', backing: FakeSilo::class),
        ));

        $this->assertSame([FakeSilo::class => ['silos']], $index->all());
        $this->assertSame(['silos'], $index->keysFor(FakeSilo::class));
        $this->assertSame('silos', $index->keyFor(FakeSilo::class));
    }

    public function test_two_resources_may_share_one_model_and_neither_is_dropped(): void
    {
        // The shipped shape: a writable owner-scoped resource and a public read-only projection over
        // one model (audiostud's `songs` + `listen-songs` over `Composition`).
        $index = new ModelResourceIndex($this->registryWith(
            new ParticleResource(key: 'songs', backing: FakeSilo::class),
            new ParticleResource(key: 'listen-songs', backing: FakeSilo::class),
        ));

        $this->assertSame(['songs', 'listen-songs'], $index->keysFor(FakeSilo::class));
    }

    public function test_a_duplicate_model_does_not_throw(): void
    {
        // Explicitly asserted, because the sibling SchemaBindingIndex does throw here and the two
        // indexes are one letter apart in intent. Getting this wrong fails boot on a legal declaration.
        $index = new ModelResourceIndex($this->registryWith(
            new ParticleResource(key: 'songs', backing: FakeSilo::class),
            new ParticleResource(key: 'listen-songs', backing: FakeSilo::class),
        ));

        $this->assertCount(2, $index->keysFor(FakeSilo::class));
    }

    public function test_registration_order_decides_key_for(): void
    {
        $index = new ModelResourceIndex($this->registryWith(
            new ParticleResource(key: 'listen-songs', backing: FakeSilo::class),
            new ParticleResource(key: 'songs', backing: FakeSilo::class),
        ));

        $this->assertSame('listen-songs', $index->keyFor(FakeSilo::class));
    }

    public function test_an_unbacked_model_yields_nothing(): void
    {
        $index = new ModelResourceIndex($this->registryWith(
            new ParticleResource(key: 'silos', backing: FakeSilo::class),
        ));

        $this->assertSame([], $index->keysFor(FakeTag::class));
        $this->assertNull($index->keyFor(FakeTag::class));
    }

    public function test_an_empty_registry_yields_an_empty_index(): void
    {
        $this->assertSame([], (new ModelResourceIndex($this->registryWith()))->all());
    }
}

class FakeSilo extends Model {}

class FakeTag extends Model {}
