<?php

namespace Splicewire\Beam\Tests\Authorization;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Authorization\ResourceReadGuard;
use Splicewire\Beam\Authorization\ResourceVisibility;
use Splicewire\Beam\Http\Particle\ParticleController;
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
    protected $table = 'public_read_actors';

    protected $guarded = [];

    public $timestamps = false;
}
