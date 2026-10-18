<?php

namespace Splicewire\Beam\Tests\Authorization;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Authorization\AuthenticatedReadPolicy;
use Splicewire\Beam\Authorization\ResourceReadGuard;
use Splicewire\Beam\Authorization\ResourceVisibility;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Particle\ParticleFrameResourceHandler;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\Fixtures\ReadGuard\Gadget;
use Splicewire\Beam\Tests\Fixtures\ReadGuard\GadgetData;
use Splicewire\Beam\Tests\TestCase;

/**
 * ADR-0230 — a guest reads a resource only through its declared Public Read. A null actor is refused a
 * resource that declares none, on every read path; a declared Public Read admits the guest and the read is
 * narrowed to it. A signed-in reader is untouched.
 */
class PublicReadGuestReadTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.key', str_repeat('g', 32));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('auth.providers.users.model', PublicReadActor::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('public_read_actors', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('gadgets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
        });

        Gadget::create(['user_id' => 1]);
        Gadget::create(['user_id' => 2]);
    }

    private function declare(?string $publicRead = null): ParticleResource
    {
        $resource = new ParticleResource(
            key: 'gadgets',
            backing: Gadget::class,
            data: GadgetData::class,
            publicRead: $publicRead,
            project: fn (Gadget $gadget): GadgetData => new GadgetData((string) $gadget->getKey()),
            readOnly: true,
            label: 'Gadgets',
        );

        app(ParticleResourceRegistry::class)->register($resource);

        return $resource;
    }

    private function mountIndex(): void
    {
        // Unauthenticated on purpose: a guest reaches the controller, where the Public Read rule decides.
        Route::get('gadgets', [ParticleController::class, 'index'])
            ->defaults(ParticleController::RESOURCE, 'gadgets')
            ->name('gadgets.index');
    }

    public function test_a_guest_cannot_bypass_the_public_scope_by_addressing_a_record_id(): void
    {
        $resource = $this->declare(publicRead: GadgetData::class);
        $resource->publicScope = fn ($query) => $query->where('user_id', 1);
        Gate::define('view', fn (?User $actor, Gadget $record): bool => true);
        Route::get('gadgets/{id}', [ParticleController::class, 'show'])
            ->defaults(ParticleController::RESOURCE, 'gadgets');

        $this->getJson('/gadgets/1')->assertOk();
        $this->getJson('/gadgets/2')->assertNotFound();
    }

    public function test_public_guest_admission_does_not_bypass_the_signed_in_model_policy(): void
    {
        $resource = $this->declare(publicRead: GadgetData::class);
        $resource->publicScope = fn ($query) => $query->where('user_id', 1);
        Gate::define('view', fn (?User $actor, Gadget $record): bool => false);
        Route::get('gadgets/{id}', [ParticleController::class, 'show'])
            ->defaults(ParticleController::RESOURCE, 'gadgets');

        $this->getJson('/gadgets/1')->assertOk();
        $this->getJson('/gadgets/2')->assertNotFound();
        $this->actingAs(PublicReadActor::create(['name' => 'Ada']));
        $this->getJson('/gadgets/1')->assertForbidden();
        Gate::define('view', fn (?User $actor, Gadget $record): bool => true);
        $this->getJson('/gadgets/2')->assertOk();
    }

    public function test_guest_show_without_public_read_stays_refused_even_with_an_allowing_policy(): void
    {
        $this->declare();
        Gate::define('view', fn (?User $actor, Gadget $record): bool => true);
        Route::get('gadgets/{id}', [ParticleController::class, 'show'])
            ->defaults(ParticleController::RESOURCE, 'gadgets');

        $this->getJson('/gadgets/1')->assertForbidden();
    }

    public function test_a_guest_cannot_reach_a_private_record_through_a_relative_mount(): void
    {
        $parent = PublicReadActor::create(['name' => 'Collection owner']);
        Gadget::whereKey(2)->update(['user_id' => $parent->id]);
        $resource = $this->declare(publicRead: GadgetData::class);
        $resource->publicScope = fn ($query) => $query->where('gadgets.id', 1);
        Gate::define('view', fn (?User $actor, Gadget $record): bool => true);
        Particle::relative('owners', PublicReadActor::class, via: 'gadgets', routes: function (): void {
            Particle::mount('gadgets', 'gadgets')->only(['show']);
        }, options: ['binding' => 'owner']);

        $this->getJson('/owners/1/gadgets/1')->assertOk();
        $this->getJson('/owners/1/gadgets/2')->assertNotFound();
    }

    public function test_frame_detail_cannot_bypass_the_guest_public_scope(): void
    {
        $resource = $this->declare(publicRead: GadgetData::class);
        $resource->publicScope = fn ($query) => $query->where('user_id', 1);
        $handler = app(ParticleFrameResourceHandler::class)->forActor(null);
        $definition = $resource->toResourceDefinition();
        $this->assertSame('1', $handler->show($definition, '1')['id']);
        $this->expectException(ModelNotFoundException::class);
        $handler->show($definition, '2');
    }

    public function test_unbound_actor_dependent_frame_read_refuses_instead_of_guessing_guest(): void
    {
        $resource = $this->declare(publicRead: GadgetData::class);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('explicit actor');
        app(ParticleFrameResourceHandler::class)->show($resource->toResourceDefinition(), '1');
    }

    public function test_unbound_public_scope_also_requires_an_explicit_actor(): void
    {
        $resource = $this->declare();
        $resource->publicScope = fn ($query) => $query->where('user_id', 1);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('explicit actor');
        app(ParticleFrameResourceHandler::class)->show($resource->toResourceDefinition(), '1');
    }

    public function test_frame_guest_detail_projects_only_the_declared_public_fields(): void
    {
        $resource = $this->declare(publicRead: GadgetData::class);
        $resource->data = PrivateGadgetProjection::class;
        $resource->project = fn (Gadget $gadget): PrivateGadgetProjection => new PrivateGadgetProjection(
            (string) $gadget->id, (int) $gadget->user_id,
        );
        $handler = app(ParticleFrameResourceHandler::class)->forActor(null);

        $this->assertSame(['id' => '1'], $handler->show($resource->toResourceDefinition(), '1'));
    }

    public function test_frame_guest_cannot_receive_edit_fields_from_an_editable_resource(): void
    {
        $resource = $this->declare(publicRead: GadgetData::class);
        $resource->editable = true;
        $resource->editData = PrivateGadgetProjection::class;
        $handler = app(ParticleFrameResourceHandler::class)->forActor(null);

        $this->assertSame(['id' => '1'], $handler->show($resource->toResourceDefinition(), '1'));
    }

    public function test_frame_signed_in_detail_keeps_the_normal_projection(): void
    {
        $resource = $this->declare(publicRead: GadgetData::class);
        $resource->data = PrivateGadgetProjection::class;
        $resource->project = fn (Gadget $gadget): PrivateGadgetProjection => new PrivateGadgetProjection(
            (string) $gadget->id, (int) $gadget->user_id,
        );
        Gate::define('view', fn (?User $actor, Gadget $record): bool => true);
        $actor = PublicReadActor::create(['name' => 'Ada']);
        $this->actingAs($actor);
        $resource->readPolicy = AuthenticatedReadPolicy::class;
        $resource->readBoundary = ParticleResource::READ_BOUNDARY_GLOBAL;
        app(Request::class)->setUserResolver(fn () => null);
        $handler = app(ParticleFrameResourceHandler::class)->forActor($actor);

        $this->assertSame(['id' => '1', 'userId' => 1], $handler->show($resource->toResourceDefinition(), '1'));
    }

    public function test_unbound_resource_without_public_rules_keeps_legacy_read_behavior(): void
    {
        $resource = $this->declare();
        $resource->readPolicy = AuthenticatedReadPolicy::class;
        $resource->readBoundary = ParticleResource::READ_BOUNDARY_GLOBAL;
        $actor = PublicReadActor::create(['name' => 'Ada']);
        $this->actingAs($actor);
        app(Request::class)->setUserResolver(fn () => $actor);
        $this->assertSame(['id' => '1'], app(ParticleFrameResourceHandler::class)->show($resource->toResourceDefinition(), '1'));
    }

    public function test_actor_binding_clones_and_does_not_follow_an_ambient_user(): void
    {
        $resource = $this->declare(publicRead: GadgetData::class);
        $resource->publicScope = fn ($query, $realm) => $query->where('user_id', $realm === 'account' ? 1 : 2);
        $actor = PublicReadActor::create(['name' => 'Ada']);
        $this->actingAs($actor);
        app(Request::class)->setUserResolver(fn () => $actor);
        $original = app(ParticleFrameResourceHandler::class);
        $guest = $original->forActor(null, 'account');
        $this->assertNotSame($original, $guest);
        $this->assertSame(['id' => '1'], $guest->show($resource->toResourceDefinition(), '1'));
        $this->expectException(\LogicException::class);
        $original->show($resource->toResourceDefinition(), '1');
    }

    public function test_request_bound_read_uses_the_visitor_and_realm_without_mutating_the_ambient_request(): void
    {
        $resource = $this->declare(publicRead: GadgetData::class);
        $resource->publicScope = fn ($query, $realm) => $query->where('user_id', $realm === 'tenant-a' ? 1 : 2);
        $resource->data = PrivateGadgetProjection::class;
        $resource->project = fn (Gadget $gadget) => new PrivateGadgetProjection((string) $gadget->id, $gadget->user_id);
        $author = PublicReadActor::create(['name' => 'Author']);
        $ambient = app(Request::class);
        $ambient->setUserResolver(fn () => $author);
        $visitor = Request::create('/page');
        $visitor->setUserResolver(fn () => null);
        $handler = app(ParticleFrameResourceHandler::class);
        $a = $handler->forRequest($visitor, 'tenant-a');
        $b = $handler->forRequest($visitor, 'tenant-b');

        $this->assertSame([['id' => '1']], $a->index($resource->toResourceDefinition(), []));
        $this->assertSame([['id' => '2']], $b->index($resource->toResourceDefinition(), []));
        $this->assertSame($author, $ambient->user());
        $this->assertNull($visitor->route());
        $this->assertNotSame($handler, $a);
    }

    public function test_a_signed_in_detail_reader_keeps_the_resource_scope_not_the_guest_scope(): void
    {
        $resource = $this->declare(publicRead: GadgetData::class);
        $resource->publicScope = fn ($query) => $query->where('user_id', 1);
        Gate::define('view', fn (?User $actor, Gadget $record): bool => true);
        Route::get('gadgets/{id}', [ParticleController::class, 'show'])
            ->defaults(ParticleController::RESOURCE, 'gadgets');

        $this->actingAs(PublicReadActor::create(['name' => 'Ada']));
        $this->getJson('/gadgets/2')->assertOk();
    }

    public function test_a_guest_index_is_refused_without_a_public_read(): void
    {
        $this->declare(publicRead: null);
        $this->mountIndex();

        $this->getJson('/gadgets')->assertForbidden();
    }

    public function test_a_guest_index_is_admitted_through_a_public_read(): void
    {
        $this->declare(publicRead: GadgetData::class);
        $this->mountIndex();

        $this->getJson('/gadgets')->assertOk();
    }

    public function test_the_guard_refuses_then_admits_a_guest_by_public_read(): void
    {
        $guard = ResourceReadGuard::forApp();
        $request = Request::create('/gadgets');

        $noPublicRead = new ParticleResource(key: 'gadgets', backing: Gadget::class, data: GadgetData::class);
        $withPublicRead = new ParticleResource(key: 'gadgets', backing: Gadget::class, data: GadgetData::class, publicRead: GadgetData::class);

        $this->assertTrue($guard->inspectReadFor($noPublicRead, $request, null)->denied());
        $this->assertTrue($guard->inspectReadFor($withPublicRead, $request, null)->allowed());

        $this->assertTrue($guard->inspectGuestReadFor($noPublicRead, null)->denied());
        $this->assertTrue($guard->inspectGuestReadFor($withPublicRead, null)->allowed());
    }

    public function test_visibility_refuses_then_admits_a_guest_by_public_read(): void
    {
        $visibility = app(ResourceVisibility::class);

        $noPublicRead = new ParticleResource(key: 'gadgets', backing: Gadget::class, data: GadgetData::class);
        $withPublicRead = new ParticleResource(key: 'gadgets', backing: Gadget::class, data: GadgetData::class, publicRead: GadgetData::class);

        $this->assertFalse($visibility->readable($noPublicRead, null));
        $this->assertTrue($visibility->readable($withPublicRead, null));
    }

    public function test_a_signed_in_reader_is_untouched_by_the_rule(): void
    {
        $guard = ResourceReadGuard::forApp();
        $actor = PublicReadActor::create(['name' => 'Ada']);

        $noPublicRead = new ParticleResource(key: 'gadgets', backing: Gadget::class, data: GadgetData::class);

        // A signed-in reader is allowed through the guest gate regardless of a Public Read — it decides only
        // the guest; the signed-in reader keeps its own guard.
        $this->assertTrue($guard->inspectGuestReadFor($noPublicRead, $actor)->allowed());

        // readable() for a signed-in reader of a model-backed resource is unchanged (the model-less
        // declared-ability arm does not apply here).
        $this->assertTrue(app(ResourceVisibility::class)->readable($noPublicRead, $actor));
    }
}

class PublicReadActor extends User
{
    public function gadgets(): HasMany
    {
        return $this->hasMany(Gadget::class, 'user_id');
    }

    protected $table = 'public_read_actors';

    protected $guarded = [];

    public $timestamps = false;
}

class PrivateGadgetProjection extends Data
{
    public function __construct(public string $id, public int $userId) {}
}
