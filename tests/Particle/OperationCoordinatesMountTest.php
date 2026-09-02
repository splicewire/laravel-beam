<?php

namespace Splicewire\Beam\Tests\Particle;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Route as RouteInstance;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Particle\Attributes\ParticleRelative;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Particle\Subject\ActorSubject;
use Splicewire\Beam\Particle\Subject\NoSubject;
use Splicewire\Beam\Particle\Subject\ParentSubject;
use Splicewire\Beam\Particle\Subject\RecordSubject;
use Splicewire\Beam\Particle\Subject\ResolvesOperationSubject;
use Splicewire\Beam\Particle\Subject\SubjectResolvers;
use Splicewire\Beam\Routing\IdConstraint;
use Splicewire\Beam\Source\ParticleRouteManifestSource;
use Splicewire\Beam\Tests\TestCase;

/**
 * particle-operation-surface 20 (decided by 16 §D1–D4) — **the op mount reads the SUBJECT's coordinates.**
 *
 * `ParticleMounter::op()` used to spell its URI from the literal `{uri}/{id}/{op}`, while the declared
 * subject already carried the list — `ResolvesOperationSubject::pathParameters()` — and nothing read it.
 * These pin the three readers 16 named: the mount derives `{uri}[/{param}…]/{name}` from the list; the
 * `/op/` alias mounts only for the `['id']` shape; `ParentSubject` ships and resolves the edge's bound
 * parent; and the published manifest reads the same list because it reads the route table the mount
 * produced. The first test is the route-table diff the ticket asked for: a declaration with no subject
 * spells exactly what the literal spelled, so the existing population's URLs are byte-identical.
 *
 * Every authorization reading here is taken with the gate CLOSED — asserted in the test itself, per
 * AGENTS.md's gate-posture rule — because a subject-shaped test with the gate open cannot see whether
 * the bound parent is what the ability was checked against.
 */
class OperationCoordinatesMountTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('crates', function (Blueprint $table): void {
            $table->id();
        });

        Schema::create('crate_slots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('crate_id')->nullable();
        });

        Schema::create('crews', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'crews',
            backing: CoordCrew::class,
        ));

        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'crate-slots',
            backing: CoordCrateSlot::class,
        ));
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────────────────

    private function register(ParticleOperation $operation): ParticleOperation
    {
        $this->app->make(ParticleOperationRegistry::class)->register($operation);

        return $operation;
    }

    private function op(string $resource, string $name, mixed $subject = null, ?callable $handle = null, mixed $ability = null): ParticleOperation
    {
        return new ParticleOperation(
            resource: $resource,
            name: $name,
            kind: OperationKind::Write,
            handle: $handle === null
                ? fn ($model) => ['data' => ['subject' => $model?->getKey()]]
                : \Closure::fromCallable($handle),
            ability: $ability,
            subject: $subject,
        );
    }

    /** @return list<array{0: string, 1: string}> every mounted [name, uri] pair for a resource, sorted */
    private function table(string $resource): array
    {
        Route::getRoutes()->refreshNameLookups();

        $rows = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            /** @var RouteInstance $route */
            if (($route->defaults['_particle_op_resource'] ?? null) !== $resource) {
                continue;
            }

            $rows[] = [$route->getName(), $route->uri()];
        }

        sort($rows);

        return $rows;
    }

    private function route(string $name): ?RouteInstance
    {
        Route::getRoutes()->refreshNameLookups();

        return Route::getRoutes()->getByName($name);
    }

    /** The gate-closed assertion AGENTS.md prescribes: an unknown ability must DENY, or the reading is worthless. */
    private function assertGateClosed(mixed $actor): void
    {
        $this->assertFalse(
            Gate::forUser($actor)->allows('probe-nonexistent-ability'),
            'The gate is OPEN in this harness; no authorization reading below can be trusted.',
        );
    }

    // ── 1. Byte-identical for the existing population, by construction ───────────────────────────────

    public function test_a_declaration_with_no_subject_spells_exactly_what_the_id_literal_spelled(): void
    {
        // Two declarations: the implicit default and the explicit `RecordSubject::class`. Both are the
        // `['id']` shape, so both must reproduce the pre-20 route table — primary AND deprecated alias,
        // names included — with nothing moved.
        $this->register($this->op('crews', 'promote'));
        $this->register($this->op('crews', 'demote', subject: RecordSubject::class));

        Particle::ops('crews', 'crews', ['promote', 'demote']);

        $this->assertSame([
            ['crews.demote', 'crews/{id}/demote'],
            ['crews.op.demote', 'crews/{id}/op/demote'],
            ['crews.op.promote', 'crews/{id}/op/promote'],
            ['crews.promote', 'crews/{id}/promote'],
        ], $this->table('crews'));

        // And the coordinate read agrees with the resolver the request path will construct.
        $this->assertSame(['id'], SubjectResolvers::coordinates($this->op('crews', 'x')));
        $this->assertSame(['id'], SubjectResolvers::coordinates(null));
    }

    public function test_the_uuid_constraint_still_lands_on_the_emitted_id(): void
    {
        $this->register(new ParticleOperation(
            resource: 'crews', name: 'archive', kind: OperationKind::Write,
            handle: fn () => null, idConstraint: IdConstraint::Uuid,
        ));

        Particle::ops('crews', 'crews', 'archive');

        $this->assertArrayHasKey('id', $this->route('crews.archive')->wheres);
    }

    // ── 2. Zero coordinates, two meanings ─────────────────────────────────────────────────────────────

    public function test_an_actor_subject_op_mounts_with_no_coordinate_and_no_alias(): void
    {
        // The `me` shape: `users/me`, not `users/{id}/me`. And no `/op/` alias — nothing ever answered
        // at `users/{id}/op/me`, so there is no published URL to keep.
        $this->register($this->op('crews', 'me', subject: ActorSubject::class, handle: fn ($actor) => [
            'data' => ['actor' => $actor::class],
        ]));

        Particle::ops('crews', 'crews', 'me');

        $this->assertSame([['crews.me', 'crews/me']], $this->table('crews'));

        $this->actingAs(new CoordinateUser);

        $this->postJson('/crews/me')->assertOk()->assertJson(['data' => ['actor' => CoordinateUser::class]]);
    }

    public function test_a_no_subject_op_mounts_at_the_collection(): void
    {
        $this->register($this->op('crews', 'purge', subject: NoSubject::class, handle: fn ($subject) => [
            'data' => ['null' => $subject === null],
        ]));

        Particle::ops('crews', 'crews', 'purge');

        $this->assertSame([['crews.purge', 'crews/purge']], $this->table('crews'));

        $this->postJson('/crews/purge')->assertOk()->assertJson(['data' => ['null' => true]]);
    }

    // ── 3. Two coordinates, in the declared order, and `resolve()` gets both ──────────────────────────

    public function test_a_two_coordinate_resolver_mounts_both_in_order_and_receives_both(): void
    {
        $this->register($this->op('crews', 'rename', subject: CrewSectionSubject::class, handle: fn ($subject) => [
            'data' => $subject,
        ]));

        Particle::ops('crews', 'crews', 'rename');

        // `{uri}/{id}/{section}/{name}` — and no alias: the `['id', 'section']` shape never answered under `/op/`.
        $this->assertSame([['crews.rename', 'crews/{id}/{section}/rename']], $this->table('crews'));

        $this->postJson('/crews/7/deck/rename')->assertOk()->assertJson([
            'data' => ['id' => '7', 'section' => 'deck'],
        ]);
    }

    // ── 4. `ParentSubject` under a declared edge ──────────────────────────────────────────────────────

    public function test_a_parent_subject_op_under_an_edge_resolves_the_bound_parent_and_authorizes_against_it(): void
    {
        $seen = null;

        $this->register($this->op('crate-slots', 'reorder', subject: ParentSubject::class, ability: 'reorder',
            handle: function ($parent) use (&$seen) {
                $seen = $parent;

                return ['data' => ['parent' => $parent::class, 'key' => $parent->getKey()]];
            }));

        // The gate: `reorder` is a policy verb on the CRATE — the parent — and only a privileged user has it.
        Gate::define('reorder', fn (?User $user, CoordCrate $crate) => $user instanceof CoordinatePrivilegedUser);

        Particle::relatives('crates', [CrateSlotReorderRelative::class]);

        $reorder = $this->route('crates.crate-slots.reorder');

        $this->assertNotNull($reorder, 'the collection op did not mount under the edge');
        // The parent's coordinate is the EDGE's; the op emits none of its own — no `{id}`, no doubled `{crate}`.
        $this->assertSame('crates/{crate}/crate-slots/reorder', $reorder->uri());
        $this->assertSame('crate', $reorder->defaults[ParticleController::RELATIVE]);
        $this->assertNull($this->route('crate-slots.op.reorder'), 'a collection op has no `/op/` legacy to keep');

        $crate = CoordCrate::create();
        CoordCrate::create();

        // Gate CLOSED, asserted for both actors before any reading is taken.
        $plain = new CoordinateUser;
        $privileged = new CoordinatePrivilegedUser;
        $this->assertGateClosed($plain);
        $this->assertGateClosed($privileged);

        $this->actingAs($plain);
        $this->postJson("/crates/{$crate->getKey()}/crate-slots/reorder")->assertForbidden();
        $this->assertNull($seen, 'the handler ran for an actor the parent gate denied');

        $this->actingAs($privileged);
        $this->postJson("/crates/{$crate->getKey()}/crate-slots/reorder")
            ->assertOk()
            ->assertJson(['data' => ['parent' => CoordCrate::class, 'key' => $crate->getKey()]]);
        $this->assertInstanceOf(CoordCrate::class, $seen);
        $this->assertSame($crate->getKey(), $seen->getKey());

        // A stranger parent is the child CRUD's 404, not a resolver error.
        $this->postJson('/crates/999/crate-slots/reorder')->assertNotFound();
    }

    public function test_a_parent_subject_declared_with_a_named_binding_reads_a_substituted_model_outside_an_edge(): void
    {
        // The INSTANCE spelling: no edge stamp, so the binding is named and the parameter must arrive
        // substituted — which is what `Route::model()` + `SubstituteBindings` do on a hand-written group.
        $this->register($this->op('crate-slots', 'compact', subject: new ParentSubject('crate'), handle: fn ($parent) => [
            'data' => ['parent' => $parent::class, 'key' => $parent->getKey()],
        ]));

        Route::model('crate', CoordCrate::class);

        Route::group(['prefix' => 'crates/{crate}', 'middleware' => [SubstituteBindings::class]], function (): void {
            Particle::ops('crate-slots', 'crate-slots', 'compact');
        });

        $this->assertSame('crates/{crate}/crate-slots/compact', $this->route('crate-slots.compact')->uri());

        $crate = CoordCrate::create();

        $this->postJson("/crates/{$crate->getKey()}/crate-slots/compact")
            ->assertOk()
            ->assertJson(['data' => ['parent' => CoordCrate::class, 'key' => $crate->getKey()]]);
    }

    // ── 5. A coordinate the enclosing group already carries is not emitted twice ──────────────────────

    public function test_a_coordinate_the_enclosing_edge_already_carries_is_not_re_emitted(): void
    {
        $this->register($this->op('crate-slots', 'tag', subject: CrateAndSlotSubject::class, handle: fn ($subject) => [
            'data' => $subject,
        ]));

        Particle::relatives('crates', [CrateSlotTagRelative::class]);

        // The resolver lists `['crate', 'id']`; the edge's prefix already carries `{crate}`, so only `{id}` is emitted.
        $this->assertSame('crates/{crate}/crate-slots/{id}/tag', $this->route('crates.crate-slots.tag')->uri());

        $crate = CoordCrate::create();

        $this->postJson("/crates/{$crate->getKey()}/crate-slots/4/tag")->assertOk()->assertJson([
            'data' => ['crate' => (string) $crate->getKey(), 'id' => '4'],
        ]);
    }

    // ── 6. The manifest reads the same list, because it reads the table the mount wrote ──────────────

    public function test_the_route_manifest_publishes_the_derived_path_and_omits_the_alias(): void
    {
        $this->register($this->op('crews', 'promote'));
        $this->register($this->op('crews', 'me', subject: ActorSubject::class));
        $this->register($this->op('crews', 'rename', subject: CrewSectionSubject::class));

        Particle::ops('crews', 'crews', ['promote', 'me', 'rename']);

        $manifest = $this->app->make(ParticleRouteManifestSource::class)->toArray();

        $this->assertSame('crews/{id}/promote', $manifest['crews.promote']['path']);
        $this->assertSame('crews/me', $manifest['crews.me']['path']);
        $this->assertSame('crews/{id}/{section}/rename', $manifest['crews.rename']['path']);
        $this->assertArrayNotHasKey('crews.op.promote', $manifest, 'the deprecated alias must not reach the client');
    }

    // ── 7. The boot-time read constructs nothing ──────────────────────────────────────────────────────

    public function test_the_coordinate_read_never_constructs_a_class_string_resolver(): void
    {
        ConstructionCountingSubject::$constructed = 0;

        $this->assertSame(['id', 'lane'], SubjectResolvers::coordinates($this->op('crews', 'x', subject: ConstructionCountingSubject::class)));
        $this->assertSame(0, ConstructionCountingSubject::$constructed, 'the mount must read the declaration, not build the resolver');

        // The request path DOES construct it — that is the split `SubjectResolvers` documents.
        SubjectResolvers::for($this->op('crews', 'x', subject: ConstructionCountingSubject::class));
        $this->assertSame(1, ConstructionCountingSubject::$constructed);
    }
}

class CoordCrate extends Model
{
    protected $table = 'crates';

    public $timestamps = false;

    public function slots(): HasMany
    {
        return $this->hasMany(CoordCrateSlot::class);
    }
}

class CoordCrateSlot extends Model
{
    protected $table = 'crate_slots';

    public $timestamps = false;

    protected $fillable = ['crate_id'];
}

class CoordCrew extends Model
{
    protected $table = 'crews';

    public $timestamps = false;

    protected $guarded = [];
}

class CoordinateUser extends User
{
    protected $table = 'users';
}

class CoordinatePrivilegedUser extends CoordinateUser {}

/** `['id', 'section']` — 16 §D4's trailing coordinate as a subject kind. Yields the raw pair so the test can see both. */
class CrewSectionSubject implements ResolvesOperationSubject
{
    public static function pathParameters(): array
    {
        return ['id', 'section'];
    }

    public function yieldsSubject(): bool
    {
        return true;
    }

    public function resolve(ParticleOperation $operation, array $parameters, mixed $actor): ?object
    {
        return (object) ['id' => (string) $parameters['id'], 'section' => (string) $parameters['section']];
    }
}

/** `['crate', 'id']` — a resolver that names the parent's coordinate, which the edge already carries. */
class CrateAndSlotSubject implements ResolvesOperationSubject
{
    public static function pathParameters(): array
    {
        return ['crate', 'id'];
    }

    public function yieldsSubject(): bool
    {
        return true;
    }

    public function resolve(ParticleOperation $operation, array $parameters, mixed $actor): ?object
    {
        $crate = $parameters['crate'];

        return (object) ['crate' => (string) ($crate instanceof Model ? $crate->getKey() : $crate), 'id' => (string) $parameters['id']];
    }
}

class ConstructionCountingSubject implements ResolvesOperationSubject
{
    public static int $constructed = 0;

    public function __construct()
    {
        static::$constructed++;
    }

    public static function pathParameters(): array
    {
        return ['id', 'lane'];
    }

    public function yieldsSubject(): bool
    {
        return true;
    }

    public function resolve(ParticleOperation $operation, array $parameters, mixed $actor): ?object
    {
        return null;
    }
}

#[ParticleRelative(child: 'crate-slots', of: 'crates', model: CoordCrate::class, via: 'slots', binding: 'crate', only: ['index'], names: 'crates.crate-slots', ops: ['reorder'])]
class CrateSlotReorderRelative {}

#[ParticleRelative(child: 'crate-slots', of: 'crates', model: CoordCrate::class, via: 'slots', binding: 'crate', only: ['index'], names: 'crates.crate-slots', ops: ['tag'])]
class CrateSlotTagRelative {}
