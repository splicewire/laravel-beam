<?php

namespace Splicewire\Beam\Tests\Authorization;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Pagination\CursorPaginator as Paginator;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Authorization\SeatGate;
use Splicewire\Beam\Authorization\SeatGateKind;
use Splicewire\Beam\Discovery\ReachabilityProbe;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Particle\Backing\StreamsRecords;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\Fixtures\WidgetGateData;
use Splicewire\Beam\Tests\TestCase;

/** APP-07: a nav seat asks the route's gate; it never authors a second permission. */
class SeatGateTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('beam.core.discovery.probes', [
            'require.admin' => SeatGateAdminProbe::class,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Gate::define('feed.read', fn (User $user): bool => $user->getAuthIdentifier() === 1);
        Gate::define('entitlement:feeds.publish', fn (User $user): bool => $user->getAuthIdentifier() === 1);
        Gate::define('reports.view', fn (User $user): bool => $user->getAuthIdentifier() === 1);
    }

    public function test_a_resource_list_seat_delegates_to_resource_visibility_and_route_middleware(): void
    {
        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'feeds',
            backing: SeatGateFeedBacking::class,
            data: WidgetGateData::class,
            policy: 'feed.read',
            frame: true,
            readOnly: true,
            showable: false,
        ), ['tenant']);

        Route::get('/feeds', fn () => [])->middleware('auth')->name('feeds.index')
            ->defaults(ParticleController::RESOURCE, 'feeds');

        $resolution = $this->gate()->resolve('feeds.index', 'tenant');

        $this->assertSame(SeatGateKind::Resource, $resolution?->kind);
        $this->assertTrue($this->gate()->for('feeds.index', $this->allowed(), 'tenant'));
        $this->assertFalse($this->gate()->for('feeds.index', $this->denied(), 'tenant'));
        $this->assertFalse($this->gate()->for('feeds.index', null, 'tenant'));
    }

    /** Integrator ruling 2026-10-10 02:43Z: list admission is viewAny AND scope; scope no longer wins. */
    public function test_a_scoped_list_seat_still_requires_its_nav_policy(): void
    {
        Gate::policy(SeatGateScopedFeed::class, SeatGateScopedFeedPolicy::class);
        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'scoped-feeds',
            backing: SeatGateScopedFeed::class,
            data: WidgetGateData::class,
            scope: fn ($query) => $query->where('owner_id', auth()->id()),
            frame: true,
            readOnly: true,
            showable: false,
        ), ['tenant']);

        Route::get('/scoped-feeds', fn () => [])->middleware('auth')->name('scoped-feeds.index')
            ->defaults(ParticleController::RESOURCE, 'scoped-feeds');

        $actor = $this->denied();
        $this->actingAs($actor);

        $this->assertFalse($this->gate()->for('scoped-feeds.index', $actor, 'tenant'));
    }

    public function test_a_subject_free_particle_operation_delegates_to_its_declared_ability(): void
    {
        $this->operation('publish', 'feeds.publish', false);

        $resolution = $this->gate()->resolve('feeds.publish');

        $this->assertSame(SeatGateKind::Operation, $resolution?->kind);
        $this->assertTrue($this->gate()->for('feeds.publish', $this->allowed()));
        $this->assertFalse($this->gate()->for('feeds.publish', $this->denied()));
    }

    public function test_a_frame_list_leaf_resolves_from_the_catalog_without_a_same_named_http_route(): void
    {
        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'catalog-feeds',
            backing: SeatGateFeedBacking::class,
            data: WidgetGateData::class,
            policy: 'feed.read',
            routeName: 'catalog.index',
            frame: true,
            readOnly: true,
            showable: false,
        ), ['tenant']);

        $resolution = $this->gate()->resolve('catalog.index', 'tenant');

        $this->assertSame(SeatGateKind::Resource, $resolution?->kind);
        $this->assertNull($resolution?->route);
        $this->assertTrue($this->gate()->for('catalog.index', $this->allowed(), 'tenant'));
        $this->assertFalse($this->gate()->for('catalog.index', $this->denied(), 'tenant'));
    }

    public function test_a_frame_list_leaf_outranks_a_same_named_ungated_spa_shell_route(): void
    {
        Gate::policy(SeatGateScopedFeed::class, SeatGateScopedFeedPolicy::class);
        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'studio',
            backing: SeatGateScopedFeed::class,
            data: WidgetGateData::class,
            routeName: 'studio.index',
            scope: fn ($query) => $query->where('owner_id', auth()->id()),
            frame: true,
            readOnly: true,
            showable: false,
        ), ['tenant']);
        Route::get('/studio', fn () => [])->middleware('auth')->name('studio.index');

        $resolution = $this->gate()->resolve('studio.index', 'tenant');

        $this->assertSame(SeatGateKind::Resource, $resolution?->kind);
        $this->assertNull($resolution?->route, 'The auth-only SPA route must not replace the backing list gate.');
        $this->assertFalse($this->gate()->allows($resolution, $this->denied(), 'tenant'));
    }

    public function test_an_explicitly_open_operation_and_bespoke_route_still_honour_auth_middleware(): void
    {
        $this->operation('preview', false, false);
        Route::get('/help', fn () => [])->middleware('auth')->name('help')
            ->defaults(SeatGate::OPEN_TO_MEMBERS, true);

        $this->assertSame(SeatGateKind::Open, $this->gate()->resolve('feeds.preview')?->kind);
        $this->assertTrue($this->gate()->for('feeds.preview', $this->denied()));
        $this->assertFalse($this->gate()->for('feeds.preview', null));

        $this->assertSame(SeatGateKind::Open, $this->gate()->resolve('help')?->kind);
        $this->assertTrue($this->gate()->for('help', $this->denied()));
        $this->assertFalse($this->gate()->for('help', null));
    }

    public function test_a_named_route_delegates_to_can_or_a_configured_middleware_probe(): void
    {
        Route::get('/reports', fn () => [])->middleware(['auth', 'can:reports.view'])->name('reports');
        Route::get('/admin', fn () => [])->middleware(['auth', 'require.admin'])->name('admin');

        $this->assertSame(SeatGateKind::Route, $this->gate()->resolve('reports')?->kind);
        $this->assertTrue($this->gate()->for('reports', $this->allowed()));
        $this->assertFalse($this->gate()->for('reports', $this->denied()));

        $this->assertSame(SeatGateKind::Route, $this->gate()->resolve('admin')?->kind);
        $this->assertTrue($this->gate()->for('admin', $this->allowed()));
        $this->assertFalse($this->gate()->for('admin', $this->denied()));
    }

    public function test_a_spa_seat_can_resolve_from_one_backing_route_or_an_explicit_open_decision(): void
    {
        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'listings',
            backing: SeatGateFeedBacking::class,
            data: WidgetGateData::class,
            policy: 'feed.read',
            frame: false,
            readOnly: true,
            showable: false,
        ));
        Route::get('/listings', fn () => [])->middleware('auth')->name('listings.index')
            ->defaults(ParticleController::RESOURCE, 'listings');

        $gate = $this->gate();
        $gate->backedBy('creator.page', 'listings.index');
        $gate->openToMembers('system.page');

        $this->assertSame(SeatGateKind::Resource, $gate->resolve('creator.page')?->kind);
        $this->assertSame('listings.index', $gate->resolve('creator.page')?->route?->getName());
        $this->assertTrue($gate->for('creator.page', $this->allowed()));
        $this->assertFalse($gate->for('creator.page', $this->denied()));
        $this->assertSame(SeatGateKind::Open, $gate->resolve('system.page')?->kind);
        $this->assertNull($gate->resolve('system.page')?->route);
        $this->assertTrue($gate->for('system.page', $this->allowed()));
        $this->assertFalse($gate->for('system.page', null));
    }

    /**
     * Combined v3 attempt 6 (orch-peer ruling 07:35Z): a client seat may need two gates at once, e.g. Studio's medium route
     * gate AND the compositions read boundary of the list it lands on. One conjunctive declaration resolves both into a
     * single Resource resolution that carries the route, so allows() checks the route's reachability, then the read.
     */
    public function test_a_conjunctive_seat_admits_only_when_its_route_gate_and_resource_read_both_pass(): void
    {
        Gate::define('conj.route', fn (User $user): bool => in_array($user->getAuthIdentifier(), [1, 3], true));
        Gate::define('conj.read', fn (User $user): bool => in_array($user->getAuthIdentifier(), [1, 2], true));
        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'conj-feeds',
            backing: SeatGateFeedBacking::class,
            data: WidgetGateData::class,
            policy: 'conj.read',
            routeName: 'conj.index',
            frame: true,
            readOnly: true,
            showable: false,
        ), ['tenant']);
        Route::get('/conj-ideas', fn () => [])->middleware(['auth', 'can:conj.route'])->name('conj.ideas');

        $gate = $this->gate();
        $gate->backedByRouteAndResource('conj.index', 'conj.ideas', 'conj.index');
        $gate->backedBy('conj.section', 'conj.index');

        foreach (['conj.index', 'conj.section'] as $seat) {
            $resolution = $gate->resolve($seat, 'tenant');
            $this->assertSame(SeatGateKind::Resource, $resolution?->kind, $seat);
            $this->assertSame('conj.ideas', $resolution?->route?->getName(), $seat);
            $this->assertSame('conj-feeds', $resolution?->resource?->key, $seat);

            $this->assertTrue($gate->for($seat, $this->user(1), 'tenant'), "{$seat}: both arms pass");
            $this->assertFalse($gate->for($seat, $this->user(2), 'tenant'), "{$seat}: the route gate denies");
            $this->assertFalse($gate->for($seat, $this->user(3), 'tenant'), "{$seat}: the resource read denies");
            $this->assertFalse($gate->for($seat, $this->user(4), 'tenant'), "{$seat}: both arms deny");
            $this->assertFalse($gate->for($seat, null, 'tenant'), "{$seat}: a guest");
        }
    }

    public function test_a_conjunctive_seat_does_not_guess_when_an_arm_is_not_what_it_declares(): void
    {
        Route::get('/conj-plain', fn () => [])->middleware('auth')->name('conj.plain');
        Route::get('/conj-gated', fn () => [])->middleware(['auth', 'can:reports.view'])->name('conj.gated');
        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'conj-only',
            backing: SeatGateFeedBacking::class,
            data: WidgetGateData::class,
            policy: 'feed.read',
            routeName: 'conj-only.index',
            frame: true,
            readOnly: true,
            showable: false,
        ), ['tenant']);

        $gate = $this->gate();
        // The route arm must be a named route gate; an auth-only route has no gate of its own to conjoin.
        $gate->backedByRouteAndResource('conj.no-route-gate', 'conj.plain', 'conj-only.index');
        // The resource arm must be a Frame list leaf in the catalog.
        $gate->backedByRouteAndResource('conj.no-resource', 'conj.gated', 'nothing.index');

        // A gate arm that leads back to its own seat is unresolved, not an endless resolution.
        $gate->backedByRouteAndResource('conj.loop', 'conj.loop-gate', 'conj-only.index');
        $gate->backedBy('conj.loop-gate', 'conj.loop');
        $this->assertNull($gate->resolve('conj.loop', 'tenant'));

        $this->assertNull($gate->resolve('conj.no-route-gate', 'tenant'));
        $this->assertNull($gate->resolve('conj.no-resource', 'tenant'));
        $this->assertFalse($gate->for('conj.no-route-gate', $this->allowed(), 'tenant'));
    }

    public function test_a_conjunctive_declaration_refuses_a_conflicting_or_degenerate_seat(): void
    {
        $gate = $this->gate();
        $gate->backedBy('taken.page', 'listings.index');
        $gate->openToMembers('open.page');

        foreach ([
            fn () => $gate->backedByRouteAndResource('', 'a.route', 'a.index'),
            fn () => $gate->backedByRouteAndResource('seat.page', '', 'a.index'),
            fn () => $gate->backedByRouteAndResource('seat.page', 'a.route', ''),
            fn () => $gate->backedByRouteAndResource('seat.page', 'seat.page', 'a.index'),
            fn () => $gate->backedByRouteAndResource('taken.page', 'a.route', 'a.index'),
            fn () => $gate->backedByRouteAndResource('open.page', 'a.route', 'a.index'),
        ] as $i => $declare) {
            try {
                $declare();
                $this->fail("declaration {$i} should have been refused");
            } catch (\LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        $gate->backedByRouteAndResource('both.page', 'a.route', 'a.index');
        $this->expectException(\LogicException::class);
        $gate->backedBy('both.page', 'other.route');
    }

    public function test_undeclared_and_record_scoped_gates_do_not_guess(): void
    {
        Route::get('/ambient', fn () => [])->middleware('auth')->name('ambient');
        $this->operation('undeclared', null, false);
        $this->operation('record-scoped', 'update', null);

        $this->assertNull($this->gate()->resolve('ambient'));
        $this->assertNull($this->gate()->resolve('feeds.undeclared'));
        $this->assertNull($this->gate()->resolve('feeds.record-scoped'));
        $this->assertFalse($this->gate()->for('ambient', $this->allowed()));
    }

    public function test_a_route_with_multiple_gate_declarations_is_unresolved(): void
    {
        Route::get('/ambiguous', fn () => [])->middleware(['auth', 'can:reports.view'])->name('ambiguous')
            ->defaults(SeatGate::OPEN_TO_MEMBERS, true);

        $this->assertNull($this->gate()->resolve('ambiguous'));
        $this->assertFalse($this->gate()->for('ambiguous', $this->allowed()));
    }

    private function operation(string $name, string|false|null $ability, string|false|null $abilityModel): void
    {
        $this->app->make(ParticleOperationRegistry::class)->register(new ParticleOperation(
            resource: 'feeds',
            name: $name,
            kind: OperationKind::Write,
            handle: fn () => [],
            ability: $ability,
            abilityModel: $abilityModel,
        ));

        Route::get('/feeds/'.$name, fn () => [])->middleware('auth')->name('feeds.'.$name)
            ->defaults(ParticleOperationController::RESOURCE, 'feeds')
            ->defaults(ParticleOperationController::NAME, $name);
    }

    private function gate(): SeatGate
    {
        return $this->app->make(SeatGate::class);
    }

    private function allowed(): User
    {
        return (new User)->forceFill(['id' => 1]);
    }

    private function denied(): User
    {
        return (new User)->forceFill(['id' => 2]);
    }

    private function user(int $id): User
    {
        return (new User)->forceFill(['id' => $id]);
    }
}

class SeatGateFeedBacking implements StreamsRecords
{
    public function records(array $filters, ?string $cursor, int $perPage): CursorPaginator
    {
        return new Paginator([], $perPage);
    }
}

class SeatGateScopedFeed extends Model
{
    protected $table = 'scoped_feeds';
}

class SeatGateScopedFeedPolicy
{
    public function viewAny(): bool
    {
        return false;
    }
}

class SeatGateAdminProbe implements ReachabilityProbe
{
    public function allows(?Authenticatable $user, array $parameters, IlluminateRoute $route): bool
    {
        return $user?->getAuthIdentifier() === 1;
    }
}
