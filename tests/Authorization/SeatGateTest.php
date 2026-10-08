<?php

namespace Splicewire\Beam\Tests\Authorization;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\CursorPaginator;
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

    public function test_a_subject_free_particle_operation_delegates_to_its_declared_ability(): void
    {
        $this->operation('publish', 'feeds.publish', false);

        $resolution = $this->gate()->resolve('feeds.publish');

        $this->assertSame(SeatGateKind::Operation, $resolution?->kind);
        $this->assertTrue($this->gate()->for('feeds.publish', $this->allowed()));
        $this->assertFalse($this->gate()->for('feeds.publish', $this->denied()));
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
}

class SeatGateFeedBacking implements StreamsRecords
{
    public function records(array $filters, ?string $cursor, int $perPage): CursorPaginator
    {
        return new Paginator([], $perPage);
    }
}

class SeatGateAdminProbe implements ReachabilityProbe
{
    public function allows(?Authenticatable $user, array $parameters, IlluminateRoute $route): bool
    {
        return $user?->getAuthIdentifier() === 1;
    }
}
