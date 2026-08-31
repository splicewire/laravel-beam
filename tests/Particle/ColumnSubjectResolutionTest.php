<?php

namespace Splicewire\Beam\Tests\Particle;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Particle\Subject\ColumnSubject;
use Splicewire\Beam\Particle\Subject\SubjectResolvers;
use Splicewire\Beam\Tests\TestCase;

/**
 * `ColumnSubject` — resolve ONE operation's subject by a named column, beside siblings that resolve
 * the same resource by its primary key.
 *
 * The generalised shape of `splicewire/tower`'s `InvitationTokenSubject`, which was the estate's first
 * `ResolvesOperationSubject` outside beam and hardcoded three things: the model, the column, and an
 * extra predicate. Two of the three are parameters here; the third is deliberately NOT — see the
 * class docblock.
 *
 * The case that rules out the obvious alternative is pinned below: `ParticleResource::$routeKey` is
 * resource-WIDE ("one public identifier per resource, never two"), so declaring it would break every
 * sibling verb that addresses the resource by id. The subject slot is per-OPERATION, which is the
 * grain the disagreement actually has.
 */
class ColumnSubjectResolutionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('column_widgets', function (Blueprint $table): void {
            $table->id();
            $table->string('token');
            $table->string('slug');
            $table->boolean('visible')->default(true);
        });
    }

    // ── It resolves by the declared column ──────────────────────────────────────────────────────────

    public function test_it_resolves_the_subject_by_the_declared_column(): void
    {
        ColumnWidget::create(['token' => 'tok-a', 'slug' => 'a']);
        ColumnWidget::create(['token' => 'tok-b', 'slug' => 'b']);

        $this->resource();
        $this->mount($this->op(subject: new ColumnSubject('token')));

        $this->postJson('/column-widgets/tok-b/op/ping')->assertOk()->assertJson(['id' => 2]);
    }

    public function test_a_miss_is_a_clean_404_rather_than_a_null_subject(): void
    {
        ColumnWidget::create(['token' => 'tok-a', 'slug' => 'a']);

        $this->resource();
        $this->mount($this->op(subject: new ColumnSubject('token')));

        $this->postJson('/column-widgets/nope/op/ping')->assertNotFound();
    }

    public function test_the_primary_key_stops_resolving_for_that_operation(): void
    {
        ColumnWidget::create(['token' => 'tok-a', 'slug' => 'a']);

        $this->resource();
        $this->mount($this->op(subject: new ColumnSubject('token')));

        // The column REPLACES the identifier, it does not widen it — otherwise the operation would
        // carry two public identifiers, which is the thing `routeKey` refuses to do.
        $this->postJson('/column-widgets/1/op/ping')->assertNotFound();
    }

    public function test_it_overrides_the_resources_declared_route_key(): void
    {
        ColumnWidget::create(['token' => 'tok-a', 'slug' => 'a']);

        $this->resource(routeKey: 'slug');
        $this->mount($this->op(subject: new ColumnSubject('token')));

        $this->postJson('/column-widgets/tok-a/op/ping')->assertOk()->assertJson(['id' => 1]);
        $this->postJson('/column-widgets/a/op/ping')->assertNotFound();
    }

    // ── The resource scope, and the opt-out the tower case needs ────────────────────────────────────

    public function test_it_honours_the_resources_row_gate_by_default(): void
    {
        ColumnWidget::create(['token' => 'tok-a', 'slug' => 'a', 'visible' => false]);

        $this->resource(scope: fn (Builder $q) => $q->where('visible', true));
        $this->mount($this->op(subject: new ColumnSubject('token')));

        // 404, not a load-then-403: the scoped-out row must never resolve at all.
        $this->postJson('/column-widgets/tok-a/op/ping')->assertNotFound();
    }

    public function test_through_resource_false_resolves_past_the_resources_scope(): void
    {
        ColumnWidget::create(['token' => 'tok-a', 'slug' => 'a', 'visible' => false]);

        // The measured case: an operation mounted OUTSIDE the middleware that makes the resource's
        // scope answerable (tower's invitation `accept` is central, with no tenancy context), where
        // resolving through the resource would 404 every legitimate call.
        $this->resource(scope: fn (Builder $q) => $q->where('visible', true));
        $this->mount($this->op(subject: new ColumnSubject('token', throughResource: false)));

        $this->postJson('/column-widgets/tok-a/op/ping')->assertOk()->assertJson(['id' => 1]);
    }

    public function test_an_unregistered_resource_key_resolves_through_the_declared_model(): void
    {
        ColumnWidget::create(['token' => 'tok-a', 'slug' => 'a']);

        // No `ParticleResource` registered — the `Sharing::attachTo()` / `Resources::attachTo()` shape.
        $this->mount($this->op(subject: new ColumnSubject('token')));

        $this->postJson('/column-widgets/tok-a/op/ping')->assertOk()->assertJson(['id' => 1]);
    }

    // ── The port's declarative half ─────────────────────────────────────────────────────────────────

    public function test_it_declares_the_id_path_parameter_and_yields_a_subject(): void
    {
        $subject = new ColumnSubject('token');

        // The URL segment is still `{id}` — this resolver changes what the id MEANS, not where it
        // comes from, so no mount, reference or codegen has to learn a second parameter name.
        $this->assertSame(['id'], $subject->pathParameters());
        $this->assertTrue($subject->yieldsSubject());
    }

    public function test_the_slot_takes_it_as_an_instance(): void
    {
        $instance = new ColumnSubject('token');

        $this->assertSame($instance, SubjectResolvers::for($this->op(subject: $instance)));
    }

    public function test_a_column_less_construction_says_what_to_spell_instead(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("new ColumnSubject('token')");

        // The class-string path cannot carry a column, so it resolves to this rather than to a
        // resolver that would silently query by `''`.
        new ColumnSubject;
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────────────────

    private function resource(?\Closure $scope = null, ?string $routeKey = null, array $includes = []): void
    {
        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'column-widgets',
            backing: ColumnWidget::class,
            includes: $includes,
            scope: $scope,
            routeKey: $routeKey,
        ));
    }

    private function op(mixed $subject = null): ParticleOperation
    {
        return new ParticleOperation(
            resource: 'column-widgets',
            name: 'ping',
            kind: OperationKind::Write,
            model: ColumnWidget::class,
            handle: fn ($model) => ['id' => $model->getKey()],
            subject: $subject,
        );
    }

    private function mount(ParticleOperation $operation): void
    {
        $this->app->make(ParticleOperationRegistry::class)->register($operation);

        Particle::ops('column-widgets', 'column-widgets', $operation->name);
    }
}

class ColumnWidget extends Model
{
    protected $table = 'column_widgets';

    public $timestamps = false;

    protected $guarded = [];
}
