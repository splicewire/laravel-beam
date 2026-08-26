<?php

namespace Splicewire\Beam\Tests\Particle;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
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
 * read path could not. The `scope` closure is ADR-0156 §83's ROW-LEVEL gate — a resolve-by-id that
 * skips it is an authorization hole, not a cosmetic inconsistency, and it was live: the flagship
 * app's `market-extensions.install` sits on a resource scoped `whereVisible()` and declares no
 * ability, so a withdrawn listing was installable while the read path correctly hid it.
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

    // ── The resource's `scope` closure gates the op's subject resolution ─────────────────────────────

    public function test_an_operation_cannot_resolve_a_row_the_resources_scope_excludes(): void
    {
        SubjectWidget::create(['slug' => 'shown', 'visible' => true]);
        SubjectWidget::create(['slug' => 'hidden', 'visible' => false]);

        $this->resource(scope: fn (Builder $q) => $q->where('visible', true));
        $this->mount($this->op());

        // 404, not a load-then-403: the excluded row must never resolve at all.
        $this->postJson('/subject-widgets/2/op/ping')->assertNotFound();
        $this->postJson('/subject-widgets/1/op/ping')->assertOk()->assertJson(['id' => 1]);
    }

    public function test_a_resource_declaring_no_scope_resolves_exactly_as_before(): void
    {
        SubjectWidget::create(['slug' => 'shown', 'visible' => true]);
        SubjectWidget::create(['slug' => 'hidden', 'visible' => false]);

        $this->resource();
        $this->mount($this->op());

        $this->postJson('/subject-widgets/2/op/ping')->assertOk()->assertJson(['id' => 2]);
    }

    // ── A declared route key is the op's identifier too ──────────────────────────────────────────────

    public function test_an_operation_resolves_by_the_resources_declared_route_key(): void
    {
        SubjectWidget::create(['slug' => 'blue', 'visible' => true]);

        $this->resource(routeKey: 'slug');
        $this->mount($this->op());

        $this->postJson('/subject-widgets/blue/op/ping')->assertOk()->assertJson(['id' => 1]);

        // One public identifier per resource, never two: the PK stops resolving, exactly as on the
        // read path.
        $this->postJson('/subject-widgets/1/op/ping')->assertNotFound();
    }

    // ── The `$model` fallback is load-bearing, not residue ───────────────────────────────────────────

    public function test_an_operation_on_an_unregistered_resource_key_resolves_through_its_declared_model(): void
    {
        SubjectWidget::create(['slug' => 'orphan', 'visible' => false]);

        // No `ParticleResource` registered for this key at all — the live shape of `beam-accounts`'
        // `Sharing::attachTo()`, `beam-rank`'s `Resources::attachTo()`, and `market-products.*`.
        $this->mount($this->op());

        $this->postJson('/subject-widgets/1/op/ping')->assertOk()->assertJson(['id' => 1]);
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

        $this->postJson('/subject-widgets/1/op/ping')->assertOk()->assertJson(['class' => SubjectUser::class]);
        $this->assertSame([], (new ActorSubject)->pathParameters());
        $this->assertTrue((new ActorSubject)->yieldsSubject());
    }

    public function test_the_no_subject_resolver_yields_null_without_touching_the_database(): void
    {
        // No row exists at all — a collection-level operation must still run.
        $this->resource();
        $this->mount($this->op(subject: NoSubject::class, handle: fn ($subject) => ['null' => $subject === null]));

        $this->postJson('/subject-widgets/999/op/ping')->assertOk()->assertJson(['null' => true]);
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

    private function resource(?\Closure $scope = null, ?string $routeKey = null, array $includes = []): void
    {
        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'subject-widgets',
            backing: SubjectWidget::class,
            includes: $includes,
            scope: $scope,
            routeKey: $routeKey,
        ));
    }

    private function op(mixed $subject = null, ?callable $handle = null): ParticleOperation
    {
        return new ParticleOperation(
            resource: 'subject-widgets',
            name: 'ping',
            kind: OperationKind::Write,
            model: SubjectWidget::class,
            handle: $handle === null
                ? fn ($model) => ['id' => $model->getKey()]
                : \Closure::fromCallable($handle),
            subject: $subject,
        );
    }

    private function mount(ParticleOperation $operation): void
    {
        $this->app->make(ParticleOperationRegistry::class)->register($operation);

        Route::particleOp('subject-widgets', 'subject-widgets', $operation->name);
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
