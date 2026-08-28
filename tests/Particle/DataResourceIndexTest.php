<?php

namespace Splicewire\Beam\Tests\Particle;

use Splicewire\Beam\Particle\Backing\DataResourceIndex;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Tests\TestCase;

/**
 * The Data-class half of the reverse index {@see \Splicewire\Beam\Particle\Backing\ModelResourceIndex}
 * already does for models — same shape, same one-to-MANY cardinality, same "first, in registration
 * order" rule for the single-answer read.
 *
 * One-to-many is not defensive here either: `data`, `input` and `editData` are three slots on one
 * resource and two of them routinely name the SAME class (`PlanEditData` is both `input` and
 * `editData` on `plans`), while a realm-varied twin can name it again from a second resource.
 */
class DataResourceIndexTest extends TestCase
{
    private function indexFor(ParticleResource ...$resources): DataResourceIndex
    {
        $registry = new ParticleResourceRegistry;

        foreach ($resources as $resource) {
            $registry->register($resource);
        }

        return new DataResourceIndex($registry);
    }

    public function test_it_indexes_the_read_projection_slot(): void
    {
        $index = $this->indexFor(new ParticleResource(key: 'plans', backing: FakeFixturePlan::class, data: 'App\Data\PlanData'));

        $this->assertSame(['plans'], $index->keysFor('App\Data\PlanData'));
    }

    public function test_it_indexes_the_write_slots_too(): void
    {
        $index = $this->indexFor(new ParticleResource(
            key: 'plans',
            backing: FakeFixturePlan::class,
            input: 'App\Data\PlanEditData',
            editData: 'App\Data\PlanEditData',
        ));

        // Named twice on one resource, listed once — a slot is not an occurrence.
        $this->assertSame(['plans'], $index->keysFor('App\Data\PlanEditData'));
    }

    public function test_a_class_named_by_two_resources_maps_to_both_in_registration_order(): void
    {
        $index = $this->indexFor(
            new ParticleResource(key: 'plans', backing: FakeFixturePlan::class, data: 'App\Data\PlanData'),
            new ParticleResource(key: 'admin-plans', backing: FakeFixturePlan::class, data: 'App\Data\PlanData'),
        );

        $this->assertSame(['plans', 'admin-plans'], $index->keysFor('App\Data\PlanData'));
        $this->assertSame('plans', $index->keyFor('App\Data\PlanData'));
    }

    public function test_an_undeclared_class_is_absent_rather_than_an_error(): void
    {
        $index = $this->indexFor(new ParticleResource(key: 'plans', backing: FakeFixturePlan::class, data: 'App\Data\PlanData'));

        $this->assertSame([], $index->keysFor('App\Data\Nothing'));
        $this->assertNull($index->keyFor('App\Data\Nothing'));
    }

    public function test_a_resource_declaring_no_data_class_is_simply_absent(): void
    {
        $index = $this->indexFor(new ParticleResource(key: 'bare', backing: FakeFixturePlan::class));

        $this->assertSame([], $index->all());
    }
}

class FakeFixturePlan extends Model {}
