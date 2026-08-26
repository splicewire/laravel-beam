<?php

namespace Splicewire\Beam\Tests\Particle;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\TestCase;

/**
 * api-surface-coherence 65 — the relative mount's structural-FK guarantee, ENFORCED rather than asserted
 * in a comment.
 *
 * `ParticleController::createParticle()` documents its own contract: *"create THROUGH the bound relation
 * so the FK is set from the bound parent — structural association, never a forgeable body field."* That
 * held only for a resource whose `input:` DTO happened not to carry the FK. An `input: null` resource
 * routes the RAW request through `toAttributes()`, which snake-maps every body key onto the model — so
 * `model_type`/`model_id` in the body OVERWROTE the association the mount had just made structurally.
 * Measured on the live `media` particle: `POST /fragments/{fragment}/media` with a forged
 * `modelType`/`modelId` persisted the forged owner, not the bound fragment.
 *
 * The fix is at the mount, not at the resource: the bound relation's own key columns are stripped from
 * every write payload under a relative mount, whatever declared them. A resource that wants the FK
 * settable has no business being mounted relatively.
 */
class ParticleRelativeStructuralFkTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(new JournalUser);
        Gate::before(fn () => true);

        Schema::create('journals', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
        });

        Schema::create('memos', function (Blueprint $table): void {
            $table->id();
            $table->string('memoable_type')->nullable();
            $table->unsignedBigInteger('memoable_id')->nullable();
            $table->string('body');
        });

        // Deliberately `input: null` — the media shape. The raw request becomes the write payload.
        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'memos',
            backing: Memo::class,
            data: MemoData::class,
            filterable: false,
        ));
    }

    public function test_a_relative_create_ignores_a_forged_morph_owner_in_the_body(): void
    {
        $mine = Journal::create(['title' => 'mine']);
        $theirs = Journal::create(['title' => 'theirs']);

        Route::particleRelative('journals', Journal::class, via: 'notes', routes: function () {
            Route::particleResource('memos', 'memos', ['only' => ['store']]);
        });

        $created = $this->postJson("/journals/{$mine->id}/memos", [
            'body' => 'forged',
            'memoableType' => Journal::class,
            'memoableId' => $theirs->id,
        ])->assertCreated()->json('data');

        $note = Memo::find($created['id']);

        // Structural: the owner is the BOUND parent, not the one the body named.
        $this->assertSame((string) $mine->id, (string) $note->memoable_id);
        $this->assertSame($mine->getMorphClass(), $note->memoable_type);
    }

    public function test_a_relative_update_ignores_a_forged_morph_owner_in_the_body(): void
    {
        $mine = Journal::create(['title' => 'mine']);
        $theirs = Journal::create(['title' => 'theirs']);
        $note = $mine->notes()->create(['body' => 'original']);

        Route::particleRelative('journals', Journal::class, via: 'notes', routes: function () {
            Route::particleResource('memos', 'memos', ['only' => ['update']]);
        });

        $this->putJson("/journals/{$mine->id}/memos/{$note->id}", [
            'body' => 'edited',
            'memoableType' => Journal::class,
            'memoableId' => $theirs->id,
        ])->assertOk();

        $note->refresh();

        $this->assertSame('edited', $note->body);
        $this->assertSame((string) $mine->id, (string) $note->memoable_id);
        $this->assertSame($mine->getMorphClass(), $note->memoable_type);
    }
}

class Journal extends Model
{
    public $timestamps = false;

    protected $table = 'journals';

    protected $guarded = [];

    public function notes(): MorphMany
    {
        return $this->morphMany(Memo::class, 'memoable');
    }
}

class Memo extends Model
{
    public $timestamps = false;

    protected $table = 'memos';

    protected $guarded = [];
}

class MemoData extends Data
{
    public function __construct(public int $id, public ?string $memoable_type, public mixed $memoable_id, public string $body) {}
}

class JournalUser extends User
{
    protected $table = 'users';
}
