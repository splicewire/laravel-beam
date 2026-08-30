<?php

namespace Splicewire\Beam\Tests\Particle;

use Illuminate\Routing\Route as RouteInstance;
use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Routing\HttpMethod;
use Splicewire\Beam\Tests\TestCase;

/**
 * `alias: false` — an operation that was never AT `/op/` must not be given a legacy there
 * (particle-operation-surface 13).
 *
 * particle-operation-surface 12 dropped the `/op/` segment and kept the old spelling mounted as a
 * deprecated alias, because a URL that shipped is a published contract and 61 of them had. An
 * operation DECLARED after that landing never answered under `/op/`, so mounting the alias for it
 * manufactures a deprecated URL nobody has ever called, stamps it `Deprecated`, and hangs
 * `LegacyOperationAlias`'s `Deprecation` header and per-call log line off a route that is not a
 * migration from anything.
 *
 * 13 is the first caller: its three Exports — `compositions.export`, `disclosures.export`,
 * `circuits.export` — answered at `{resource}/{id}/export` under the rendering mount and have never
 * answered at `{resource}/{id}/op/export`. Without this option the dissolution would have ADDED three
 * routes to the flagship's table while claiming to preserve it, and one of them
 * (`splice.compositions.…`) would have carried a doubled route name, because the alias derives its
 * name from the resource key while the mount passes `name:` to keep the group's own prefix.
 *
 * Opt-OUT rather than opt-in, deliberately: the default has to keep serving the sixty-one, and a
 * caller who forgets the option gets a harmless extra route rather than a broken contract.
 */
class OperationAliasOptOutTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(ParticleOperationRegistry::class)->register(new ParticleOperation(
            resource: 'papers',
            name: 'export',
            kind: OperationKind::Read,
            model: 'App\\Models\\Paper',
            method: HttpMethod::Get,
            handle: fn () => null,
        ));
    }

    /** @return list<string> every mounted URI whose last segment is `export` */
    protected function mounted(): array
    {
        return array_values(array_map(
            fn (RouteInstance $route) => $route->uri(),
            array_filter(
                Route::getRoutes()->getRoutes(),
                fn (RouteInstance $route) => str_ends_with($route->uri(), 'export'),
            ),
        ));
    }

    public function test_the_deprecated_op_spelling_is_not_mounted_when_the_caller_opts_out(): void
    {
        Particle::ops('papers', 'papers', 'export', ['alias' => false]);

        $this->assertSame(['papers/{id}/export'], $this->mounted());
    }

    public function test_the_primary_is_untouched_by_the_opt_out(): void
    {
        Particle::ops('papers', 'papers', 'export', ['alias' => false]);

        // Name lookups are built when a route is ADDED and the mounter names each route after adding
        // it, so a test mounting post-boot refreshes them itself — a real app gets this from the
        // framework's `booted` hook.
        Route::getRoutes()->refreshNameLookups();

        $route = Route::getRoutes()->getByName('papers.export');

        $this->assertNotNull($route, 'The primary keeps its derived name; only the alias is skipped.');
        $this->assertContains('GET', $route->methods());
        $this->assertSame('papers/{id}/export', $route->uri());
    }

    public function test_the_alias_still_mounts_by_default_so_the_sixty_one_published_urls_survive(): void
    {
        Particle::ops('papers', 'papers', 'export');

        $this->assertSame(
            ['papers/{id}/export', 'papers/{id}/op/export'],
            $this->mounted(),
            'Omitting the option must reproduce ticket 12 exactly — this is opt-OUT.',
        );
    }
}
