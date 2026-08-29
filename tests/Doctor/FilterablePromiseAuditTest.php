<?php

namespace Splicewire\Beam\Tests\Doctor;

use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Router;
use Rushing\DataFilters\Registry\ResourceDefinition as FilterResourceDefinition;
use Rushing\DataFilters\Registry\ResourceRegistry as FilterResourceRegistry;
use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Doctor\FilterablePromiseAudit;
use Splicewire\Beam\Filters\Http\ResourceFiltersController;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\TestCase;

/**
 * The detector for the promise `#[ParticleResource]` makes by NOT opting out.
 *
 * Every registry here is CONSTRUCTED, never resolved off the container: `ParticleResourceRegistry` is
 * populated at boot by beam's own discovery and `FilterResourceRegistry` seeds itself from
 * `config('data-filters.resources')`, so an audit pointed at the app's copies would be measuring the
 * harness rather than the fixture. `new FilterResourceRegistry([])` is the explicit empty seed — the
 * null default means *read config later*, which is a different and much less legible starting state.
 *
 * ⚠️ The identity probe below is kept for the reason `tests/TestCase.php:70` records: this is the
 * package that documents six instances of the testbench trap and then produced a seventh. If
 * `Rushing\DataFilters\ServiceProvider` ever falls out of `getPackageProviders()`, `ResourceRegistry`
 * stays auto-resolvable and `app()` mints a fresh empty one per call — an audit reading it would report
 * a clean host by not running. That is this estate's recurring defect class, and the probe is one line.
 *
 * ⚠️ Every case that matters asserts the audit **fires** and names the substring that makes the finding
 * actionable. A suite of clean-case tests passes against an audit that returns `pass()` unconditionally.
 */
class FilterablePromiseAuditTest extends TestCase
{
    private ParticleResourceRegistry $resources;

    private FilterResourceRegistry $filters;

    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resources = new ParticleResourceRegistry;
        $this->filters = new FilterResourceRegistry([]);
        $this->router = new Router(new Dispatcher);
    }

    private function audit(): FilterablePromiseAudit
    {
        return new FilterablePromiseAudit($this->resources, $this->filters, $this->router);
    }

    private function declare(string $key, ?bool $filterable = null): void
    {
        // `$filterable` is left at the ATTRIBUTE's default when null — the whole point of the audit is
        // the declaration that says nothing, so the fixture has to be able to say nothing too.
        $this->resources->register($filterable === null
            ? new ParticleResource(key: $key, backing: PromiseSubject::class)
            : new ParticleResource(key: $key, backing: PromiseSubject::class, filterable: $filterable));
    }

    private function registerFilter(string $key): void
    {
        $this->filters->registerDefinition(new FilterResourceDefinition(
            key: $key,
            data: PromiseSubject::class,
            query: PromiseSubject::class,
        ));
    }

    private function mountIndex(string $key, string $uri): void
    {
        $this->router->get($uri, [ParticleController::class, 'index'])
            ->defaults(ParticleController::RESOURCE, $key);
    }

    public function test_the_data_filters_registry_is_a_singleton_in_this_harness(): void
    {
        // The tripwire, not a behaviour test. `ResourceRegistry` is auto-resolvable, so without
        // `Rushing\DataFilters\ServiceProvider` in getPackageProviders() this is two objects and every
        // registration in a booted provider lands in a throwaway.
        $this->assertSame(
            $this->app->make(FilterResourceRegistry::class),
            $this->app->make(FilterResourceRegistry::class),
        );
    }

    public function test_it_passes_when_no_resource_is_filterable(): void
    {
        $this->declare('opted-out', filterable: false);

        $findings = $this->audit()->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertStringContainsString('none of them filterable', $findings[0]->detail);
    }

    public function test_a_registered_filterable_resource_produces_no_finding(): void
    {
        // THE NEGATIVE CASE. Same declaration as the failing one below, differing only in the
        // counterpart registration — so a pass here is evidence the audit reads the second registry
        // rather than just counting the first.
        $this->declare('widgets');
        $this->registerFilter('widgets');

        $findings = $this->audit()->run();

        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertStringContainsString('every one has a data-filters resource', $findings[0]->detail);
        $this->assertStringContainsString('does not claim those filters are CORRECT', $findings[0]->detail);
    }

    public function test_it_reports_a_resource_that_never_declared_filterable_at_all(): void
    {
        // THE CASE THIS AUDIT EXISTS FOR, and it is the shape all four hand-repaired 500s had:
        // `filterable` is not written down anywhere, the attribute defaults it to true, and the
        // declaration promises a data-filters query that nothing registered.
        $this->declare('widgets');

        $findings = $this->audit()->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('1 of 1 filterable particle resource', $findings[0]->detail);
        $this->assertStringContainsString('defaults to true, so the promise is made by not opting out', $findings[0]->detail);
        $this->assertStringContainsString('LATENT (1): widgets', $findings[0]->detail);
    }

    public function test_an_unmounted_resource_is_reported_as_latent(): void
    {
        $this->declare('widgets');
        // A stamped route that is NOT one of the two throwing paths. `show` reads its own subject and
        // never touches the filter registry — the flagship's `ingest-runs` is exactly this shape, and a
        // mount-map reading would have called it live.
        $this->router->get('api/v1/widgets/{id}', [ParticleController::class, 'show'])
            ->defaults(ParticleController::RESOURCE, 'widgets');

        $findings = $this->audit()->run();

        $this->assertStringContainsString('None is reachable on this host today', $findings[0]->detail);
        $this->assertStringContainsString('LATENT (1): widgets', $findings[0]->detail);
        $this->assertStringNotContainsString('LIVE', $findings[0]->detail);
    }

    public function test_a_mounted_index_is_reported_as_live(): void
    {
        $this->declare('widgets');
        $this->mountIndex('widgets', 'api/v1/widgets');

        $findings = $this->audit()->run();

        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('LIVE (1)', $findings[0]->detail);
        $this->assertStringContainsString('widgets (api/v1/widgets)', $findings[0]->detail);
    }

    public function test_the_filters_sub_surface_counts_as_live_on_its_own(): void
    {
        // `ResourceFiltersController::definition()` resolves the same registry entry, so a resource whose
        // only filtering route is `filters/schema` is just as broken as one with an index.
        $this->declare('widgets');
        $this->router->get('api/v1/widgets/filters/schema', [ResourceFiltersController::class, 'schema'])
            ->defaults(ParticleController::RESOURCE, 'widgets')
            ->defaults(ResourceFiltersController::CONFIG, ['resource' => 'widgets']);

        $findings = $this->audit()->run();

        $this->assertStringContainsString('LIVE (1)', $findings[0]->detail);
        $this->assertStringContainsString('filters/schema', $findings[0]->detail);
    }

    public function test_it_splits_a_mixed_population(): void
    {
        $this->declare('widgets');
        $this->declare('gadgets');
        $this->declare('registered');
        $this->declare('opted-out', filterable: false);
        $this->registerFilter('registered');
        $this->mountIndex('widgets', 'api/v1/widgets');

        $findings = $this->audit()->run();

        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('2 of 3 filterable particle resources', $findings[0]->detail);
        $this->assertStringContainsString('LIVE (1) — a route already routes through the throwing lookup', $findings[0]->detail);
        $this->assertStringContainsString('LATENT (1): gadgets', $findings[0]->detail);
        $this->assertStringNotContainsString('registered,', $findings[0]->detail);
    }

    public function test_it_is_registered_advisory_in_the_doctor_manifest(): void
    {
        // Advisory is the load-bearing half of this audit's design, so it is asserted rather than
        // documented. Both halves of its population are host facts; a gate here would fail hosts for a
        // composition they did not choose.
        $registrations = $this->app->make(BeamDoctorManifest::class)->registrations();

        $mine = array_values(array_filter(
            $registrations,
            fn ($registration) => $registration->audit === FilterablePromiseAudit::class,
        ));

        $this->assertCount(1, $mine, 'FilterablePromiseAudit is not registered in BeamDoctorManifest.');
        $this->assertFalse($mine[0]->gate, 'FilterablePromiseAudit must be advisory, never a gate.');
    }
}

/** A backing/Data stand-in — never instantiated; the audit reads keys and flags, never the class. */
class PromiseSubject {}
