<?php

namespace Splicewire\Beam\Tests\Particle;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Rushing\DataFilters\Facades\DataFilter;
use Rushing\DataFilters\Registry\ResourceDefinition as FilterResourceDefinition;
use Splicewire\Beam\Doctor\UngatedResourceReadAudit;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\Fixtures\ReadGuard\BareGadgetQuery;
use Splicewire\Beam\Tests\Fixtures\ReadGuard\Gadget;
use Splicewire\Beam\Tests\Fixtures\ReadGuard\GadgetData;
use Splicewire\Beam\Tests\Fixtures\ReadGuard\GadgetPolicy;
use Splicewire\Beam\Tests\Fixtures\ReadGuard\OwnedGadgetQuery;
use Splicewire\Beam\Tests\TestCase;

/**
 * beam-docs-satellite 65: an UNSCOPED, POLICY-LESS standalone list read fails closed ON READ — 403 to a
 * caller no `Gate::before` waves through — and every other list read answers exactly as before.
 *
 * "Unscoped" is read off the WIRE, not the source: a `filterable` resource is scoped when its data-filters
 * base query carries a predicate, a non-filterable one when it declares a `scope` closure. An overridden
 * `baseQuery()` that only orders (`BareGadgetQuery`, the `HookResourceQuery` shape) is unscoped.
 *
 * ⚠️ Every mount here MOUNTS. The first cut of this ruling refused the mount at boot and took two hosts
 * down with it; the corrected shape (AGENTS.md: a check whose answer depends on the host must not throw)
 * registers the route and computes the gate on read. {@see UngatedResourceReadAudit}
 * is the advisory that names the mounts this gate would deny.
 */
class UngatedIndexReadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('gadgets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
        });

        Gadget::create(['user_id' => 1]);
        Gadget::create(['user_id' => 2]);

        $this->actingAs(new ReadGuardUser);
    }

    private function declare(bool $filterable, ?\Closure $scope = null): void
    {
        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'gadgets',
            backing: Gadget::class,
            data: GadgetData::class,
            filterable: $filterable,
            scope: $scope,
            project: fn (Gadget $gadget) => new GadgetData((string) $gadget->id),
            frame: false,
        ));
    }

    private function filterWith(string $query): void
    {
        DataFilter::registry()->registerDefinition(new FilterResourceDefinition(
            key: 'gadgets',
            data: GadgetData::class,
            query: $query,
            model: Gadget::class,
        ));
    }

    public function test_a_non_filterable_resource_with_no_scope_and_no_policy_answers_403_on_a_bare_mount(): void
    {
        $this->declare(filterable: false);
        Particle::mount('gadgets')->only(['index'])->register();

        $this->getJson('/gadgets')->assertForbidden();
    }

    public function test_a_filterable_resource_whose_base_query_only_orders_answers_403(): void
    {
        $this->declare(filterable: true);
        $this->filterWith(BareGadgetQuery::class);
        Particle::mount('gadgets')->only(['index'])->register();

        $this->getJson('/gadgets')->assertForbidden();
    }

    public function test_the_mount_still_registers_so_the_host_boots(): void
    {
        $this->declare(filterable: false);
        Particle::mount('gadgets')->only(['index'])->register();

        Route::getRoutes()->refreshNameLookups();
        $this->assertNotNull(Route::getRoutes()->getByName('gadgets.index'));
    }

    public function test_a_gate_before_superuser_still_reads(): void
    {
        $this->declare(filterable: false);
        Gate::before(fn () => true);
        Particle::mount('gadgets')->only(['index'])->register();

        $this->getJson('/gadgets')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_a_tenancy_group_supplies_the_scope(): void
    {
        $this->declare(filterable: false);
        Route::middleware('tenant')->group(fn () => Particle::mount('gadgets')->only(['index'])->register());
        // The alias is a signal, not a middleware this harness ships; resolve it to a pass-through.
        app('router')->aliasMiddleware('tenant', PassThroughTenancy::class);

        $this->getJson('/gadgets')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_a_declared_scope_closure_is_the_gate(): void
    {
        $this->declare(filterable: false, scope: fn ($q) => $q->where('user_id', 1));
        Particle::mount('gadgets')->only(['index'])->register();

        $this->getJson('/gadgets')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_predicated_base_query_is_the_gate(): void
    {
        $this->declare(filterable: true);
        $this->filterWith(OwnedGadgetQuery::class);
        Particle::mount('gadgets')->only(['index'])->register();

        $this->getJson('/gadgets')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_bound_policy_lets_the_index_through_unchanged(): void
    {
        $this->declare(filterable: false);
        Gate::policy(Gadget::class, GadgetPolicy::class);
        Particle::mount('gadgets')->only(['index'])->register();

        $this->getJson('/gadgets')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_a_relative_mount_lists_through_its_parent_and_is_not_denied(): void
    {
        $this->declare(filterable: false);
        Particle::relative('parents', Gadget::class, fn (Gadget $parent) => Gadget::query()->where('user_id', $parent->user_id), function (): void {
            Particle::mount('gadgets')->only(['index'])->register();
        });

        $this->getJson('/parents/1/gadgets')->assertOk()->assertJsonCount(1, 'data');
    }
}

class ReadGuardUser extends User
{
    protected $table = 'users';

    public $exists = true;

    public function getAuthIdentifier()
    {
        return 1;
    }

    public function getKey()
    {
        return 1;
    }
}

class PassThroughTenancy
{
    public function handle($request, \Closure $next)
    {
        return $next($request);
    }
}
