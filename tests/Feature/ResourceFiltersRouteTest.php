<?php

namespace Splicewire\Beam\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Filters\Http\ResourceFiltersController;
use Splicewire\Beam\Tests\TestCase;

/**
 * `Route::resourceFilters()` — the per-resource filter sub-surface's MOUNT
 * (api-surface-coherence ticket 10, build 35).
 *
 * Scoped to what the macro is responsible for: the shape it mounts, the config it freezes, and the
 * registration ORDER that decides which route actually answers. The controller's behaviour — the
 * resource gate, saved-filter CRUD, variant enumeration — is exercised against real registered
 * resources in the host, because it needs a filter registry and an authenticated owner and both are
 * host facts.
 *
 * Every assertion here is on `RouteCollection::match()` rather than on the mounted list. That is
 * deliberate: `route:list` (and `getRoutes()`) SORT, so they will happily show you a route that is
 * shadowed by an earlier registration and never answers. Three particle resources shipped exactly
 * that way during the build.
 */
class ResourceFiltersRouteTest extends TestCase
{
    private function answering(string $uri, string $method = 'GET'): ?string
    {
        Route::getRoutes()->refreshNameLookups();

        return Route::getRoutes()->match(Request::create('/'.ltrim($uri, '/'), $method))->getName();
    }

    public function test_mounts_the_whole_sub_surface_under_the_exposure(): void
    {
        Route::resourceFilters('papers', at: 'papers');

        $this->assertSame('papers.filters.index', $this->answering('papers/filters'));
        $this->assertSame('papers.filters.store', $this->answering('papers/filters', 'POST'));
        $this->assertSame('papers.filters.schema', $this->answering('papers/filters/schema'));
        $this->assertSame('papers.filters.variants', $this->answering('papers/filters/variants'));
        $this->assertSame('papers.filters.options', $this->answering('papers/filters/options/silos'));
        $this->assertSame('papers.filters.variant-schema', $this->answering('papers/filters/journals/schema'));

        $id = '00000000-0000-4000-8000-000000000000';
        $this->assertSame('papers.filters.show', $this->answering("papers/filters/{$id}"));
        $this->assertSame('papers.filters.update', $this->answering("papers/filters/{$id}", 'PUT'));
        $this->assertSame('papers.filters.destroy', $this->answering("papers/filters/{$id}", 'DELETE'));
    }

    public function test_the_literal_segments_win_against_an_unconstrained_id(): void
    {
        // The failure this guards is not hypothetical: mounted after the CRUD block, three of the
        // estate's particle resources answered their filter index with `<resource>.show`, because
        // Laravel matches in REGISTRATION order and an unconstrained `{id}` swallows `filters`.
        Route::resourceFilters('papers', at: 'papers', idConstraint: 'none');
        Route::get('papers/{id}', fn () => null)->name('papers.show');

        $this->assertSame('papers.filters.schema', $this->answering('papers/filters/schema'));
        $this->assertSame('papers.filters.variants', $this->answering('papers/filters/variants'));
        $this->assertSame('papers.filters.index', $this->answering('papers/filters'));
        $this->assertSame('papers.show', $this->answering('papers/anything-else'));
    }

    public function test_freezes_the_resource_key_and_only_the_resource_key(): void
    {
        Route::resourceFilters('papers', at: 'papers');

        Route::getRoutes()->refreshNameLookups();
        $route = Route::getRoutes()->getByName('papers.filters.schema');

        // A plain, serializable array — `route:cache` has to survive it, which is why the macro
        // freezes a key rather than a closure or a resolved registry entry. Everything else (the
        // schema, the variant set, the option vocabulary) is re-read per request.
        $this->assertSame(['resource' => 'papers'], $route->defaults[ResourceFiltersController::CONFIG]);
    }

    public function test_a_null_resource_defers_to_the_route_parameter(): void
    {
        // The Frame-resource-root case, and the only one: that root is itself parameterised BY the
        // registration key, so `{resource}` there IS the key rather than a URL word that might
        // diverge from it.
        Route::resourceFilters(null, at: 'resources/{resource}', names: 'resources');

        Route::getRoutes()->refreshNameLookups();
        $route = Route::getRoutes()->getByName('resources.filters.schema');

        $this->assertSame('resources/{resource}/filters/schema', $route->uri());
        $this->assertSame(['resource' => null], $route->defaults[ResourceFiltersController::CONFIG]);
    }

    public function test_an_empty_names_stem_leaves_the_group_to_name_the_surface(): void
    {
        // `Route::get('/')` inside a NAMED group reports a route name that is exactly the group's
        // prefix, so the stem is empty and the sub-surface must emit a bare `filters.<verb>` for the
        // group to prefix. Passing the stem through instead produced
        // `splice.compositions.splice.compositions.filters.index`.
        Route::name('splice.compositions.')->prefix('splice/compositions')->group(function () {
            Route::resourceFilters('compositions', at: '', names: '');
        });

        $this->assertSame(
            'splice.compositions.filters.index',
            $this->answering('splice/compositions/filters')
        );
    }

    public function test_the_config_is_required_and_says_so(): void
    {
        // Mounted by hand, without the macro: the controller must refuse rather than guess a resource
        // out of the URI, which is the divergence ticket 10 §1 ruled legitimate.
        Route::get('papers/filters/schema', [ResourceFiltersController::class, 'schema']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Register it via Route::resourceFilters()');

        $this->withoutExceptionHandling()->get('papers/filters/schema');
    }
}
