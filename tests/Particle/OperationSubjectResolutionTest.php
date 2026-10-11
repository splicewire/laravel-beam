<?php

namespace Splicewire\Beam\Tests\Particle;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Particle\Subject\ActorSubject;
use Splicewire\Beam\Particle\Subject\NoSubject;
use Splicewire\Beam\Particle\Subject\RecordSubject;
use Splicewire\Beam\Particle\Subject\SubjectResolvers;
use Splicewire\Beam\Tests\TestCase;

/**
 * particle-operation-surface ticket 02 — an operation resolves its subject THROUGH the resource.
 *
 * The defect these pin: `ParticleOperationController::invoke()` was a bare
 * `$operation->model::query()->findOrFail($id)`, so an operation reached rows the resource's own
 * read path could not. Applying that read scope to operations, however, made list admission run
 * before the operation's own declared authority could be asked. The two contracts are independent:
 * reads keep the resource scope, while an operation resolves through the resource's declared
 * population boundary and public identifier, then asks its own `ability` about the resolved subject.
 *
 * The `$model` fallback is pinned just as hard, because it is not vestigial: 13+ operations across
 * `beam-accounts`, `beam-rank` and `beam-market` register against a resource key that is not a
 * registered particle resource at all, and for those the declared model IS the subject class.
 */
class OperationSubjectResolutionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('subject_widgets', function (Blueprint $table): void {
            $table->id();
            $table->string('slug');
            $table->boolean('visible')->default(true);
        });
    }

    // ── The operation's authority is independent of the resource's read scope ───────────────────────

    public function test_an_operation_resolves_a_subject_the_read_scope_excludes_then_applies_its_own_ability(): void
    {
        SubjectWidget::create(['slug' => 'shown', 'visible' => true]);
        SubjectWidget::create(['slug' => 'hidden', 'visible' => false]);

        $this->resource(
            scope: fn (Builder $q) => $q->where('visible', true),
            operationScope: fn (Builder $q) => $q,
        );
        $this->mount($this->op(ability: 'ping'));

        Gate::define('ping', fn (SubjectUser $actor, SubjectWidget $subject): bool => $subject->slug === 'hidden');
        $this->actingAs(new SubjectUser);

        // The read scope would return only row 1. The operation reaches row 2 because its own gate
        // admits it, and refuses row 1 because that same operation gate denies it.
        $this->postJson('/subject-widgets/2/ping')->assertOk()->assertJson(['id' => 2]);
        $this->postJson('/subject-widgets/1/ping')->assertForbidden();
    }

    public function test_an_operation_keeps_the_full_resource_scope_when_no_operation_boundary_is_declared(): void
    {
        SubjectWidget::create(['slug' => 'shown', 'visible' => true]);
        SubjectWidget::create(['slug' => 'hidden', 'visible' => false]);

        $this->resource(scope: fn (Builder $q) => $q->where('visible', true));
        $this->mount($this->op(ability: false));

        $this->postJson('/subject-widgets/1/ping')->assertOk()->assertJson(['id' => 1]);
        $this->postJson('/subject-widgets/2/ping')->assertNotFound();
        $this->postJson('/subject-widgets/999/ping')->assertNotFound();
    }

    public function test_an_operation_boundary_keeps_population_identity_while_skipping_list_admission(): void
    {
        SubjectWidget::create(['slug' => 'tenant-a-shown', 'visible' => true]);
        SubjectWidget::create(['slug' => 'tenant-a-hidden', 'visible' => false]);
        SubjectWidget::create(['slug' => 'tenant-b-hidden', 'visible' => false]);

        $this->resource(
            scope: fn (Builder $q) => $q->where('visible', true)->whereKey([1, 2]),
            operationScope: fn (Builder $q) => $q->whereKey([1, 2]),
        );
        $this->mount($this->op(ability: false));

        $this->postJson('/subject-widgets/2/ping')->assertOk()->assertJson(['id' => 2]);
        $this->postJson('/subject-widgets/3/ping')->assertNotFound();
        $this->postJson('/subject-widgets/999/ping')->assertNotFound();
    }

    public function test_a_subject_blind_permission_cannot_cross_the_operation_boundary(): void
    {
        SubjectWidget::create(['slug' => 'tenant-a', 'visible' => false]);
        SubjectWidget::create(['slug' => 'tenant-b', 'visible' => false]);

        $this->resource(
            scope: fn (Builder $q) => $q->whereKey(1),
            operationScope: fn (Builder $q) => $q->whereKey(1),
        );
        $this->mount($this->op(ability: 'touch-widgets'));

        Gate::before(fn (SubjectUser $actor, string $ability): ?bool => $ability === 'touch-widgets' ? true : null);
        $this->actingAs(new SubjectUser);

        $this->postJson('/subject-widgets/1/ping')->assertOk();
        $this->postJson('/subject-widgets/2/ping')->assertNotFound();
        $this->postJson('/subject-widgets/999/ping')->assertNotFound();
    }

    public function test_a_resource_declaring_no_scope_resolves_exactly_as_before(): void
    {
        SubjectWidget::create(['slug' => 'shown', 'visible' => true]);
        SubjectWidget::create(['slug' => 'hidden', 'visible' => false]);

        $this->resource();
        $this->mount($this->op());

        $this->postJson('/subject-widgets/2/ping')->assertOk()->assertJson(['id' => 2]);
    }

    // ── A declared route key is the op's identifier too ──────────────────────────────────────────────

    public function test_an_operation_resolves_by_the_resources_declared_route_key(): void
    {
        SubjectWidget::create(['slug' => 'blue', 'visible' => true]);

        $this->resource(routeKey: 'slug');
        $this->mount($this->op());

        $this->postJson('/subject-widgets/blue/ping')->assertOk()->assertJson(['id' => 1]);

        // One public identifier per resource, never two: the PK stops resolving, exactly as on the
        // read path.
        $this->postJson('/subject-widgets/1/ping')->assertNotFound();
    }

    // ── The `$model` fallback is load-bearing, not residue ───────────────────────────────────────────

    public function test_an_operation_on_an_unregistered_resource_key_resolves_through_its_declared_model(): void
    {
        SubjectWidget::create(['slug' => 'orphan', 'visible' => false]);

        // No `ParticleResource` registered for this key at all — the live shape of `beam-accounts`'
        // `Sharing::attachTo()`, `beam-rank`'s `Resources::attachTo()`, and `market-products.*`.
        $this->mount($this->op());

        $this->postJson('/subject-widgets/1/ping')->assertOk()->assertJson(['id' => 1]);
    }

    // ── The two subject-less shipped resolvers ───────────────────────────────────────────────────────

    public function test_the_actor_subject_yields_the_acting_principal_and_consumes_no_path_parameters(): void
    {
        $this->resource();
        $this->mount($this->op(subject: ActorSubject::class, handle: fn ($subject) => [
            'class' => $subject === null ? null : $subject::class,
        ]));

        $actor = new SubjectUser;
        $this->actingAs($actor);

        // No coordinate: the mount reads `pathParameters() === []` (particle-operation-surface 20), so
        // the op answers at `subject-widgets/ping` and has no `{id}` coordinate.
        $this->postJson('/subject-widgets/ping')->assertOk()->assertJson(['class' => SubjectUser::class]);
        $this->assertSame([], (new ActorSubject)->pathParameters());
        $this->assertTrue((new ActorSubject)->yieldsSubject());
    }

    public function test_the_no_subject_resolver_yields_null_without_touching_the_database(): void
    {
        // No row exists at all — a collection-level operation must still run.
        $this->resource();
        $this->mount($this->op(subject: NoSubject::class, handle: fn ($subject) => ['null' => $subject === null]));

        // A collection-level op mounts at the collection (particle-operation-surface 20): no `{id}`.
        $this->postJson('/subject-widgets/ping')->assertOk()->assertJson(['null' => true]);
        $this->assertSame([], (new NoSubject)->pathParameters());
        $this->assertFalse((new NoSubject)->yieldsSubject());
    }

    // ── The slot normalises the way `backing:` does ──────────────────────────────────────────────────

    public function test_the_subject_slot_normalises_an_instance_a_class_string_and_null(): void
    {
        $instance = new ActorSubject;

        $this->assertSame($instance, SubjectResolvers::for($this->op(subject: $instance)));
        $this->assertInstanceOf(NoSubject::class, SubjectResolvers::for($this->op(subject: NoSubject::class)));
        $this->assertInstanceOf(RecordSubject::class, SubjectResolvers::for($this->op()));
    }

    public function test_the_record_subject_declares_the_id_path_parameter(): void
    {
        $this->assertSame(['id'], (new RecordSubject)->pathParameters());
        $this->assertTrue((new RecordSubject)->yieldsSubject());
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────────────────

    private function resource(
        ?\Closure $scope = null,
        ?\Closure $operationScope = null,
        ?string $routeKey = null,
        array $includes = [],
    ): void {
        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'subject-widgets',
            backing: SubjectWidget::class,
            includes: $includes,
            scope: $scope,
            operationScope: $operationScope,
            routeKey: $routeKey,
        ));
    }

    private function op(
        mixed $subject = null,
        ?callable $handle = null,
        string|false|null $ability = null,
    ): ParticleOperation {
        return new ParticleOperation(
            resource: 'subject-widgets',
            name: 'ping',
            kind: OperationKind::Write,
            model: SubjectWidget::class,
            handle: $handle === null
                ? fn ($model) => ['id' => $model->getKey()]
                : \Closure::fromCallable($handle),
            ability: $ability,
            subject: $subject,
        );
    }

    private function mount(ParticleOperation $operation): void
    {
        $this->app->make(ParticleOperationRegistry::class)->register($operation);

        Particle::ops('subject-widgets', 'subject-widgets', $operation->name);
    }
}

class SubjectWidget extends Model
{
    protected $table = 'subject_widgets';

    public $timestamps = false;

    protected $guarded = [];
}

class SubjectUser extends User
{
    protected $table = 'users';
}
