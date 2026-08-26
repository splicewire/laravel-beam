<?php

namespace Splicewire\Beam\Tests\Particle;

use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Particle\Mount\ParticleMounter;
use Splicewire\Beam\Particle\Mount\ParticleMountManager;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Tests\TestCase;

/**
 * `Particle::mount()` — the sanctioned particle mount front door (api-surface-coherence ticket 49).
 *
 * The two things worth pinning, and they are different in kind:
 *
 *  1. **Equivalence.** The facade and the `Route::particle*()` macros drive the same
 *     {@see ParticleMounter}, so the route table they produce is identical route-for-route. This is the
 *     ticket's "route table byte-identical to HEAD" acceptance criterion expressed as a test rather than
 *     as a one-off manifest diff, so it keeps holding after the session that ran the diff has ended.
 *  2. **Opt-in defaults.** A declared operation or rendering mounts NOTHING unless the host asks. This
 *     is the one rule the ticket said must not be relaxed for convenience — a package that adds a
 *     `#[ParticleOp]` must never be able to add routes to a host that did not ask.
 */
class ParticleMountFacadeTest extends TestCase
{
    /** Route (method, uri, name) triples currently registered, ordered as the router holds them. */
    protected function table(): array
    {
        return array_values(array_map(
            fn ($route) => [implode('|', $route->methods()), $route->uri(), $route->getName()],
            Route::getRoutes()->getRoutes(),
        ));
    }

    /** Routes registered after boot are absent from the name lookup until it is refreshed. */
    protected function named(string $name)
    {
        Route::getRoutes()->refreshNameLookups();

        return Route::getRoutes()->getByName($name);
    }

    protected function freshRouter(): void
    {
        $this->refreshApplication();
    }

    public function test_the_facade_resolves_to_a_container_bound_manager(): void
    {
        $this->assertInstanceOf(ParticleMountManager::class, app(ParticleMountManager::class));
        $this->assertInstanceOf(ParticleMountManager::class, Particle::getFacadeRoot());
    }

    public function test_the_facade_and_the_macro_mount_an_identical_resource_table(): void
    {
        Particle::mount('widgets')->only(['index', 'show', 'store']);
        $viaFacade = $this->table();

        $this->freshRouter();

        Route::particleResource('widgets', 'widgets', ['only' => ['index', 'show', 'store']]);
        $viaMacro = $this->table();

        $this->assertSame($viaMacro, $viaFacade);
        $this->assertNotSame([], $viaFacade);
    }

    public function test_a_diverging_resource_key_is_carried_through_to_the_route_defaults(): void
    {
        Particle::mount('extensions', 'market-extensions')->only(['index']);

        $route = $this->named('market_extensions.index');

        $this->assertNotNull($route);
        $this->assertSame('extensions', $route->uri());
        $this->assertSame('market-extensions', $route->defaults[ParticleController::RESOURCE]);
    }

    public function test_every_resource_option_survives_the_builder(): void
    {
        Particle::mount('gadgets')
            ->names('gizmos')
            ->idConstraint('uuid')
            ->legacyPostUpdate()
            ->controller(ParticleController::class)
            ->filters(false);

        $update = $this->named('gizmos.update');

        $this->assertNotNull($update);
        $this->assertEqualsCanonicalizing(['PUT', 'PATCH', 'POST'], $update->methods());
        $this->assertArrayHasKey('id', $update->wheres);

        // `filters(false)` is a real opt-out, not a no-op: no filter sub-surface route exists.
        $this->assertNull($this->named('gizmos.filters.index'));
    }

    public function test_ops_are_off_unless_asked(): void
    {
        app(ParticleOperationRegistry::class)->register(
            new ParticleOperation(
                resource: 'sprockets',
                name: 'spin',
                kind: OperationKind::Task,
                model: 'App\\Models\\Sprocket',
                handle: fn () => null,
            ),
        );

        Particle::mount('sprockets')->only(['index']);

        $this->assertNull($this->named('sprockets.op.spin'));
    }

    public function test_ops_true_mounts_every_registered_operation_for_the_resource(): void
    {
        app(ParticleOperationRegistry::class)->register(
            new ParticleOperation(
                resource: 'sprockets',
                name: 'spin',
                kind: OperationKind::Task,
                model: 'App\\Models\\Sprocket',
                handle: fn () => null,
            ),
        );

        Particle::mount('sprockets')->only(['index'])->ops();

        $route = $this->named('sprockets.op.spin');

        $this->assertNotNull($route);
        $this->assertSame('sprockets/{id}/op/spin', $route->uri());
        $this->assertSame('sprockets', $route->defaults[ParticleOperationController::RESOURCE]);
        $this->assertSame('spin', $route->defaults[ParticleOperationController::NAME]);
    }

    public function test_an_explicit_op_list_matches_the_macro_spelling(): void
    {
        Particle::mount('sprockets')->only([])->ops(['spin'], ['method' => 'get']);
        $viaFacade = $this->table();

        $this->freshRouter();

        Route::particleOps('sprockets', 'sprockets', ['spin'], ['method' => 'get']);
        $viaMacro = $this->table();

        $this->assertSame($viaMacro, $viaFacade);
    }

    public function test_renderings_are_off_unless_asked(): void
    {
        Particle::mount('doodads')->only(['index']);

        // The ticket-33 catalog route mounts even for zero renderings — but only once the host asks for
        // the rendering surface at all. Silence must not publish it.
        $this->assertNull($this->named('doodads.renderings'));
    }

    public function test_renderings_mount_when_asked(): void
    {
        Particle::mount('doodads')->only(['index'])->renderings(subject: 'App\\Models\\Doodad', abilities: []);

        $this->assertNotNull($this->named('doodads.renderings'));
    }

    public function test_the_builder_mounts_at_its_own_statement_rather_than_at_request_time(): void
    {
        Particle::mount('trinkets')->only(['index']);

        // No terminal call, no `register()`, no return value held — the destructor fired at the
        // semicolon. This is what preserves mount ORDER against the macro spelling, and it is the whole
        // reason `only()` can be a fluent setter rather than a constructor argument.
        $this->assertNotNull($this->named('trinkets.index'));
    }

    public function test_register_is_idempotent_so_the_destructor_cannot_double_mount(): void
    {
        $mount = Particle::mount('baubles')->only(['index']);
        $mount->register();
        $mount->register();
        unset($mount);

        $matching = array_filter(
            Route::getRoutes()->getRoutes(),
            fn ($route) => $route->getName() === 'baubles.index',
        );

        $this->assertCount(1, $matching);
    }

    public function test_the_standalone_filter_door_matches_the_macro_spelling(): void
    {
        Particle::filters(resource: null, at: 'resources/{resource}', names: 'resources');
        $viaFacade = $this->table();

        $this->freshRouter();

        Route::resourceFilters(resource: null, at: 'resources/{resource}', names: 'resources');
        $viaMacro = $this->table();

        $this->assertSame($viaMacro, $viaFacade);
    }
}
