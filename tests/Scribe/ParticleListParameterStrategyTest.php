<?php

namespace Splicewire\Beam\Tests\Scribe;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Tools\DocumentationConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use Rushing\DataFilters\Attributes\Filterable;
use Rushing\DataFilters\Attributes\Includable;
use Rushing\DataFilters\Attributes\Sortable;
use Rushing\DataFilters\Facades\DataFilter;
use Rushing\DataFilters\Operators\Exact;
use Rushing\DataFilters\Operators\Partial;
use Rushing\DataFilters\Query\ResourceQuery;
use Rushing\DataFilters\ServiceProvider as DataFiltersServiceProvider;
use Schemastud\DataSchemas\Attributes\Description;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Spatie\QueryBuilder\AllowedFilter;
use Splicewire\Beam\Discovery\Http\ResourceDiscoveryController;
use Splicewire\Beam\Filters\Http\ResourceFiltersController;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Routing\BeamRouteProxy;
use Splicewire\Beam\Scribe\Strategies\ParticleListParameterStrategy;
use Splicewire\Beam\Tests\TestCase;
use Splicewire\Beam\Webhooks\Http\HookEventCatalogController;

class ListFixtureModel extends Model
{
    protected $table = 'catalogs';
}

class ListFixtureHostController
{
    /** The estate's conventional collection action. */
    public function index() {}

    /** A SECOND collection action on one controller — `GuestTokenController@indexAll` at the flagship. */
    public function indexAll() {}

    /** A collection action under a third spelling, to show the gate does not enumerate names. */
    public function listAll() {}
}

enum ListFixtureStatus: string
{
    case Draft = 'draft';
    case Live = 'live';
}

class ListFixtureFilterData extends Data
{
    public function __construct(
        #[Filterable(Exact::class)]
        #[Sortable]
        public string|Optional|null $id = null,

        // Prose is declared with `#[Description]`, never a docblock (ticket 08's one rule) — the
        // schema generator reflects attributes.
        #[Description('The catalog\'s display name.')]
        #[Filterable(Partial::class)]
        #[Sortable]
        public string|Optional|null $name = null,

        // A RENAMED facet: the wire says `external_ref`, the property says `externalRef`. Joining the
        // schema to the set by property name instead of facet name would lose this one.
        #[Filterable(Exact::class, name: 'external_ref')]
        public string|Optional|null $externalRef = null,

        #[Filterable(Exact::class)]
        public ListFixtureStatus|Optional|null $status = null,

        #[Includable]
        public mixed $owner = null,

        #[Sortable(name: 'createdAt', column: 'created_at')]
        public string|Optional|null $createdAt = null,
    ) {}
}

class ListFixtureQuery extends ResourceQuery
{
    protected function baseQuery(Request $request): Builder
    {
        return ListFixtureModel::query();
    }

    /**
     * The escape hatch: a closure filter with no backing Data property, so it is invisible to the
     * generated schema and reachable ONLY through the query object.
     */
    protected function extraFilters(): array
    {
        return [AllowedFilter::scope('recentlyTouched')];
    }
}

/**
 * api-surface-coherence ticket 21 (deciding ticket 07) — a dissolved particle index documents its own
 * list contract off the declarations the route already carries.
 *
 * The two assertions that matter are the two the deciding ticket argued from: the SET comes from the
 * query object (so an `extraFilters()` closure is documented, not silently dropped), and the TYPES are
 * joined in from the schema by FACET name (so a `#[Filterable(name:)]` rename still finds its property).
 * Names are asserted as DERIVED, never literal — ticket 22 flips the wire to camelCase and must not
 * break a single assertion here.
 */
class ParticleListParameterStrategyTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        // beam requires data-filters but its base harness does not boot it: this strategy is the first
        // beam-side reader of the filter declarations, and the provider is what registers both the
        // resource registry and the `x-filter`/`x-sort` schema strategy the join reads.
        return [...parent::getPackageProviders($app), DataFiltersServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->singleton(ParticleResourceRegistry::class);

        DataFilter::resource('catalogs', [
            'data' => ListFixtureFilterData::class,
            'query' => ListFixtureQuery::class,
            'model' => ListFixtureModel::class,
        ]);
    }

    private function register(bool $filterable = true, int $perPage = 20): void
    {
        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'catalogs',
            backing: ListFixtureModel::class,
            data: ListFixtureFilterData::class,
            filterable: $filterable,
            perPage: $perPage,
        ));
    }

    private function endpoint(string $method = 'index', bool $particle = true): ExtractedEndpointData
    {
        $route = new Route(['GET'], 'catalogs', [
            'uses' => ParticleController::class.'@'.$method,
            'controller' => ParticleController::class.'@'.$method,
        ]);

        if ($particle) {
            $route->defaults(ParticleController::RESOURCE, 'catalogs');
        }

        return ExtractedEndpointData::fromRoute($route);
    }

    private function strategy(): ParticleListParameterStrategy
    {
        return new ParticleListParameterStrategy(new DocumentationConfig([]));
    }

    /** The parameter name for a filter facet, derived exactly the way the strategy derives it. */
    private function filterKey(string $facet): string
    {
        return config('query-builder.parameters.filter', 'filter')."[{$facet}]";
    }

    public function test_a_filterable_index_documents_all_four_axes(): void
    {
        $this->register(perPage: 25);

        $parameters = $this->strategy()($this->endpoint());

        foreach (['id', 'name', 'external_ref', 'status'] as $facet) {
            $this->assertArrayHasKey($this->filterKey($facet), $parameters);
        }

        $sort = config('query-builder.parameters.sort', 'sort');
        $include = config('query-builder.parameters.include', 'include');

        $this->assertStringContainsString('`createdAt`', $parameters[$sort]['description']);
        $this->assertStringContainsString('`owner`', $parameters[$include]['description']);

        $this->assertSame(1, $parameters[ParticleController::PAGE]['example']);
        $this->assertSame(25, $parameters[ParticleController::PER_PAGE]['example']);
        $this->assertSame('integer', $parameters[ParticleController::PER_PAGE]['type']);
    }

    public function test_an_escape_hatch_filter_is_documented_untyped_rather_than_dropped(): void
    {
        $this->register();

        $parameters = $this->strategy()($this->endpoint());

        // Present, because the SET came from the query object. Untyped, because the schema — which only
        // ever reflects properties — has nothing to say about a closure.
        $this->assertArrayHasKey($this->filterKey('recentlyTouched'), $parameters);
        $this->assertSame('string', $parameters[$this->filterKey('recentlyTouched')]['type']);
        $this->assertStringNotContainsString('operator', $parameters[$this->filterKey('recentlyTouched')]['description']);
    }

    public function test_a_renamed_facet_still_finds_its_property_and_carries_its_operator(): void
    {
        $this->register();

        $parameters = $this->strategy()($this->endpoint());

        $renamed = $parameters[$this->filterKey('external_ref')];

        $this->assertSame('string', $renamed['type']);
        $this->assertStringContainsString('`exact`', $renamed['description']);

        // The property's own prose wins over the derived fallback.
        $this->assertStringContainsString(
            'display name',
            $parameters[$this->filterKey('name')]['description'],
        );
    }

    public function test_a_finite_domain_carries_its_values(): void
    {
        $this->register();

        $parameters = $this->strategy()($this->endpoint());

        $this->assertSame(['draft', 'live'], $parameters[$this->filterKey('status')]['enumValues']);
    }

    public function test_a_non_filterable_resource_documents_pagination_only(): void
    {
        $this->register(filterable: false);

        $parameters = $this->strategy()($this->endpoint());

        $this->assertSame(
            [ParticleController::PAGE, ParticleController::PER_PAGE],
            array_keys($parameters),
        );
    }

    public function test_only_the_index_carries_the_list_contract(): void
    {
        $this->register();

        $this->assertSame([], $this->strategy()($this->endpoint('show')));
        $this->assertSame([], $this->strategy()($this->endpoint('store')));
    }

    public function test_a_non_particle_route_is_deferred_not_answered(): void
    {
        $this->register();

        // null, not [] — anything else would claim the endpoint and stop Scribe's own strategies running.
        $this->assertNull($this->strategy()($this->endpoint(particle: false)));
    }

    /**
     * api-surface-coherence 102. `->beam()->inResource($key, filters: true)` stamps this same route
     * default on a HAND-ROLLED exposure, and its argument is a **data-filters** key that need not also
     * carry a `#[ParticleResource]`. This used to `get()` and throw; Scribe caught per-route, printed
     * only under `-v`, and 30 live endpoints at the flagship were silently absent from `openapi.yaml`.
     */
    public function test_an_unregistered_particle_key_documents_its_filter_contract_instead_of_throwing(): void
    {
        // Deliberately NO ParticleResource registration — only the data-filters one from setUp().
        $parameters = $this->strategy()($this->endpoint());

        foreach (['id', 'name', 'external_ref', 'status', 'recentlyTouched'] as $facet) {
            $this->assertArrayHasKey($this->filterKey($facet), $parameters);
        }

        $this->assertArrayHasKey(config('query-builder.parameters.sort', 'sort'), $parameters);
        $this->assertArrayHasKey(config('query-builder.parameters.include', 'include'), $parameters);

        // No pagination: a hand-rolled index picks its own paging, so `perPage` would be an invention.
        $this->assertArrayNotHasKey(ParticleController::PAGE, $parameters);
        $this->assertArrayNotHasKey(ParticleController::PER_PAGE, $parameters);
    }

    public function test_a_key_registered_in_neither_registry_documents_no_query_contract(): void
    {
        $route = new Route(['GET'], 'nowhere', [
            'uses' => ParticleController::class.'@index',
            'controller' => ParticleController::class.'@index',
        ]);
        $route->defaults(ParticleController::RESOURCE, 'nowhere');

        $this->assertSame([], $this->strategy()(ExtractedEndpointData::fromRoute($route)));
    }

    /**
     * A route on an arbitrary controller/method, with arbitrary route defaults — the shape every
     * hand-mounted exposure and every sub-surface mount actually has.
     *
     * @param  array<string, mixed>  $defaults
     */
    private function routeFor(string $controller, string $method, array $defaults = [], string $uri = 'catalogs'): ExtractedEndpointData
    {
        $route = new Route(['GET'], $uri, [
            'uses' => $controller.'@'.$method,
            'controller' => $controller.'@'.$method,
        ]);

        $route->defaults(ParticleController::RESOURCE, 'catalogs');

        foreach ($defaults as $key => $value) {
            $route->defaults($key, $value);
        }

        return ExtractedEndpointData::fromRoute($route);
    }

    /**
     * api-surface-coherence 103, the under-trigger half. `GET /api/v1/guest-tokens` is served by
     * `GuestTokenController@indexAll` and published ZERO parameters while its circuit-scoped twin
     * published three — same resource, same data-filters key, different documentation.
     *
     * `filters: true` is the declaration that fixes it: {@see BeamRouteProxy::mountFilterSubSurface()}
     * defines the flag as "this route is the resource's INDEX at this exposure", which is exactly the
     * fact the method name was being asked to stand in for.
     */
    public function test_a_second_collection_action_is_documented_when_the_exposure_declares_the_index(): void
    {
        $this->register();

        $parameters = $this->strategy()($this->routeFor(
            ListFixtureHostController::class,
            'indexAll',
            [BeamRouteProxy::FILTERS_PROMISE => 'catalogs'],
        ));

        $this->assertArrayHasKey($this->filterKey('name'), $parameters);
        $this->assertArrayHasKey(config('query-builder.parameters.sort', 'sort'), $parameters);
    }

    /** The second declared spelling: an explicit cardinality on the mount. */
    public function test_a_declared_many_return_documents_the_list_contract_under_any_method_name(): void
    {
        $this->register();

        $route = new Route(['GET'], 'catalogs', [
            'uses' => ListFixtureHostController::class.'@listAll',
            'controller' => ListFixtureHostController::class.'@listAll',
            BeamRouteProxy::ACTION => ['returns' => ListFixtureFilterData::class, 'returnsMany' => true],
        ]);
        $route->defaults(ParticleController::RESOURCE, 'catalogs');

        $parameters = $this->strategy()(ExtractedEndpointData::fromRoute($route));

        $this->assertArrayHasKey($this->filterKey('name'), $parameters);
    }

    /**
     * api-surface-coherence 103, the OVER-trigger half — 96 routes at the flagship, and the half nothing
     * could see. Each sub-surface mounted beside a resource carries the same `_particle` stamp and is
     * served by a controller whose method is ALSO called `index`, so the saved-filter listing was
     * documented as accepting the filter vocabulary of the resource it lists saved filters FOR.
     *
     * @return array<string, array{0: class-string, 1: array<string, mixed>}>
     */
    public static function subSurfaceProvider(): array
    {
        return [
            'saved filters' => [ResourceFiltersController::class, [ResourceFiltersController::CONFIG => ['resource' => 'catalogs']]],
            'discovery' => [ResourceDiscoveryController::class, [ResourceDiscoveryController::CONFIG => ['resource' => 'catalogs']]],
            // The one sub-surface with no config default of its own: recognised by its controller class.
            'hook events' => [HookEventCatalogController::class, []],
        ];
    }

    /**
     * @param  class-string  $controller
     * @param  array<string, mixed>  $defaults
     */
    #[DataProvider('subSurfaceProvider')]
    public function test_a_sub_surface_does_not_inherit_its_resources_list_contract(string $controller, array $defaults): void
    {
        $this->register();

        $this->assertSame([], $this->strategy()($this->routeFor($controller, 'index', $defaults)));
    }

    /**
     * The rung that is a CONVENTION rather than a declaration, kept deliberately and pinned so its
     * removal is a visible decision: three flagship routes (`api/v1/silos`, `api/v1/agents`,
     * `api/v1/circuits`) are hand-mounted indexes declaring neither cardinality nor a filter promise,
     * and dropping the rung would strip a contract they genuinely serve.
     */
    public function test_an_undeclared_crud_index_still_falls_back_to_the_method_name(): void
    {
        $this->register();

        $parameters = $this->strategy()($this->routeFor(ListFixtureHostController::class, 'index'));

        $this->assertArrayHasKey($this->filterKey('name'), $parameters);
    }

    /**
     * The honest limit of that fallback, stated as a test rather than as prose: a second collection
     * action that declares NOTHING is still invisible. That is the declaration gap 103 reports, not a
     * defect this strategy can close on its own.
     */
    public function test_an_undeclared_second_collection_action_is_still_not_documented(): void
    {
        $this->register();

        $this->assertSame([], $this->strategy()($this->routeFor(ListFixtureHostController::class, 'indexAll')));
    }
}
