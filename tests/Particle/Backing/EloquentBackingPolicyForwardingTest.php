<?php

namespace Splicewire\Beam\Tests\Particle\Backing;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Rushing\PermissionCascade\Attributes\UseCascadePolicy;
use Rushing\PermissionCascade\Policies\ConfiguredModelPolicy;
use Rushing\PermissionCascade\Support\CascadePolicyRegistrar;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Particle\Backing\EloquentBacking;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Tests\TestCase;

/**
 * api-surface-coherence 147 (b) — the ticket's premise was that a resource whose `backing:` is an
 * `EloquentBacking` SUBCLASS (`activity` → `ActivityBacking`, `tokens` → `ConfiguredTokenBacking`,
 * `users` → `ConfiguredUserBacking`) "has no policy the cascade can name", because the Frame model is
 * the backing class and `PermissionNamer`/`ConfiguredModelPolicy` are shaped for a `Model`.
 *
 * Measured, that premise is false, and this file pins the reason so the census does not read it wrong
 * a third time. {@see ParticleResource::modelClass()} resolves through
 * {@see \Splicewire\Beam\Particle\Backing\BackingResolver::modelFor()}, which instantiates the backing
 * and asks it {@see \Splicewire\Beam\Particle\Backing\BacksModel::modelClass()} — the CONFIGURED model.
 * `toResourceDefinition()` hands Frame that same class as `$model`. So every reader that asks
 * `Gate::getPolicyFor($resource->modelClass())` or `Gate::getPolicyFor($def->model)` already reaches
 * the configured model's policy: the forwarding the ticket asked for is the existing behaviour, and a
 * policy on the backing class itself would be a declaration with no consumer.
 *
 * What was actually policy-less at the flagship was the configured model behind each backing
 * (`CentralActivityLog`, tower's `PersonalAccessToken`) — a per-model decision, not a backing defect —
 * while `users` resolves to `App\Models\User`, which carries `UserPolicy`, and never belonged in 135's
 * re-census "no policy" bucket at all.
 */
class EloquentBackingPolicyForwardingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('forwarding_widgets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('label')->nullable();
            $table->timestamps();
        });

        CascadePolicyRegistrar::register(ForwardingWidget::class);
    }

    private function resource(): ParticleResource
    {
        return new ParticleResource(key: 'forwarding-widgets', backing: ForwardingBacking::class, data: ForwardingWidgetData::class);
    }

    public function test_control_the_gate_is_closed_and_the_backing_class_itself_has_no_policy(): void
    {
        $this->assertFalse(Gate::forUser(new User)->allows('probe-nonexistent-ability'));
        $this->assertNull(Gate::getPolicyFor(ForwardingBacking::class), 'nothing binds a policy on the backing, and nothing needs to');
    }

    public function test_model_class_resolves_through_the_backing_to_the_configured_model(): void
    {
        $this->assertSame(ForwardingWidget::class, $this->resource()->modelClass());
    }

    public function test_the_frame_definition_carries_the_configured_model_not_the_backing(): void
    {
        $this->assertSame(ForwardingWidget::class, $this->resource()->toResourceDefinition()->model);
    }

    public function test_the_configured_models_policy_is_reachable_through_both_reads(): void
    {
        $resource = $this->resource();

        $this->assertInstanceOf(ConfiguredModelPolicy::class, Gate::getPolicyFor($resource->modelClass()));
        $this->assertInstanceOf(ConfiguredModelPolicy::class, Gate::getPolicyFor($resource->toResourceDefinition()->model));
        $this->assertFalse(Gate::forUser(new User)->allows('viewAny', $resource->modelClass()), 'and it answers — deny, for a token-less actor');
    }
}

#[UseCascadePolicy]
class ForwardingWidget extends Model
{
    use HasUuids;

    protected $table = 'forwarding_widgets';

    protected $guarded = [];
}

/** The `ConfiguredTokenBacking`/`ActivityBacking` shape: an ordinary Eloquent backing whose model is decided at resolve time. */
class ForwardingBacking extends EloquentBacking
{
    public function __construct()
    {
        parent::__construct(ForwardingWidget::class);
    }
}

class ForwardingWidgetData extends BeamData
{
    public function __construct(public string $id, public ?string $label = null) {}
}
