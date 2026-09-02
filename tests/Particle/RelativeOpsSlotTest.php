<?php

namespace Splicewire\Beam\Tests\Particle;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Particle\Attributes\ParticleRelative;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Tests\TestCase;

/**
 * particle-operation-surface 15 (07 §D1/§D2, unblocked by 16) — **the edge declaration can say its OPS and
 * its SUB-EDGES.**
 *
 * `PendingParticleMount` already had `ops()` and `relatives()`, and `mountRelative()` called neither, so
 * an op on a child mounted under a declared edge was reachable only by a hand-written
 * `Particle::mount(childUri)->ops(…)` inside the `routes:` closure of the imperative spelling — the exact
 * restatement the attribute exists to remove. These pin the two slots, the wildcard, the opt-in default
 * (01's rule: a package shipping a `#[ParticleOp]` can never add a route to a host that did not ask), and
 * the one naming fact the mounter used to get wrong: an op mounted under an edge takes the edge's `names:`
 * stem, exactly as the child's CRUD does, instead of deriving a flat `{child}.{op}` that collides with the
 * child's own flat mount.
 */
class RelativeOpsSlotTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('hulls', function (Blueprint $table): void {
            $table->id();
        });

        Schema::create('holds', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('hull_id')->nullable();
        });

        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('hold_id')->nullable();
        });

        $registry = $this->app->make(ParticleOperationRegistry::class);

        $registry->register(new ParticleOperation(
            resource: 'holds', name: 'seal', kind: OperationKind::Write,
            handle: fn (Hold $hold) => ['data' => ['sealed' => $hold->getKey()]],
        ));

        $registry->register(new ParticleOperation(
            resource: 'holds', name: 'inspect', kind: OperationKind::Read,
            handle: fn (Hold $hold) => ['data' => ['inspected' => $hold->getKey()]],
        ));
    }

    private function route(string $name): ?\Illuminate\Routing\Route
    {
        Route::getRoutes()->refreshNameLookups();

        return Route::getRoutes()->getByName($name);
    }

    public function test_an_edge_that_declares_no_ops_mounts_no_operations(): void
    {
        // The opt-in rule does not bend: two ops are registered for `holds`, and an edge that says
        // nothing about them mounts neither.
        Particle::relatives('hulls', [HullHoldRelative::class]);

        $this->assertNotNull($this->route('hulls.holds.index'));
        $this->assertNull($this->route('hulls.holds.seal'));
        $this->assertNull($this->route('hulls.holds.inspect'));
        $this->assertNull($this->route('holds.seal'));
    }

    public function test_an_edge_declaring_a_list_of_ops_mounts_them_under_the_bound_parent(): void
    {
        Particle::relatives('hulls', [HullHoldSealRelative::class]);

        $seal = $this->route('hulls.holds.seal');

        $this->assertNotNull($seal, 'the declared op did not mount under the edge');
        $this->assertSame('hulls/{hull}/holds/{id}/seal', $seal->uri());
        $this->assertSame(['POST'], $seal->methods());

        // It is a relative route like the child's CRUD — the edge stamps it, so the controller resolves
        // the subject inside the bound parent's set (07 §D3: the subject is the CHILD, the parent narrows).
        $this->assertSame('hull', $seal->defaults[ParticleController::RELATIVE]);
        $this->assertSame(Hull::class, $seal->defaults[ParticleController::RELATIVE_MODEL]);
        $this->assertSame('holds', $seal->defaults[ParticleController::VIA]);
        $this->assertSame('holds', $seal->defaults[ParticleOperationController::RESOURCE]);
        $this->assertSame('seal', $seal->defaults[ParticleOperationController::NAME]);

        // The list was ONE op; the other registered op stays unmounted.
        $this->assertNull($this->route('hulls.holds.inspect'));

        // The deprecated `/op/` alias keeps the OLD flat name (particle-operation-surface 12) — the stem
        // reaches the primary only, because the alias exists to keep old `route()` calls resolving.
        $alias = $this->route('holds.op.seal');
        $this->assertNotNull($alias);
        $this->assertSame('hulls/{hull}/holds/{id}/op/seal', $alias->uri());
    }

    public function test_ops_true_is_the_wildcard_and_mounts_every_registered_op_for_the_child(): void
    {
        Particle::relatives('hulls', [HullHoldAllOpsRelative::class]);

        $this->assertNotNull($this->route('hulls.holds.seal'));
        $this->assertNotNull($this->route('hulls.holds.inspect'));
        $this->assertSame('hulls/{hull}/holds/{id}/inspect', $this->route('hulls.holds.inspect')->uri());
    }

    public function test_an_op_under_an_edge_takes_the_edges_name_stem_not_the_childs_flat_name(): void
    {
        // The collision 51 §3 recorded for CRUD, one axis over: without the stem an op under the edge
        // derives `holds.seal`, the same name the child's FLAT mount derives, and Laravel's name table is
        // last-wins. The stem is what keeps both exposures addressable.
        Particle::mount('holds', 'holds')->only(['index'])->ops(['seal']);
        Particle::relatives('hulls', [HullHoldSealRelative::class]);

        $flat = $this->route('holds.seal');
        $nested = $this->route('hulls.holds.seal');

        $this->assertNotNull($flat);
        $this->assertNotNull($nested);
        $this->assertSame('holds/{id}/seal', $flat->uri());
        $this->assertSame('hulls/{hull}/holds/{id}/seal', $nested->uri());
    }

    public function test_only_true_is_the_wildcard_for_the_childs_crud_verbs(): void
    {
        Particle::relatives('hulls', [HullHoldOnlyTrueRelative::class]);

        foreach (['index', 'show', 'store', 'update', 'destroy'] as $verb) {
            $this->assertNotNull($this->route("hulls.all-holds.{$verb}"), "`only: true` did not mount {$verb}");
        }
    }

    public function test_only_false_mounts_no_crud_so_an_edge_can_carry_ops_alone(): void
    {
        // An edge whose whole surface is operations on the child — no CRUD asked for, none mounted.
        Particle::relatives('hulls', [HullHoldOpsOnlyRelative::class]);

        foreach (['index', 'show', 'store', 'update', 'destroy'] as $verb) {
            $this->assertNull($this->route("hulls.sealed-holds.{$verb}"), "`only: false` still mounted {$verb}");
        }

        $this->assertNotNull($this->route('hulls.sealed-holds.seal'));
    }

    public function test_an_edge_declaring_relatives_composes_a_second_edge_underneath_it(): void
    {
        // 07 §D4 — edges compose recursively, and the nesting guard keeps the INNER binding.
        Particle::relatives('hulls', [HullHoldWithTagsRelative::class]);

        $tags = $this->route('hulls.holds.tags.index');

        $this->assertNotNull($tags, 'the sub-edge did not mount under the outer edge');
        $this->assertSame('hulls/{hull}/holds/{hold}/tags', $tags->uri());
        $this->assertSame('hold', $tags->defaults[ParticleController::RELATIVE]);
        $this->assertSame(Hold::class, $tags->defaults[ParticleController::RELATIVE_MODEL]);
        $this->assertSame('tags', $tags->defaults[ParticleController::VIA]);

        // The outer edge's own child routes are still the outer edge's.
        $this->assertSame('hull', $this->route('hulls.holds.index')->defaults[ParticleController::RELATIVE]);
    }
}

class Hull extends Model
{
    protected $table = 'hulls';

    public $timestamps = false;

    public function holds(): HasMany
    {
        return $this->hasMany(Hold::class);
    }
}

class Hold extends Model
{
    protected $table = 'holds';

    public $timestamps = false;

    protected $fillable = ['hull_id'];

    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }
}

class Tag extends Model
{
    protected $table = 'tags';

    public $timestamps = false;

    protected $fillable = ['hold_id'];
}

#[ParticleRelative(child: 'holds', of: 'hulls', model: Hull::class, via: 'holds', binding: 'hull', only: ['index'], names: 'hulls.holds')]
class HullHoldRelative {}

#[ParticleRelative(child: 'holds', of: 'hulls', model: Hull::class, via: 'holds', binding: 'hull', only: ['index'], names: 'hulls.holds', ops: ['seal'])]
class HullHoldSealRelative {}

#[ParticleRelative(child: 'holds', of: 'hulls', model: Hull::class, via: 'holds', binding: 'hull', only: ['index'], names: 'hulls.holds', ops: true)]
class HullHoldAllOpsRelative {}

#[ParticleRelative(child: 'holds', of: 'hulls', model: Hull::class, via: 'holds', binding: 'hull', only: true, names: 'hulls.all-holds', childAt: 'all-holds')]
class HullHoldOnlyTrueRelative {}

#[ParticleRelative(child: 'holds', of: 'hulls', model: Hull::class, via: 'holds', binding: 'hull', only: false, names: 'hulls.sealed-holds', childAt: 'sealed-holds', ops: ['seal'])]
class HullHoldOpsOnlyRelative {}

#[ParticleRelative(child: 'holds', of: 'hulls', model: Hull::class, via: 'holds', binding: 'hull', only: ['index'], names: 'hulls.holds', relatives: [HoldTagRelative::class])]
class HullHoldWithTagsRelative {}

/**
 * The sub-edge. Declared exactly as it would be for a FLAT `holds` mount — `of: 'holds'`, no `at:` — and it
 * composes under the outer edge anyway (07 §D4): the builder mounts it inside the outer edge's route
 * group, whose prefix already carries `hulls/{hull}`, so the same declaration answers at both depths.
 * (`at: ''` is the hand-written idiom for an inner `Particle::relative()` written directly inside an
 * outer one's `routes:` closure, where no child mount sits between them.)
 */
#[ParticleRelative(child: 'tags', of: 'holds', model: Hold::class, via: 'tags', binding: 'hold', only: ['index'], names: 'hulls.holds.tags')]
class HoldTagRelative {}
