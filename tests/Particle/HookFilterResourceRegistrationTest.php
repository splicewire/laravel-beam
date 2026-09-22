<?php

namespace Splicewire\Beam\Tests\Particle;

use Rushing\DataFilters\Facades\DataFilter;
use Rushing\DataFilters\Query\ResourceQuery;
use Rushing\DataFilters\Registry\ResourceDefinition;
use Rushing\DataFilters\Registry\ResourceRegistry;
use Splicewire\Beam\BeamServiceProvider;
use Splicewire\Beam\Data\HookData;
use Splicewire\Beam\Models\Hook;
use Splicewire\Beam\Particle\ParticleListQuery;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Query\HookResourceQuery;
use Splicewire\Beam\Tests\TestCase;

class HookFilterResourceRegistrationTest extends TestCase
{
    public function test_resolves_the_declared_filter_query(): void
    {
        $query = DataFilter::query('hooks');

        $this->assertInstanceOf(ResourceQuery::class, $query);
        $this->assertInstanceOf(HookResourceQuery::class, $query);
    }

    public function test_resolves_one_registry(): void
    {
        $this->assertSame(app(ResourceRegistry::class), app(ResourceRegistry::class));
    }

    public function test_resolves_the_backing_model_through_the_particle_registry(): void
    {
        $this->assertSame(Hook::class, DataFilter::resource('hooks')->requireModel());
    }

    public function test_list_composition_applies_the_declared_scope(): void
    {
        $resource = app(ParticleResourceRegistry::class)->get('hooks');
        $composed = app(ParticleListQuery::class)->forList($resource)->toSql();

        $this->assertNotSame(Hook::query()->toSql(), $composed);
        $this->assertStringContainsString('order by', $composed);
    }

    public function test_reads_the_scope_off_the_particle_dto_rather_than_restating_it(): void
    {
        $this->assertTrue(method_exists(HookData::class, 'scope'));
    }

    public function test_never_stomps_a_host_that_registered_the_key_first(): void
    {
        app(ResourceRegistry::class)->registerDefinition(new ResourceDefinition(
            key: 'hooks',
            data: HookData::class,
            query: HostOwnedHookQuery::class,
        ));

        // Re-run the registration STEP rather than rebooting the provider: the guard is what is under
        // test, not the boot order.
        $provider = new BeamServiceProvider(app());
        (fn () => $this->declareFilterResources())->call($provider);

        $this->assertInstanceOf(HostOwnedHookQuery::class, DataFilter::query('hooks'));
    }
}

class HostOwnedHookQuery extends ResourceQuery {}
