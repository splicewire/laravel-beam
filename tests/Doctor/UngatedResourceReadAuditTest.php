<?php

namespace Splicewire\Beam\Tests\Doctor;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Rushing\DataFilters\Facades\DataFilter;
use Rushing\DataFilters\Registry\ResourceDefinition as FilterResourceDefinition;
use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Authorization\ResourceReadGuard;
use Splicewire\Beam\Doctor\UngatedResourceReadAudit;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\Fixtures\ReadGuard\BareGadgetQuery;
use Splicewire\Beam\Tests\Fixtures\ReadGuard\Gadget;
use Splicewire\Beam\Tests\Fixtures\ReadGuard\GadgetData;
use Splicewire\Beam\Tests\Fixtures\ReadGuard\GadgetPolicy;
use Splicewire\Beam\Tests\TestCase;

class UngatedResourceReadAuditTest extends TestCase
{
    private ParticleResourceRegistry $resources;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resources = new ParticleResourceRegistry;
    }

    private function declare(string $key, ?\Closure $scope = null): void
    {
        $this->resources->register(new ParticleResource(
            key: $key,
            backing: Gadget::class,
            data: GadgetData::class,
            scope: $scope,
            frame: false,
        ));
    }

    /** A hand-written index — the shape the mount rule cannot see. */
    private function mountBare(string $key, string $uri): void
    {
        Route::get($uri, [ParticleController::class, 'index'])
            ->defaults(ParticleController::RESOURCE, $key)
            ->name("{$key}.index");
    }

    private function audit(): UngatedResourceReadAudit
    {
        return new UngatedResourceReadAudit(app(Router::class), $this->resources, ResourceReadGuard::forApp());
    }

    public function test_an_empty_registry_is_inconclusive_rather_than_clean(): void
    {
        $findings = $this->audit()->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertFalse($findings[0]->conclusive);
        $this->assertSame(UngatedResourceReadAudit::CHECK, $findings[0]->check);
    }

    public function test_a_bare_index_over_an_unscoped_policy_less_resource_warns_by_route_and_never_fails(): void
    {
        $this->declare('gadgets');
        $this->mountBare('gadgets', 'api/gadgets');

        $findings = $this->audit()->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertNotSame(DoctorStatus::Fail, $findings[0]->status);
        $this->assertStringContainsString('(resource [gadgets])', $findings[0]->detail);
        $this->assertStringContainsString('GET api/gadgets', $findings[0]->detail);
        $this->assertStringContainsString('no predicate', $findings[0]->detail);
        $this->assertStringContainsString('no policy', $findings[0]->detail);
        $this->assertStringContainsString('tenancy', $findings[0]->detail);
    }

    public function test_a_declared_query_resource_whose_base_only_orders_is_unscoped(): void
    {
        $this->declare('gadgets');
        DataFilter::registry()->registerDefinition(new FilterResourceDefinition(
            key: 'gadgets',
            data: GadgetData::class,
            query: BareGadgetQuery::class,
            model: Gadget::class,
        ));
        $this->mountBare('gadgets', 'api/gadgets');

        $findings = $this->audit()->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
    }

    public function test_a_tenancy_mounted_index_passes(): void
    {
        $this->declare('gadgets');
        Route::middleware('tenant')->group(fn () => $this->mountBare('gadgets', 'api/gadgets'));

        $findings = $this->audit()->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertTrue($findings[0]->conclusive);
    }

    public function test_a_scoped_resource_passes_on_a_bare_mount(): void
    {
        $this->declare('gadgets', scope: fn ($q) => $q->where('user_id', 1));
        $this->mountBare('gadgets', 'api/gadgets');

        $findings = $this->audit()->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
    }

    public function test_a_policy_bound_resource_passes_on_a_bare_mount(): void
    {
        $this->declare('gadgets');
        Gate::policy(Gadget::class, GadgetPolicy::class);
        $this->mountBare('gadgets', 'api/gadgets');

        $findings = $this->audit()->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertStringContainsString('1 policy-bound', $findings[0]->detail);
    }

    public function test_an_unmounted_ungated_resource_is_reported_latent_in_one_row(): void
    {
        $this->declare('gadgets');
        $this->declare('sprockets');

        $findings = $this->audit()->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('latent', $findings[0]->detail);
        $this->assertStringContainsString('[gadgets]', $findings[0]->detail);
        $this->assertStringContainsString('[sprockets]', $findings[0]->detail);
    }

    public function test_a_resource_the_guard_cannot_read_is_counted_not_passed(): void
    {
        // A request-dependent scope cannot be built in this audit context, so the
        // audit did not look — and says so, rather than reading the absence as clean.
        $this->declare('gadgets', scope: fn () => throw new \RuntimeException('No request context'));
        $this->mountBare('gadgets', 'api/gadgets');

        $findings = $this->audit()->run();

        $this->assertCount(1, $findings);
        $this->assertFalse($findings[0]->conclusive);
        $this->assertStringContainsString('1 resource could not be read', $findings[0]->detail);
        $this->assertStringContainsString('[gadgets]', $findings[0]->detail);
    }

    public function test_one_row_per_live_ungated_route(): void
    {
        $this->declare('gadgets');
        $this->declare('sprockets');
        $this->mountBare('gadgets', 'api/gadgets');
        $this->mountBare('sprockets', 'api/sprockets');

        $findings = $this->audit()->run();

        $this->assertCount(2, $findings);
        foreach ($findings as $finding) {
            $this->assertSame(DoctorStatus::Warn, $finding->status);
        }
    }
}
