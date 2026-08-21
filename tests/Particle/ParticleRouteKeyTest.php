<?php

namespace Splicewire\Beam\Tests\Particle;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\TestCase;

/**
 * `ParticleResource(routeKey:)` — the DECLARED route key (api-surface-coherence 03 §7).
 *
 * A resource says how it is NAMED; the `{id}` segment then resolves against that column instead of the
 * primary key, and the primary key stops resolving entirely. The two things this pins:
 *
 *   1. **One public identifier, never two.** A slug-keyed resource resolves by slug and 404s on its own PK —
 *      a dual-key public route is exactly the "two ways to say the same thing" incoherence the effort removes.
 *   2. **The key inherits the relative base query.** The branch sits BELOW the relative scoping, so a route
 *      key need only be unique per PARENT — which is what lets `/sellers/{seller}/items/{slug}` carry the
 *      hierarchy structurally instead of faking it inside a composite string.
 *
 * A null `routeKey` (every existing resource) is the untouched `findOrFail` path.
 */
class ParticleRouteKeyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(new RkUser);
        Gate::before(fn () => true);

        Schema::create('rk_vendors', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
        });

        Schema::create('rk_listings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->string('slug');
            $table->string('title');
            $table->unique(['seller_id', 'slug']);
        });

        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'rk-items',
            model: Listing::class,
            data: ListingData::class,
            filterable: false,
            routeKey: 'slug',
        ));

        // The same table, declared WITHOUT a route key — the control for "existing resources are unchanged".
        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'rk-items-pk',
            model: Listing::class,
            data: ListingData::class,
            filterable: false,
        ));

        $acme = Vendor::create(['slug' => 'acme']);
        $globex = Vendor::create(['slug' => 'globex']);

        // BOTH sellers ship a `starter` — the slug is unique per seller, never globally. Resolving one of
        // these correctly is only possible because the route key inherits the relative base query.
        Listing::create(['seller_id' => $acme->id, 'slug' => 'starter', 'title' => 'Acme Starter']);
        Listing::create(['seller_id' => $acme->id, 'slug' => 'pro', 'title' => 'Acme Pro']);
        Listing::create(['seller_id' => $globex->id, 'slug' => 'starter', 'title' => 'Globex Starter']);
    }

    // ---- Standalone: the declared key resolves, the primary key does not ------------------------------

    public function test_a_route_keyed_resource_resolves_by_the_declared_column(): void
    {
        Route::particleResource('items', 'rk-items', ['only' => ['show']]);

        $this->getJson('/items/pro')->assertOk()->assertJsonPath('data.title', 'Acme Pro');
    }

    public function test_a_route_keyed_resource_404s_on_its_own_primary_key(): void
    {
        Route::particleResource('items', 'rk-items', ['only' => ['show']]);

        $pro = Listing::where('slug', 'pro')->firstOrFail();

        // The PK stops resolving ENTIRELY — the point of the declaration, not a side effect of it.
        $this->getJson("/items/{$pro->id}")->assertNotFound();
    }

    public function test_a_null_route_key_resource_still_resolves_by_primary_key(): void
    {
        Route::particleResource('pk-items', 'rk-items-pk', ['only' => ['show']]);

        $pro = Listing::where('slug', 'pro')->firstOrFail();

        $this->getJson("/pk-items/{$pro->id}")->assertOk()->assertJsonPath('data.title', 'Acme Pro');

        // …and the slug is NOT a second way in on an undeclared resource.
        $this->getJson('/pk-items/pro')->assertNotFound();
    }

    // ---- Relative mount: the key need only be unique per parent ---------------------------------------

    public function test_a_route_key_resolves_through_the_relative_base_query(): void
    {
        Route::particleRelative('sellers', Vendor::class, via: 'items', routes: function () {
            Route::particleResource('items', 'rk-items', ['only' => ['show']]);
        });

        $acme = Vendor::where('slug', 'acme')->firstOrFail();
        $globex = Vendor::where('slug', 'globex')->firstOrFail();

        // One slug, two sellers, two different rows — the parent segment disambiguates, not the slug.
        $this->getJson("/sellers/{$acme->id}/items/starter")
            ->assertOk()->assertJsonPath('data.title', 'Acme Starter');

        $this->getJson("/sellers/{$globex->id}/items/starter")
            ->assertOk()->assertJsonPath('data.title', 'Globex Starter');
    }

    public function test_a_route_keyed_child_of_another_parent_404s(): void
    {
        Route::particleRelative('sellers', Vendor::class, via: 'items', routes: function () {
            Route::particleResource('items', 'rk-items', ['only' => ['show']]);
        });

        $globex = Vendor::where('slug', 'globex')->firstOrFail();

        // `pro` exists — under Acme. Reached through Globex it must 404, never resolve.
        $this->getJson("/sellers/{$globex->id}/items/pro")->assertNotFound();
    }
}

class RkUser extends User
{
    protected $table = 'users';

    public $exists = true;
}

class Vendor extends Model
{
    public $timestamps = false;

    protected $table = 'rk_vendors';

    protected $guarded = [];

    public function items(): HasMany
    {
        return $this->hasMany(Listing::class, 'seller_id');
    }
}

class Listing extends Model
{
    public $timestamps = false;

    protected $table = 'rk_listings';

    protected $guarded = [];
}

class ListingData extends Data
{
    public function __construct(public int $id, public string $slug, public string $title) {}
}
