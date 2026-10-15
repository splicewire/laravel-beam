<?php

namespace Splicewire\Beam\Tests\Webhooks;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Data\HookInputData;
use Splicewire\Beam\Models\Hook;
use Splicewire\Beam\Particle\Backing\EloquentBacking;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\TestCase;
use Splicewire\Beam\Webhooks\HookSubscriptionReach;

/**
 * A subject subscription is a read grant with a push delivery attached, so it may name only a record the
 * actor could READ through the resource the events belong to — the resource's row `scope` included, the
 * same population `show` resolves through (`ResourceRecordLookup::within()`).
 *
 * Measured before the repair (ticket 00 nomination, 2026-10-15 01:09Z): `authorize()` asked only
 * `Gate::authorize('view', $subject)`, so where a model's `view` is wider than its resource's scope —
 * laravel-beam-market's Product declares `view: true`, while `market-listings` scopes to the acting
 * Seller's own rows — a hook writer could subscribe to another Seller's draft listing.
 *
 * **Gate posture: closed.** No `Gate::before`. The probe policy answers `view` true for everyone and
 * `viewAny` false, so every admission below is the scope's answer and every refusal is real.
 */
class HookSubjectScopeReachTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('scope_probe_records', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('owner_id');
            $table->boolean('hidden')->default(false);
        });

        Gate::policy(ScopeProbeRecord::class, ScopeProbePolicy::class);

        $registry = $this->app->make(ParticleResourceRegistry::class);
        $registry->register(new ParticleResource(
            key: 'scoped-probes',
            backing: ScopeProbeRecord::class,
            scope: fn (Builder $query, ?string $realm = null): Builder => $query->where('owner_id', auth()->id()),
        ));
        $registry->register(new ParticleResource(
            key: 'open-probes',
            backing: ScopeProbeRecord::class,
        ));
        $registry->register(new ParticleResource(
            key: 'backed-probes',
            backing: VisibleOnlyProbeBacking::class,
        ));

        $this->actingAs(new ScopeProbeUser);
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            Schema::dropIfExists('scope_probe_records');
        }

        parent::tearDown();
    }

    protected function reach(): HookSubscriptionReach
    {
        return $this->app->make(HookSubscriptionReach::class);
    }

    public function test_a_subject_inside_the_actors_row_scope_is_admitted(): void
    {
        $mine = ScopeProbeRecord::create(['owner_id' => ScopeProbeUser::ID]);

        $this->reach()->authorize(['scoped-probes.persisted'], $mine);

        $this->assertTrue(Gate::allows('view', $mine));
    }

    public function test_a_subject_outside_the_actors_row_scope_is_refused_though_view_allows_it(): void
    {
        $theirs = ScopeProbeRecord::create(['owner_id' => 99]);
        $this->assertTrue(Gate::allows('view', $theirs), 'precondition: the model policy alone would admit it');

        $this->expectException(AuthorizationException::class);

        $this->reach()->authorize(['scoped-probes.persisted'], $theirs);
    }

    public function test_every_named_resource_must_reach_the_subject(): void
    {
        $theirs = ScopeProbeRecord::create(['owner_id' => 99]);

        $this->expectException(AuthorizationException::class);

        $this->reach()->authorize(['open-probes.persisted', 'scoped-probes.persisted'], $theirs);
    }

    public function test_a_resource_without_a_scope_still_admits_on_view_alone(): void
    {
        $theirs = ScopeProbeRecord::create(['owner_id' => 99]);

        $this->reach()->authorize(['open-probes.persisted'], $theirs);

        $this->assertTrue(Gate::allows('view', $theirs));
    }

    public function test_a_subjectless_subscription_is_unchanged_and_still_asks_viewany(): void
    {
        $this->expectException(AuthorizationException::class);

        $this->reach()->authorize(['scoped-probes.persisted'], null);
    }

    /**
     * The population is the resource's queryable BACKING, not `Model::query()`: a model-backed
     * `QueriesRecords` backing may itself narrow the rows (here: never the hidden ones), and `show`
     * starts from that base (`ParticleController::findParticle()`), so the hook check must too.
     */
    public function test_a_subject_the_resource_backing_does_not_serve_is_refused(): void
    {
        $hidden = ScopeProbeRecord::create(['owner_id' => ScopeProbeUser::ID, 'hidden' => true]);

        $this->expectException(AuthorizationException::class);

        $this->reach()->authorize(['backed-probes.persisted'], $hidden);
    }

    public function test_a_subject_the_resource_backing_serves_is_admitted(): void
    {
        $shown = ScopeProbeRecord::create(['owner_id' => ScopeProbeUser::ID]);

        $this->reach()->authorize(['backed-probes.persisted'], $shown);

        $this->assertFalse((bool) $shown->hidden);
    }

    public function test_re_pointing_a_hook_at_a_subject_outside_the_scope_is_refused(): void
    {
        $theirs = ScopeProbeRecord::create(['owner_id' => 99]);
        $hook = new Hook(['events' => ['scoped-probes.persisted']]);

        $this->expectException(AuthorizationException::class);

        $this->reach()->vetWrite($hook, new HookInputData(
            subjectType: ScopeProbeRecord::class,
            subjectId: (string) $theirs->getKey(),
        ));
    }

    public function test_re_pointing_a_hook_at_a_subject_inside_the_scope_is_admitted(): void
    {
        $mine = ScopeProbeRecord::create(['owner_id' => ScopeProbeUser::ID]);
        $hook = new Hook(['events' => ['scoped-probes.persisted']]);

        $this->reach()->vetWrite($hook, new HookInputData(
            subjectType: ScopeProbeRecord::class,
            subjectId: (string) $mine->getKey(),
        ));

        $this->assertSame(ScopeProbeUser::ID, $mine->owner_id);
    }
}

class ScopeProbeUser extends User
{
    public const ID = 7;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    public function getKey()
    {
        return self::ID;
    }

    public function getAuthIdentifier()
    {
        return self::ID;
    }
}

class ScopeProbeRecord extends Model
{
    public $timestamps = false;

    protected $table = 'scope_probe_records';

    protected $guarded = [];
}

/** A model-backed backing whose own query serves only the rows that are not hidden. */
class VisibleOnlyProbeBacking extends EloquentBacking
{
    public function __construct()
    {
        parent::__construct(ScopeProbeRecord::class);
    }

    public function query(array $filters): Builder
    {
        return parent::query($filters)->where('hidden', false);
    }
}

class ScopeProbePolicy
{
    public function view(mixed $user, ScopeProbeRecord $record): bool
    {
        return true;
    }

    public function viewAny(mixed $user): bool
    {
        return false;
    }
}
