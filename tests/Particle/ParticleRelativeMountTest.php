<?php

namespace Splicewire\Beam\Tests\Particle;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\TestCase;

/**
 * HTTP-02 — the two beam-core particle route macros + the ParticleController relative-context read.
 *
 * Proves the abstraction the ticket demands: the SAME particle class mounts both standalone
 * (`/photos/{id}`) and RELATIVE (`/albums/{album}/photos`), a relation-name `via:` auto-associates the FK
 * on create while a scope-closure `via:` only scopes (no auto-associate), and `Route::particleOps` collapses
 * a list of op declarations (inline object + `#[ParticleOp]` class-string + bare name) into one call.
 */
class ParticleRelativeMountTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The write pipeline is deny-by-default and gate abilities require an authenticated user; act as one
        // and open the gate, so these tests assert the FK/scoping mechanics (HTTP-02) rather than re-testing
        // authorization (covered elsewhere).
        $this->actingAs(new RelativeUser);
        Gate::before(fn () => true);

        Schema::create('albums', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
        });

        Schema::create('photos', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('album_id')->nullable();
            $table->string('caption');
        });

        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'photos',
            backing: Photo::class,
            data: PhotoData::class,
            input: PhotoInput::class,
            filterable: false,
        ));

        $album = Album::create(['title' => 'Vacation']);
        $other = Album::create(['title' => 'Work']);
        Photo::create(['album_id' => $album->id, 'caption' => 'beach']);
        Photo::create(['album_id' => $album->id, 'caption' => 'sunset']);
        Photo::create(['album_id' => $other->id, 'caption' => 'meeting']);
        Photo::create(['album_id' => null, 'caption' => 'orphan']);
    }

    // ---- Standalone mount (base shape) ---------------------------------------------------------------

    public function test_the_same_particle_class_mounts_standalone(): void
    {
        Particle::mount('photos', 'photos')->only(['index', 'show', 'store']);

        // The standalone index sees EVERY photo — no relative scoping.
        $data = $this->getJson('/photos')->assertOk()->json('data');
        $this->assertCount(4, $data);

        // A standalone create sets no FK from a parent (album_id stays null unless the body carries it).
        $created = $this->postJson('/photos', ['caption' => 'standalone'])->assertCreated()->json('data');
        $this->assertNull(Photo::find($created['id'])->album_id);
    }

    // ---- Relative mount — relation-name via: (scopes AND auto-associates) ----------------------------

    public function test_a_relative_relation_via_scopes_the_index_to_the_bound_parent(): void
    {
        Particle::relative('albums', Album::class, via: 'photos', routes: function () {
            Particle::mount('photos', 'photos')->only(['index', 'store']);
        });

        $album = Album::where('title', 'Vacation')->firstOrFail();

        // Only the two photos hanging off THIS album — the other album's + the orphan must not leak.
        $data = $this->getJson("/albums/{$album->id}/photos")->assertOk()->json('data');
        $this->assertCount(2, $data);
        $this->assertEqualsCanonicalizing(['beach', 'sunset'], array_column($data, 'caption'));
    }

    public function test_a_relative_relation_via_auto_associates_the_fk_on_create(): void
    {
        Particle::relative('albums', Album::class, via: 'photos', routes: function () {
            Particle::mount('photos', 'photos')->only(['store']);
        });

        $album = Album::where('title', 'Work')->firstOrFail();

        // The FK comes STRUCTURALLY from the bound parent — never a body field.
        $created = $this->postJson("/albums/{$album->id}/photos", ['caption' => 'new-work-photo'])
            ->assertCreated()->json('data');

        $this->assertSame($album->id, Photo::find($created['id'])->album_id);
    }

    public function test_a_relative_mount_404s_a_stranger_parent_id(): void
    {
        Particle::relative('albums', Album::class, via: 'photos', routes: function () {
            Particle::mount('photos', 'photos')->only(['index']);
        });

        $this->getJson('/albums/99999/photos')->assertNotFound();
    }

    /**
     * The regression: a relative mount has TWO route parameters, and Laravel splices them into the action
     * POSITIONALLY — so the bound parent, not the subject, landed in `show(Request, string $id)`.
     * `findOrFail()` unwraps a Model to its key and the relative base query keeps the result inside the
     * parent's own set, so the request answered **200 with the wrong photo** instead of failing loudly
     * (`destroy` deleted it). `subjectId()` reads `{id}` by NAME; these pin the read verbs against it.
     */
    public function test_a_relative_show_resolves_the_subject_not_the_bound_parent(): void
    {
        Particle::relative('albums', Album::class, via: 'photos', routes: function () {
            Particle::mount('photos', 'photos')->only(['show', 'destroy']);
        });

        $album = Album::where('title', 'Vacation')->firstOrFail();

        // 'sunset' is deliberately NOT the album's first photo — resolving the parent's id would return
        // 'beach' with a 200, which is exactly what this used to do.
        $sunset = Photo::where('caption', 'sunset')->firstOrFail();

        $this->getJson("/albums/{$album->id}/photos/{$sunset->id}")
            ->assertOk()->assertJsonPath('data.caption', 'sunset');
    }

    public function test_a_relative_destroy_deletes_the_subject_not_the_bound_parents_first_child(): void
    {
        Particle::relative('albums', Album::class, via: 'photos', routes: function () {
            Particle::mount('photos', 'photos')->only(['destroy']);
        });

        $album = Album::where('title', 'Vacation')->firstOrFail();
        $sunset = Photo::where('caption', 'sunset')->firstOrFail();

        $this->deleteJson("/albums/{$album->id}/photos/{$sunset->id}")->assertOk();

        $this->assertNull(Photo::find($sunset->id));
        // The sibling the positional splice would have destroyed instead is untouched.
        $this->assertNotNull(Photo::where('caption', 'beach')->first());
    }

    // ---- Relative mount — scope-closure via: (scopes, NO auto-associate) -----------------------------

    public function test_a_relative_closure_via_scopes_but_does_not_auto_associate(): void
    {
        Particle::relative('albums', Album::class,
            via: fn (Album $album, Builder $q) => $q->where('album_id', $album->id),
            routes: function () {
                Particle::mount('photos', 'photos')->only(['index', 'store']);
            });

        $album = Album::where('title', 'Vacation')->firstOrFail();

        // Scopes the index like the relation form…
        $data = $this->getJson("/albums/{$album->id}/photos")->assertOk()->json('data');
        $this->assertCount(2, $data);

        // …but a closure via CANNOT auto-associate — the FK is NOT set from the parent (stays null here).
        $created = $this->postJson("/albums/{$album->id}/photos", ['caption' => 'closure-create'])
            ->assertCreated()->json('data');
        $this->assertNull(Photo::find($created['id'])->album_id);
    }

    // ---- Particle::ops — the plural loop-collapse -----------------------------------------------

    public function test_particle_ops_registers_and_mounts_inline_class_string_and_bare_forms(): void
    {
        // A bare name presupposes prior registration.
        $this->app->make(ParticleOperationRegistry::class)->register(new ParticleOperation(
            resource: 'photos', name: 'archive', kind: OperationKind::Write, model: Photo::class,
            handle: fn (Photo $p) => ['data' => ['archived' => $p->getKey()]],
        ));

        Particle::ops('photos', 'photos', [
            new ParticleOperation(
                resource: 'photos', name: 'feature', kind: OperationKind::Write, model: Photo::class,
                handle: fn (Photo $p) => ['data' => ['featured' => $p->getKey()]],
            ),
            CaptionOp::class,   // a #[ParticleOp] class-string
            'archive',          // a bare name
        ]);

        $registry = $this->app->make(ParticleOperationRegistry::class);
        // All three op names are registered…
        $this->assertSame('feature', $registry->get('photos', 'feature')->name);
        $this->assertSame('caption', $registry->get('photos', 'caption')->name);
        $this->assertSame('archive', $registry->get('photos', 'archive')->name);

        // …and all three are mounted at POST /photos/{id}/op/{name}.
        $names = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($r) => $r->uri())
            ->filter(fn ($u) => str_starts_with($u, 'photos/{id}/op/'))
            ->values()->all();

        $this->assertContains('photos/{id}/op/feature', $names);
        $this->assertContains('photos/{id}/op/caption', $names);
        $this->assertContains('photos/{id}/op/archive', $names);
    }
}

class RelativeUser extends User
{
    protected $table = 'users';

    public $exists = true;

    public function getAuthIdentifier()
    {
        return 1;
    }
}

class Album extends Model
{
    public $timestamps = false;

    protected $table = 'albums';

    protected $guarded = [];

    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class);
    }
}

class Photo extends Model
{
    public $timestamps = false;

    protected $table = 'photos';

    protected $guarded = [];
}

class PhotoData extends Data
{
    public function __construct(public int $id, public ?int $album_id, public string $caption) {}
}

class PhotoInput extends Data
{
    public function __construct(public string $caption) {}
}

#[\Splicewire\Beam\Particle\Attributes\ParticleOp(resource: 'photos', name: 'caption', kind: OperationKind::Write, model: Photo::class)]
class CaptionOp
{
    public static function handle(Photo $photo, Request $request): array
    {
        return ['data' => ['captioned' => $photo->getKey()]];
    }
}
