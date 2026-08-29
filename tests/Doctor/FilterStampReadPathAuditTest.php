<?php

namespace Splicewire\Beam\Tests\Doctor;

use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Rushing\DataFilters\Facades\DataFilter;
use Rushing\DataFilters\Registry\ResourceDefinition as FilterResourceDefinition;
use Rushing\DataFilters\Registry\ResourceRegistry as FilterResourceRegistry;
use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Doctor\FilterStampReadPathAudit;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Routing\BeamRouteProxy;
use Splicewire\Beam\Tests\TestCase;

/**
 * The detector for the promise a hand-rolled exposure makes by SAYING so (api-surface-coherence 101).
 *
 * Every fixture route is built on a CONSTRUCTED `Router`, never the app's, for the reason
 * {@see FilterablePromiseAuditTest} records: an audit pointed at the harness's own route table measures
 * the harness. The filter registry is seeded explicitly with `[]` — the null default means *read config
 * later*, which is a different starting state.
 *
 * ⚠️ Every case that matters asserts the audit **fires** and names the substring that makes the finding
 * actionable. A suite of clean-case tests passes against an audit that returns `pass()` unconditionally.
 *
 * ⚠️ The three-way split is the point: `honoured` and `blind` are the SAME declaration differing only in
 * the handler body, so a pass on one and a warn on the other is evidence the audit reads the read path
 * rather than counting stamps.
 */
class FilterStampReadPathAuditTest extends TestCase
{
    private FilterResourceRegistry $filters;

    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filters = new FilterResourceRegistry([]);
        $this->router = new Router(new Dispatcher);
    }

    private function audit(): FilterStampReadPathAudit
    {
        return new FilterStampReadPathAudit($this->filters, $this->router);
    }

    private function registerFilter(string $key): void
    {
        $this->filters->registerDefinition(new FilterResourceDefinition(
            key: $key,
            data: StampSubject::class,
            query: StampSubject::class,
        ));
    }

    /** A route carrying exactly what `->beam()->inResource($key, filters: true)` stamps. */
    private function stamp(string $uri, string $key, array|string $action): void
    {
        $this->router->get($uri, $action)
            ->defaults(ParticleController::RESOURCE, $key)
            ->defaults(BeamRouteProxy::FILTERS_PROMISE, $key);
    }

    public function test_the_data_filters_registry_is_a_singleton_in_this_harness(): void
    {
        // The tripwire, not a behaviour test — `ResourceRegistry` is auto-resolvable, so without
        // `Rushing\DataFilters\ServiceProvider` in getPackageProviders() this is two objects and the
        // audit would read a registry nothing registered into.
        $this->assertSame(
            $this->app->make(FilterResourceRegistry::class),
            $this->app->make(FilterResourceRegistry::class),
        );
    }

    public function test_inResource_records_the_stamp_on_the_route_it_decorates(): void
    {
        // The construction test for the recording half. Without this default the audit's population is
        // empty and it passes by not running — this estate's recurring defect class.
        $route = $this->router->get('widgets', [StampedHandlers::class, 'blind']);

        (new BeamRouteProxy($route))->inResource('widgets', filters: true);

        $this->assertSame('widgets', $route->defaults[BeamRouteProxy::FILTERS_PROMISE] ?? null);
    }

    public function test_inResource_without_the_flag_records_nothing(): void
    {
        // The negative case for the same line. `inResource()` is stamped on sub-operations too, and a
        // sub-operation is not an index — recording every `inResource()` would make the whole audit noise.
        $route = $this->router->get('widgets/{id}/reject', [StampedHandlers::class, 'blind']);

        (new BeamRouteProxy($route))->inResource('widgets');

        $this->assertArrayNotHasKey(BeamRouteProxy::FILTERS_PROMISE, $route->defaults);
    }

    public function test_it_passes_when_no_route_carries_the_stamp(): void
    {
        $this->router->get('api/v1/widgets', [StampedHandlers::class, 'blind']);

        $findings = $this->audit()->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertStringContainsString('out of this audit\'s population by construction', $findings[0]->detail);
    }

    public function test_a_handler_that_rides_the_engine_is_not_a_finding(): void
    {
        // THE NEGATIVE CASE, and it differs from the failing one below ONLY in the handler body.
        $this->registerFilter('widgets');
        $this->stamp('api/v1/widgets', 'widgets', [StampedHandlers::class, 'converged']);

        $findings = $this->audit()->run();

        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertStringContainsString('does NOT claim the handler honours every DECLARED facet', $findings[0]->detail);
    }

    public function test_it_reports_a_stamped_route_whose_handler_composes_its_own_query(): void
    {
        $this->registerFilter('widgets');
        $this->stamp('api/v1/widgets', 'widgets', [StampedHandlers::class, 'blind']);

        $findings = $this->audit()->run();

        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('PUBLISHED-BUT-NOT-SERVED (1)', $findings[0]->detail);
        $this->assertStringContainsString('widgets (api/v1/widgets)', $findings[0]->detail);
        $this->assertStringContainsString('drop `filters: true`', $findings[0]->detail);
    }

    public function test_two_exposures_of_one_resource_are_judged_separately(): void
    {
        // `guest-links` verbatim: the flat exposure rides `DataFilter::query()`, the nested one
        // hard-codes the scope its own FilterData declares. One resource key, two verdicts — so the
        // audit must not collapse the population by key.
        $this->registerFilter('guest-links');
        $this->stamp('api/v1/guest-tokens', 'guest-links', [StampedHandlers::class, 'converged']);
        $this->stamp('api/v1/circuits/{circuit}/guest-tokens', 'guest-links', [StampedHandlers::class, 'blind']);

        $detail = $this->audit()->run()[0]->detail;

        $this->assertStringContainsString('PUBLISHED-BUT-NOT-SERVED (1)', $detail);
        $this->assertStringContainsString('circuits/{circuit}/guest-tokens', $detail);
        $this->assertStringNotContainsString('api/v1/guest-tokens →', $detail);
    }

    public function test_a_particle_controller_index_is_answered_structurally(): void
    {
        // Not by reading source: `ParticleController@index` IS the particle read path. Reading its body
        // would work today and break the day the hydrator call moves into a trait.
        //
        // ⚠️ This test does NOT discriminate, and the mutant was run rather than assumed: deleting the
        // structural arm leaves all 11 green, because `ParticleController::index()`'s own body contains
        // `hydrator->query(` and the source read reaches the same verdict by the route this arm exists to
        // stop depending on. It is recorded here rather than papered over — the arm is a hedge against a
        // future refactor, and nothing in this file can currently prove it fires.
        $this->registerFilter('widgets');
        $this->stamp('api/v1/widgets', 'widgets', [ParticleController::class, 'index']);

        $this->assertSame(DoctorStatus::Pass, $this->audit()->run()[0]->status);
    }

    public function test_it_reports_a_stamp_naming_a_key_no_registry_carries(): void
    {
        // The silent half: `mountFilterSubSurface()` returns early, so this declaration mounted NOTHING
        // and said so nowhere. It is reported separately from the read-path failure because the repair
        // is different — register the resource, or delete the argument.
        $this->stamp('api/v1/widgets', 'widgets', [StampedHandlers::class, 'converged']);

        $detail = $this->audit()->run()[0]->detail;

        $this->assertStringContainsString('STAMP REFUSED (1)', $detail);
        $this->assertStringContainsString('widgets (api/v1/widgets)', $detail);
        $this->assertStringNotContainsString('PUBLISHED-BUT-NOT-SERVED', $detail);
    }

    public function test_a_closure_action_is_reported_as_unclassified_rather_than_clean(): void
    {
        // "No opinion" must not read as a pass. An audit that silently drops what it cannot inspect is
        // the instrument-reports-success-by-not-running shape this package documents seven times.
        $this->registerFilter('widgets');
        $this->router->get('api/v1/widgets', fn () => null)
            ->defaults(BeamRouteProxy::FILTERS_PROMISE, 'widgets');

        $findings = $this->audit()->run();

        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('NOT CLASSIFIED (1)', $findings[0]->detail);
        $this->assertStringContainsString('not a pass', $findings[0]->detail);
    }

    public function test_a_handler_that_delegates_one_level_to_the_engine_is_not_a_finding(): void
    {
        // `FragmentController::index()` verbatim: the handler names a query-builder facade and the
        // facade names the engine. THREE of the flagship's twelve stamped routes read this way, and a
        // body-only test reported all three as defects — half the finding would have been noise on its
        // first run. Measured before this arm existed, not imagined.
        $this->registerFilter('widgets');
        $this->stamp('api/v1/widgets', 'widgets', [StampedHandlers::class, 'delegating']);

        $this->assertSame(DoctorStatus::Pass, $this->audit()->run()[0]->status);
    }

    public function test_delegation_two_levels_deep_is_still_reported(): void
    {
        // The declared blind spot, pinned so it is a KNOWN limit rather than a surprise: the audit
        // follows exactly one hop, and the finding says so. A reader who meets this row confirms by
        // issuing a facet against the route.
        $this->registerFilter('widgets');
        $this->stamp('api/v1/widgets', 'widgets', [StampedHandlers::class, 'delegatingTwice']);

        $this->assertStringContainsString('PUBLISHED-BUT-NOT-SERVED (1)', $this->audit()->run()[0]->detail);
    }

    public function test_the_finding_states_what_its_read_path_test_cannot_see(): void
    {
        // A one-level source read has two blind spots in opposite directions, and a reader acting on a
        // row needs both named next to the row.
        $this->registerFilter('widgets');
        $this->stamp('api/v1/widgets', 'widgets', [StampedHandlers::class, 'blind']);

        $detail = $this->audit()->run()[0]->detail;

        $this->assertStringContainsString('ONE-LEVEL source read', $detail);
        $this->assertStringContainsString('delegates its query to a service', $detail);
    }
}

/** A backing/Data stand-in — never instantiated; the audit reads keys, routes and method bodies. */
class StampSubject {}

/**
 * The two handler shapes the audit has to tell apart. Their bodies ARE the fixture: the audit reads the
 * action method's own source, so these must remain real method bodies rather than stubs.
 */
class StampedHandlers
{
    /** Rides the engine the stamp publishes. */
    public function converged(Request $request)
    {
        return DataFilter::query('widgets')->apply($request)->get();
    }

    /** Composes its own query and never consults the declared vocabulary. */
    public function blind(Request $request)
    {
        return StampSubject::class;
    }

    /** Names a facade rather than the engine — the shape three flagship indexes actually have. */
    public function delegating(Request $request)
    {
        return StampedFacade::forUser($request);
    }

    /** One hop further than the audit follows. */
    public function delegatingTwice(Request $request)
    {
        return StampedFacade::indirect($request);
    }
}

/** The query-builder facade half of the delegation fixture. Its BODY is what the audit reaches. */
class StampedFacade
{
    public static function forUser(Request $request)
    {
        return DataFilter::query('widgets')->apply($request);
    }

    public static function indirect(Request $request)
    {
        return self::forUser($request);
    }
}
