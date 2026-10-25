<?php

namespace Splicewire\Beam\Tests\Frame;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Schemastud\Frame\FrameServiceProvider;
use Splicewire\Beam\Authorization\ResourceReadGuard;
use Splicewire\Beam\Authorization\ResourceVisibility;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Frame\ParticleResourceActions;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Particle\ActionAffordance;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Particle\Mount\ParticleMounter;
use Splicewire\Beam\Particle\Subject\NoSubject;
use Splicewire\Beam\Tests\TestCase;

class RealmScopedResourceReadAbilityTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), FrameServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('frame.middleware', []);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('vaults', function (Blueprint $table): void {
            $table->id();
        });
        RealmVault::query()->create();

        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'realm-vaults',
            backing: RealmVault::class,
            data: RealmVaultData::class,
            scope: fn (Builder $query): Builder => $query->whereNotNull($query->getModel()->getQualifiedKeyName()),
            policy: 'ux.author',
            frame: true,
            readOnly: true,
        ));
        $operation = new ParticleOperation(
            resource: 'realm-vaults',
            name: 'refresh',
            kind: OperationKind::Write,
            handle: fn () => ['data' => ['ok' => true]],
            ability: 'ux.author',
            abilityModel: false,
            input: false,
            subject: NoSubject::class,
            affordance: new ActionAffordance,
        );
        $this->app->make(ParticleOperationRegistry::class)->register($operation);
        Particle::ops('operator/realm-vaults', 'realm-vaults', 'refresh', ['name' => 'realm-vaults.refresh']);
        Particle::ops('tenant/realm-vaults', 'realm-vaults', 'refresh', [
            'realm' => 'tenant',
            'name' => 'tenant.realm-vaults.refresh',
            'abilityOverrides' => ['ux.author' => 'site.edit'],
        ]);

        Gate::define('ux.author', fn ($user): bool => $user instanceof RealmCentralAuthor);
        Gate::define('site.edit', fn ($user): bool => $user instanceof RealmSiteEditor);
    }

    public function test_the_tenant_read_uses_the_tenant_route_map_and_the_projected_manifest_realm(): void
    {
        $resource = $this->app->make(ParticleResourceRegistry::class)->get('realm-vaults');
        $request = $this->requestForRealm('tenant');
        $guard = ResourceReadGuard::forApp();
        $visibility = $this->app->make(ResourceVisibility::class);
        $definition = $resource->toResourceDefinition('tenant');

        $this->assertSame('site.edit', app(ParticleResourceActions::class)
            ->effectiveResourceReadAbility('realm-vaults', 'ux.author', 'tenant'));

        $this->assertTrue($guard->inspectReadFor($resource, $request, new RealmCentralAuthor)->denied());
        $this->assertFalse($visibility->listable($definition, new RealmCentralAuthor, 'tenant'));

        $siteEditorRead = $guard->inspectReadFor($resource, $request, new RealmSiteEditor);
        $this->assertTrue($siteEditorRead->allowed(), $siteEditorRead->message() ?? '');
        $this->assertTrue($visibility->listable($definition, new RealmSiteEditor, 'tenant'));
    }

    public function test_the_central_read_does_not_inherit_the_tenant_ability_map(): void
    {
        $resource = $this->app->make(ParticleResourceRegistry::class)->get('realm-vaults');
        $request = $this->requestForRealm('operator', ['realm' => 'tenant']);
        $guard = ResourceReadGuard::forApp();

        $this->assertTrue($guard->inspectReadFor($resource, $request, new RealmSiteEditor)->denied());
        $centralRead = $guard->inspectReadFor($resource, $request, new RealmCentralAuthor);
        $this->assertTrue($centralRead->allowed(), $centralRead->message() ?? '');
    }

    public function test_rest_reads_use_only_the_matched_tenant_mounts_author_override(): void
    {
        $router = app('router');
        $mounter = app(ParticleMounter::class);
        $mounter->resource($router, 'tenant-mapped/realm-vaults', 'realm-vaults', [
            'only' => ['index'],
            'realm' => 'tenant',
            'abilityOverrides' => ['ux.author' => 'site.edit'],
        ]);
        $mounter->resource($router, 'tenant-strict/realm-vaults', 'realm-vaults', [
            'only' => ['index'],
            'realm' => 'tenant',
        ]);

        $strictRoute = collect($router->getRoutes()->getRoutes())->first(
            fn (Route $route): bool => $route->uri() === 'tenant-strict/realm-vaults',
        );
        $strictRequest = Request::create('/tenant-strict/realm-vaults', 'GET');
        $strictRequest->setRouteResolver(fn () => $strictRoute);
        $this->assertSame('ux.author', app(ResourceReadGuard::class)->declaredReadAbility(
            app(ParticleResourceRegistry::class)->get('realm-vaults'), request: $strictRequest,
        ));

        $this->actingAs(new RealmSiteEditor)->getJson('/tenant-mapped/realm-vaults')->assertOk();
        $this->actingAs(new RealmSiteEditor)->getJson('/tenant-strict/realm-vaults')->assertForbidden();
        $this->actingAs(new RealmCentralAuthor)->getJson('/tenant-strict/realm-vaults')->assertOk();
    }

    private function requestForRealm(string $realm, array $query = []): Request
    {
        $request = Request::create('/frame/resources/realm-vaults', 'GET', $query);
        $route = new Route(['GET'], '/frame/resources/realm-vaults', fn () => null);
        $route->defaults(ParticleOperationController::REALM, $realm);
        $request->setRouteResolver(fn () => $route);

        return $request;
    }
}

class RealmVault extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'vaults';

    public $timestamps = false;
}

class RealmVaultData extends \Spatie\LaravelData\Data
{
    public function __construct(public int $id) {}
}

class RealmCentralAuthor extends User {}

class RealmSiteEditor extends User {}
