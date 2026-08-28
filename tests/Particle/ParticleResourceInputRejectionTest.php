<?php

namespace Splicewire\Beam\Tests\Particle;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\TestCase;

/**
 * api-surface-coherence 69 — `input: false` on the RESOURCE axis.
 *
 * Until this shipped the resource axis was two-state (`?string`) while the operation axis
 * ({@see ParticleOperation::$input}) was three. A resource whose every
 * attribute is server-derived by its `prepare()` hook therefore had no way to say "accepts nothing":
 * it declared `null`, and `null` routes the raw request through `toAttributes()`, which snake-maps
 * every body key onto the model AFTER `prepare()` has force-filled — so the body wins.
 *
 * 65's `withoutStructuralColumns()` does NOT cover this. That guard strips the bound relation's key
 * columns under a RELATIVE mount; a flat mount has no relative context and the payload passes through
 * untouched. The live instance was `seller-repo-authorizations` (a flat mount), where the body could
 * set `status: 'active'` and arbitrary `repos` on a GitHub repo authorization.
 *
 * `null` is deliberately UNCHANGED here — see the flip's gate on `ParticleOperation::$input`'s docblock.
 */
class ParticleResourceInputRejectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(new LedgerUser);
        Gate::before(fn () => true);

        Schema::create('entries', function (Blueprint $table): void {
            $table->id();
            $table->string('state')->nullable();
            $table->string('note')->nullable();
        });

        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'entries',
            backing: Entry::class,
            data: EntryData::class,
            input: false,
            filterable: false,
            prepare: function (Model $model): void {
                $model->forceFill(['state' => 'pending']);
            },
        ));
    }

    public function test_a_create_with_no_body_is_the_normal_path(): void
    {
        Particle::mount('entries', 'entries')->only(['store']);

        $this->postJson('/entries')->assertCreated()->assertJsonPath('data.state', 'pending');
    }

    public function test_a_create_carrying_a_body_is_rejected_rather_than_silently_stripped(): void
    {
        Particle::mount('entries', 'entries')->only(['store']);

        $this->postJson('/entries', ['state' => 'settled', 'note' => 'forged'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['state', 'note']);

        $this->assertSame(0, Entry::count());
    }

    public function test_an_update_carrying_a_body_is_rejected_too(): void
    {
        $entry = Entry::create(['state' => 'pending', 'note' => null]);

        Particle::mount('entries', 'entries')->only(['update']);

        $this->putJson("/entries/{$entry->id}", ['state' => 'settled'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['state']);

        $this->assertSame('pending', $entry->fresh()->state);
    }

    public function test_the_rejection_names_the_resource_not_the_route(): void
    {
        Particle::mount('entries', 'entries')->only(['store']);

        $this->postJson('/entries', ['note' => 'forged'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The `entries` resource accepts no input.');
    }
}

class Entry extends Model
{
    public $timestamps = false;

    protected $table = 'entries';

    protected $guarded = [];
}

class EntryData extends Data
{
    public function __construct(public int $id, public ?string $state, public ?string $note) {}
}

class LedgerUser extends User
{
    protected $table = 'users';
}
