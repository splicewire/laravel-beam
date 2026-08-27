<?php

namespace Splicewire\Beam\Tests\Particle;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Particle\Attributes\ParticleRelative;
use Splicewire\Beam\Particle\ParticleRelative as ParticleRelativeRuntime;
use Splicewire\Beam\Particle\ParticleRelativeRegistry;
use Splicewire\Beam\Tests\TestCase;

/**
 * api-surface-coherence ticket 50 — `#[ParticleRelative]`: the edge declares itself.
 *
 * A relative mount used to be a hand-written `Route::particleRelative(…)` in a host route file: three
 * facts about a relationship between two resources, stated as a mount, invisible to anything that did
 * not read the route file. These tests pin the declaration, the registry it accumulates into, and the
 * one property that decides whether the build was worth doing — that a DECLARED edge produces the same
 * routes the hand-written one did.
 */
class ParticleRelativeDeclarationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('vessels', function (Blueprint $table): void {
            $table->id();
            $table->string('slug');
        });

        Schema::create('crates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('vessel_id')->nullable();
            $table->boolean('sealed')->default(false);
        });
    }

    /**
     * The rows `$mount` adds to the route table, in registration order.
     *
     * Snapshotting by object identity rather than re-reading the whole collection is what lets two
     * spellings be compared inside ONE application — which matters, because the thing under test is
     * whether they produce the same routes, and rebuilding the app between them would compare two
     * tables that were never adjacent.
     */
    private function routesAddedBy(callable $mount): array
    {
        $before = [];

        foreach (Route::getRoutes()->getRoutes() as $existing) {
            $before[spl_object_id($existing)] = true;
        }

        $mount();

        $rows = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (isset($before[spl_object_id($route)])) {
                continue;
            }

            $rows[] = implode(' | ', [
                implode(',', $route->methods()),
                $route->uri(),
                (string) $route->getName(),
                json_encode($route->defaults),
            ]);
        }

        return $rows;
    }

    // ---- the declaration and its registry --------------------------------------------------------

    public function test_the_attribute_registers_a_runtime_edge_keyed_parent_dot_child(): void
    {
        Particle::relatives('vessels', [VesselCrateRelative::class]);

        $registry = $this->app->make(ParticleRelativeRegistry::class);

        $this->assertSame(['vessels.crates'], array_map('strval', array_map(
            fn ($key): string => str_replace('beam.particle.relatives.', '', (string) $key),
            $registry->keys(),
        )));

        $edge = $registry->get('vessels', 'crates');

        $this->assertSame('crates', $edge->child);
        $this->assertSame('vessels', $edge->of);
        $this->assertSame(Vessel::class, $edge->model);
        $this->assertSame('crates', $edge->via);
        // The declaring class travels with the declaration — it is the serializable reference a Closure
        // `via` cannot be, and the mount stamps it rather than a Closure.
        $this->assertSame(VesselCrateRelative::class, $edge->declaredBy);
    }

    public function test_a_parents_edges_are_a_one_step_branch_read_rather_than_a_scan(): void
    {
        // What the dotted key bought, and the read `Particle::relatives($parent)` runs. `crates` is the
        // child of TWO parents here, which is 50's "many edges per child" — each its own class.
        Particle::relatives('vessels', [VesselCrateRelative::class]);
        Particle::relatives('depots', [DepotCrateRelative::class]);

        $registry = $this->app->make(ParticleRelativeRegistry::class);

        $this->assertSame(['crates'], array_map(fn ($e): string => $e->child, $registry->forParent('vessels')));
        $this->assertSame(['crates'], array_map(fn ($e): string => $e->child, $registry->forParent('depots')));
        $this->assertCount(2, $registry->all());
        $this->assertSame([], $registry->forParent('nothing-declared-here'));
    }

    public function test_two_edges_on_one_child_mount_independently(): void
    {
        // The acceptance criterion, at fixture level because there is no live second case. Two parents,
        // one child resource, two bound prefixes, two name stems, no interference.
        Particle::relatives('vessels', [VesselCrateRelative::class]);
        Particle::relatives('depots', [DepotCrateRelative::class]);

        Route::getRoutes()->refreshNameLookups();

        $this->assertNotNull(Route::getRoutes()->getByName('vessels.crates.index'));
        $this->assertNotNull(Route::getRoutes()->getByName('depots.crates.index'));

        $this->assertSame(
            'vessels/{vessel}/crates',
            Route::getRoutes()->getByName('vessels.crates.index')->uri(),
        );
        $this->assertSame(
            'depots/{depot}/crates',
            Route::getRoutes()->getByName('depots.crates.index')->uri(),
        );
    }

    public function test_an_edge_that_declares_both_a_via_string_and_a_via_method_is_a_declaration_bug(): void
    {
        // The two say different things about one edge, and silently preferring either is how a reader
        // ends up trusting the wrong half.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/declares both a `via:` relation name and a `public static via\(\)`/');

        Particle::relatives('vessels', [ContradictoryRelative::class]);
    }

    public function test_a_runtime_edge_with_no_via_and_no_declaring_class_cannot_be_built(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/declares no `via:` and no declaring class/');

        new ParticleRelativeRuntime(child: 'crates', of: 'vessels', model: Vessel::class);
    }

    // ---- the mount -------------------------------------------------------------------------------

    public function test_a_declared_edge_mounts_the_same_routes_the_hand_written_one_did(): void
    {
        // The property the whole ticket turns on. Anything else — a different prefix, a different name
        // stem, a different set of defaults — and "declare it instead of placing it" would have been a
        // route-table change wearing a refactor's clothes.
        $declared = $this->routesAddedBy(function (): void {
            Particle::relatives('vessels', [VesselCrateRelative::class]);
        });

        $placed = $this->routesAddedBy(function (): void {
            Particle::relative('vessels', Vessel::class, via: 'crates', routes: function (): void {
                Particle::mount('crates')->only(['index', 'store'])->names('vessels.crates')->idConstraint('uuid');
            }, options: ['binding' => 'vessel']);
        });

        // The declared edge stamps the DECLARING CLASS where the hand-placed one stamps the relation
        // name — the one intended difference, and the thing that makes a behavioural edge cacheable.
        // Normalise it away and the two are the same routes.
        $this->assertSame(
            $placed,
            str_replace(VesselCrateRelative::class, 'crates', $declared),
        );
        $this->assertNotSame([], $declared);
    }

    public function test_the_builder_slot_and_the_standalone_verb_mount_the_same_table(): void
    {
        // Two spellings, one body — ticket 49's rule, carried into 50's slot. The builder is the
        // convenience for a parent that IS particle-mounted; the verb is the general form.
        $viaVerb = $this->routesAddedBy(function (): void {
            Particle::relatives('vessels', [VesselCrateRelative::class]);
        });

        $viaBuilder = $this->routesAddedBy(function (): void {
            Particle::mount('vessels')->only([])->filters(false)->hookEvents(false)->relatives([VesselCrateRelative::class])->register();
        });

        $this->assertSame($viaVerb, $viaBuilder);
        $this->assertNotSame([], $viaVerb);
    }

    public function test_the_registry_driven_spelling_mounts_everything_declared_for_the_parent(): void
    {
        $this->app->make(ParticleRelativeRegistry::class)->register(
            new ParticleRelativeRuntime(
                child: 'crates',
                of: 'vessels',
                model: Vessel::class,
                via: 'crates',
                binding: 'vessel',
                only: ['index'],
                names: 'vessels.crates',
            ),
        );

        Particle::relatives('vessels');

        Route::getRoutes()->refreshNameLookups();

        $this->assertNotNull(Route::getRoutes()->getByName('vessels.crates.index'));
        $this->assertNull(Route::getRoutes()->getByName('vessels.crates.store'));
    }

    // ---- §51 §2's promise, kept ------------------------------------------------------------------

    public function test_a_behavioural_edge_stamps_its_class_name_and_the_table_stays_serializable(): void
    {
        // This is the entire reason the convention method exists rather than a `via: fn () => …`. A
        // Closure in the route defaults makes `route:cache` throw (ticket 51 §2); the edge CLASS NAME is
        // a reference, and it round-trips.
        Particle::relatives('vessels', [SealedCrateRelative::class]);

        Route::getRoutes()->refreshNameLookups();

        $route = Route::getRoutes()->getByName('vessels.sealed-crates.index');

        $this->assertSame(SealedCrateRelative::class, $route->defaults[ParticleController::VIA]);
        $this->assertSame($route->defaults, unserialize(serialize($route->defaults)));
    }

    public function test_the_class_name_resolves_back_to_the_convention_method_per_request(): void
    {
        // The other half: a serializable reference is only useful if something turns it back into
        // behaviour at the point of use.
        $vessel = Vessel::create(['slug' => 'argo']);
        $vessel->crates()->create(['sealed' => true]);
        $vessel->crates()->create(['sealed' => false]);

        $resolveVia = new \ReflectionMethod(ParticleController::class, 'resolveVia');
        $resolveVia->setAccessible(true);

        $resolved = $resolveVia->invoke($this->app->make(ParticleController::class), SealedCrateRelative::class);

        $this->assertInstanceOf(\Closure::class, $resolved);
        $this->assertSame(1, $resolved($vessel, Crate::query())->count());
    }
}

// ── fixtures ──────────────────────────────────────────────────────────────────────────────────────

class Vessel extends Model
{
    protected $table = 'vessels';

    public $timestamps = false;

    protected $fillable = ['slug'];

    public function crates(): HasMany
    {
        return $this->hasMany(Crate::class, 'vessel_id');
    }
}

class Depot extends Model
{
    protected $table = 'vessels';

    public $timestamps = false;

    protected $fillable = ['slug'];

    public function crates(): HasMany
    {
        return $this->hasMany(Crate::class, 'vessel_id');
    }
}

class Crate extends Model
{
    protected $table = 'crates';

    public $timestamps = false;

    protected $fillable = ['vessel_id', 'sealed'];
}

#[ParticleRelative(
    child: 'crates',
    of: 'vessels',
    model: Vessel::class,
    via: 'crates',
    binding: 'vessel',
    only: ['index', 'store'],
    names: 'vessels.crates',
    idConstraint: 'uuid',
)]
class VesselCrateRelative {}

#[ParticleRelative(
    child: 'crates',
    of: 'depots',
    model: Depot::class,
    via: 'crates',
    binding: 'depot',
    only: ['index', 'store'],
    names: 'depots.crates',
    idConstraint: 'uuid',
)]
class DepotCrateRelative {}

/** The behavioural edge — no `via:`, a `public static via()` instead. */
#[ParticleRelative(
    child: 'crates',
    of: 'vessels',
    model: Vessel::class,
    binding: 'vessel',
    only: ['index'],
    names: 'vessels.sealed-crates',
    childAt: 'sealed-crates',
)]
class SealedCrateRelative
{
    public static function via(Vessel $vessel, mixed $query): mixed
    {
        return $query->where('vessel_id', $vessel->getKey())->where('sealed', true);
    }
}

#[ParticleRelative(child: 'crates', of: 'vessels', model: Vessel::class, via: 'crates', binding: 'vessel')]
class ContradictoryRelative
{
    public static function via(Vessel $vessel, mixed $query): mixed
    {
        return $query;
    }
}
