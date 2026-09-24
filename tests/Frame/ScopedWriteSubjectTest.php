<?php

namespace Splicewire\Beam\Tests\Frame;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Schemastud\Frame\Contracts\WriteSubjectResolver;
use Schemastud\Frame\FrameServiceProvider;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Particle\ParticleWriteSubjectResolver;
use Splicewire\Beam\Tests\Fixtures\ReadGuard\Gadget;
use Splicewire\Beam\Tests\Fixtures\ReadGuard\GadgetData;
use Splicewire\Beam\Tests\TestCase;

/**
 * Frame's write gate asks a policy about the record a request names. Before beam bound
 * {@see ParticleWriteSubjectResolver}, frame found that record with an UNSCOPED lookup, so a caller naming a
 * row outside the declared `scope` got the policy's 403 — the gate disclosing that the row exists — where
 * the handler's scoped `findOrFail` answers 404. Measured 2026-09-24 at `~/Herd/splicewire-app`: an
 * owner's revoke of another user's token, and of an accepted invitation, were 403.
 */
class ScopedWriteSubjectTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), FrameServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.key', str_repeat('w', 32));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('auth.providers.users.model', ScopedWriteActor::class);
        $app['config']->set('frame.middleware', ['web', 'auth']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('scoped_write_actors', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('gadgets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
        });

        Gate::policy(Gadget::class, OwnGadgetWritePolicy::class);

        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'gadgets',
            backing: Gadget::class,
            data: GadgetData::class,
            scope: fn ($query) => $query->where('user_id', request()->user()->getAuthIdentifier()),
            project: fn (Gadget $gadget): GadgetData => new GadgetData((string) $gadget->getKey()),
            readOnly: true,
            deletable: true,
            label: 'Gadgets',
        ));
    }

    public function test_beam_binds_the_scoped_resolver_for_frames_write_gate(): void
    {
        $this->assertInstanceOf(ParticleWriteSubjectResolver::class, app(WriteSubjectResolver::class));
    }

    public function test_a_row_outside_the_declared_scope_is_404_and_survives(): void
    {
        $actor = ScopedWriteActor::create(['name' => 'Ada']);
        $other = ScopedWriteActor::create(['name' => 'Bo']);
        $foreign = Gadget::create(['user_id' => $other->getKey()]);

        $this->actingAs($actor)->deleteJson("/frame/resources/gadgets/records/{$foreign->getKey()}")->assertNotFound();

        $this->assertNotNull(Gadget::find($foreign->getKey()));
    }

    public function test_a_row_inside_the_scope_is_still_asked_of_the_policy_as_the_persisted_record(): void
    {
        $actor = ScopedWriteActor::create(['name' => 'Ada']);
        $mine = Gadget::create(['user_id' => $actor->getKey()]);

        $this->actingAs($actor)->deleteJson("/frame/resources/gadgets/records/{$mine->getKey()}")->assertNoContent();

        $this->assertNull(Gadget::find($mine->getKey()));
        $this->assertTrue(OwnGadgetWritePolicy::$lastAskedExisted);
    }
}

class ScopedWriteActor extends User
{
    protected $table = 'scoped_write_actors';

    protected $guarded = [];

    public $timestamps = false;
}

/** Owner-only delete — the shape of `TokenPolicy`: an unsaved probe is the class-level "may you delete your own?". */
class OwnGadgetWritePolicy
{
    public static ?bool $lastAskedExisted = null;

    public function viewAny(Authenticatable $user): bool
    {
        return true;
    }

    public function delete(Authenticatable $user, Model $gadget): bool
    {
        self::$lastAskedExisted = $gadget->exists;

        return ! $gadget->exists || (string) $gadget->user_id === (string) $user->getAuthIdentifier();
    }
}
