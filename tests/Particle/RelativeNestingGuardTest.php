<?php

namespace Splicewire\Beam\Tests\Particle;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Tests\TestCase;

/**
 * particle-operation-surface 07 §A4 / 15 — **edges compose, and the innermost one wins.**
 *
 * Nesting a relative inside a relative was never blocked, and it very nearly worked by accident: Laravel's
 * `RouteGroup::formatPrefix` is `trim($old,'/').'/'.trim($new,'/')`, so an inner edge mounted with an empty
 * parent URI lands under the outer prefix with no doubled segment and no leading-slash defect.
 *
 * What did NOT work is the stamping. `ParticleMounter::relative()` snapshots the route table, runs the
 * group, and then stamps `RELATIVE`/`RELATIVE_MODEL`/`VIA` onto every route that appeared — which includes
 * the routes an INNER `relative()` call already stamped, because the inner call runs while the outer
 * group's callback is still executing. `Route::defaults()` overwrites, so the outer edge silently won and
 * a doubly-nested child resolved its parent as the OUTERMOST binding.
 *
 * That failure is invisible in every static check: the route exists, matches, and returns 200 — it is just
 * scoped to the wrong parent. These tests are the only instrument that sees it.
 */
class RelativeNestingGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['albums', 'discs', 'tracks'] as $table) {
            Schema::create($table, function (Blueprint $table): void {
                $table->id();
                $table->string('slug');
            });
        }
    }

    public function test_an_inner_relative_keeps_its_own_parent_binding_when_nested_inside_an_outer_one(): void
    {
        Particle::relative('albums', NestingAlbum::class, via: 'discs', options: ['binding' => 'album'], routes: function (): void {
            // The inner edge, mounted with an EMPTY parent URI so it composes under the outer prefix
            // rather than restating it.
            Particle::relative('', NestingDisc::class, via: 'tracks', routes: function (): void {
                Route::get('probe', fn () => 'ok')->name('deep.probe');
            }, options: ['binding' => 'disc']);
        });

        Route::getRoutes()->refreshNameLookups();
        $route = Route::getRoutes()->getByName('deep.probe');

        $this->assertNotNull($route, 'the nested route did not mount at all');

        // The prefixes composed, with no doubled segment and no leading slash.
        $this->assertSame('albums/{album}/{disc}/probe', $route->uri());

        // THE ASSERTION THIS FILE EXISTS FOR. Before the guard these were `album` / NestingAlbum::class / 'discs'
        // — the outer edge overwrote the inner one's stamp and the child resolved the wrong parent.
        $this->assertSame('disc', $route->defaults[ParticleController::RELATIVE]);
        $this->assertSame(NestingDisc::class, $route->defaults[ParticleController::RELATIVE_MODEL]);
        $this->assertSame('tracks', $route->defaults[ParticleController::VIA]);
    }

    public function test_the_outer_relative_still_stamps_routes_the_inner_one_did_not_write(): void
    {
        // The guard skips a route that ALREADY carries a RELATIVE default — it must not skip siblings that
        // legitimately belong to the outer edge. A guard that over-reaches would leave the outer edge's own
        // children unscoped, which is the same class of silent bug pointed the other way.
        Particle::relative('albums', NestingAlbum::class, via: 'discs', options: ['binding' => 'album'], routes: function (): void {
            Route::get('sibling', fn () => 'ok')->name('outer.sibling');

            Particle::relative('', NestingDisc::class, via: 'tracks', routes: function (): void {
                Route::get('probe', fn () => 'ok')->name('inner.probe');
            }, options: ['binding' => 'disc']);
        });

        Route::getRoutes()->refreshNameLookups();
        $outer = Route::getRoutes()->getByName('outer.sibling');
        $inner = Route::getRoutes()->getByName('inner.probe');

        $this->assertSame('album', $outer->defaults[ParticleController::RELATIVE]);
        $this->assertSame(NestingAlbum::class, $outer->defaults[ParticleController::RELATIVE_MODEL]);

        $this->assertSame('disc', $inner->defaults[ParticleController::RELATIVE]);
    }

    public function test_an_unnested_relative_is_unchanged_by_the_guard(): void
    {
        // The estate's whole existing population is single-level. The guard must be a no-op for it —
        // the map's standing preference is that the existing population does not have to change.
        Particle::relative('albums', NestingAlbum::class, via: 'discs', options: ['binding' => 'album'], routes: function (): void {
            Route::get('flat', fn () => 'ok')->name('flat.probe');
        });

        Route::getRoutes()->refreshNameLookups();
        $route = Route::getRoutes()->getByName('flat.probe');

        $this->assertSame('albums/{album}/flat', $route->uri());
        $this->assertSame('album', $route->defaults[ParticleController::RELATIVE]);
        $this->assertSame(NestingAlbum::class, $route->defaults[ParticleController::RELATIVE_MODEL]);
        $this->assertSame('discs', $route->defaults[ParticleController::VIA]);
    }
}

class NestingAlbum extends Model
{
    protected $table = 'albums';

    public $timestamps = false;

    protected $fillable = ['slug'];
}

class NestingDisc extends Model
{
    protected $table = 'discs';

    public $timestamps = false;

    protected $fillable = ['slug'];
}
