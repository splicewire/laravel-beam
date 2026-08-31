<?php

namespace Splicewire\Beam\Tests\Particle;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Particle\Subject\ColumnSubject;
use Splicewire\Beam\Particle\Subject\OperationSubjectModel;
use Splicewire\Beam\Tests\TestCase;

/**
 * `OperationSubjectModel` — the one read of *"which model does this operation's `{id}` resolve
 * against"*, and the retirement of {@see ParticleOperation::$model} it makes possible.
 *
 * particle-operation-surface ticket 18. The op names a `resource:`, the resource names its `backing:`,
 * and the model is a fact about the backing — so an op that states it again states the least
 * interesting thing about itself, and states it where nothing checks the two agree.
 *
 * The tests that matter are the two request-path ones: an operation declaring **no `model:` at all**
 * resolves its subject, through both subject resolvers, purely from its resource. That is the property
 * the 99 declaration sites are being migrated against; everything else here is the seam's own grammar.
 */
class OperationSubjectModelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('subject_model_widgets', function (Blueprint $table): void {
            $table->id();
            $table->string('token');
            $table->boolean('visible')->default(true);
        });
    }

    // ── The read itself ─────────────────────────────────────────────────────────────────────────────

    public function test_it_reads_the_model_off_the_resources_backing(): void
    {
        $this->resource();

        $this->assertSame(SubjectModelWidget::class, $this->models()->for($this->op()));
    }

    public function test_the_resources_backing_wins_over_a_declared_model(): void
    {
        // A disagreement between the two is the duplication defect the ticket exists to remove, and
        // the resource is the side that owns the fact — so it is not a coin toss which one answers.
        $this->resource();

        $this->assertSame(
            SubjectModelWidget::class,
            $this->models()->for($this->op(model: OtherSubjectModelWidget::class)),
        );
    }

    public function test_an_unregistered_resource_falls_back_to_the_declared_model(): void
    {
        // No resource registered. This is what keeps the estate green while the 99 declaration sites
        // migrate one repo at a time, and it is the ONLY thing `$model` is still for.
        $this->assertSame(
            OtherSubjectModelWidget::class,
            $this->models()->for($this->op(model: OtherSubjectModelWidget::class)),
        );
    }

    public function test_unresolvable_is_a_null_and_not_a_throw(): void
    {
        // Doctor-time callers treat this as their own blind spot rather than the host's defect, so the
        // class refuses to decide for them — `ParticleIdConstraintKeyTypeAudit` already skips on null.
        $this->assertNull($this->models()->for($this->op()));
    }

    public function test_require_names_the_declaration_that_is_missing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('subject-model-widgets');

        $this->models()->require($this->op());
    }

    // ── The request path, with NO `model:` declared ────────────────────────────────────────────────

    public function test_a_record_subject_resolves_with_no_declared_model(): void
    {
        SubjectModelWidget::create(['token' => 'tok-a']);

        $this->resource();
        $this->mount($this->op());

        $this->postJson('/subject-model-widgets/1/op/ping')->assertOk()->assertJson(['id' => 1]);
    }

    public function test_a_column_subject_resolves_past_the_scope_with_no_declared_model(): void
    {
        SubjectModelWidget::create(['token' => 'tok-a', 'visible' => false]);

        // `throughResource: false` is the opt-out for a mount where the resource's scope is not
        // answerable (tower's central invitation `accept`). It used to be the one path that REQUIRED
        // `$model`; it now takes the model off the same backing while still skipping the row gate.
        $this->resource(scope: fn (Builder $q) => $q->where('visible', true));
        $this->mount($this->op(subject: new ColumnSubject('token', throughResource: false)));

        $this->postJson('/subject-model-widgets/tok-a/op/ping')->assertOk()->assertJson(['id' => 1]);
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────────────────

    private function models(): OperationSubjectModel
    {
        return new OperationSubjectModel($this->app->make(ParticleResourceRegistry::class));
    }

    private function resource(?\Closure $scope = null): void
    {
        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'subject-model-widgets',
            backing: SubjectModelWidget::class,
            scope: $scope,
        ));
    }

    private function op(?string $model = null, mixed $subject = null): ParticleOperation
    {
        return new ParticleOperation(
            resource: 'subject-model-widgets',
            name: 'ping',
            kind: OperationKind::Write,
            handle: fn ($record) => ['id' => $record->getKey()],
            subject: $subject,
            model: $model,
        );
    }

    private function mount(ParticleOperation $operation): void
    {
        $this->app->make(ParticleOperationRegistry::class)->register($operation);

        Particle::ops('subject-model-widgets', 'subject-model-widgets', $operation->name);
    }
}

class SubjectModelWidget extends Model
{
    protected $table = 'subject_model_widgets';

    public $timestamps = false;

    protected $guarded = [];
}

class OtherSubjectModelWidget extends Model
{
    protected $table = 'subject_model_widgets';

    public $timestamps = false;

    protected $guarded = [];
}
