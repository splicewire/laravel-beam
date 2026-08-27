<?php

namespace Splicewire\Beam\Tests\Webhooks;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Data\HookData;
use Splicewire\Beam\Events\EventType;
use Splicewire\Beam\Events\EventTypeRegistry;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Models\Hook;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\TestCase;

/**
 * particle-write-surface ticket 04 — a hook PATCH may not re-point its subject at a record the actor
 * cannot reach.
 *
 * ## The gate posture, stated because a security test without it is worth nothing
 *
 * **The gate is CLOSED here.** No `Gate::before(fn () => true)` — which is precisely what the
 * neighbouring {@see HookHttpSurfaceTest} installs, and precisely the harness that let
 * `api-surface-coherence` 65 record a hole that did not exist while concealing one that did. This
 * class binds two explicit policies instead:
 *
 *   - the Hook row is updatable (so a refusal here is never the write pipeline's `AuthorizeStage`
 *     denying for the wrong reason — {@see test_the_harness_gate_is_genuinely_closed()} proves both
 *     halves), and
 *   - a probe record is `view`-able only when it is flagged reachable, and `viewAny` is denied
 *     outright.
 *
 * Measured 2026-08-27 under exactly this posture: BEFORE the fix, `PUT /hooks/{id}` with a forged
 * `subject_type`/`subject_id` answered **200** and persisted the re-point. The particle update path
 * authorizes the Hook ROW; the reach check that vets a narrowed subscription had exactly one call
 * site, on `POST /hooks`.
 */
class HookSubjectRepointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $migration = require __DIR__.'/../../database/migrations/shared/create_beam_hooks_table.php.stub';
        $migration->up();

        Schema::create('reach_records', function (Blueprint $table): void {
            $table->id();
            $table->boolean('reachable')->default(false);
        });

        $this->actingAs(new ReachUser);

        // GATE CLOSED — explicit policies only. See the class docblock.
        Gate::policy(Hook::class, UpdatableHookPolicy::class);
        Gate::policy(ReachRecord::class, ReachRecordPolicy::class);

        $catalog = $this->app->make(EventTypeRegistry::class);
        $catalog->register(new EventType(name: 'reaches.happened', subjectless: true, description: 'A probe fired.'));
        $catalog->register(new EventType(name: 'reaches.other', subjectless: true, description: 'Another probe fired.'));

        $registry = $this->app->make(ParticleResourceRegistry::class);
        $registry->registerClass(HookData::class);
        // A backing for the `reaches` prefix, so the subjectless branch has a model class to ask
        // `viewAny` against rather than silently skipping it.
        $registry->register(new ParticleResource(
            key: 'reaches',
            backing: ReachRecord::class,
            data: ReachRecordData::class,
            filterable: false,
        ));

        Particle::mount('hooks')->only(['update']);
    }

    private function hook(?ReachRecord $subject = null, array $events = ['reaches.happened']): Hook
    {
        return Hook::create([
            'endpoint' => 'https://receiver.test/hooks',
            'secret' => Hook::mintSecret(),
            'events' => $events,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
        ]);
    }

    // ── The control group: prove the harness can both allow and refuse ──────────────────────────

    public function test_the_harness_gate_is_genuinely_closed(): void
    {
        $user = new ReachUser;
        $mine = ReachRecord::create(['reachable' => true]);
        $theirs = ReachRecord::create(['reachable' => false]);

        $this->assertTrue(Gate::forUser($user)->allows('view', $mine));
        $this->assertFalse(Gate::forUser($user)->allows('view', $theirs));
        $this->assertFalse(Gate::forUser($user)->allows('viewAny', ReachRecord::class));
    }

    public function test_a_hook_the_actor_may_not_update_is_refused_by_the_write_pipeline(): void
    {
        // Not the check under test — the control that says a 403 below is the REACH check and not the
        // row gate refusing everything.
        Gate::policy(Hook::class, UnupdatableHookPolicy::class);

        $hook = $this->hook(ReachRecord::create(['reachable' => true]));

        $this->putJson("/hooks/{$hook->id}", ['endpoint' => 'https://receiver.test/moved'])
            ->assertForbidden();
    }

    public function test_an_ordinary_field_edit_is_untouched_by_the_reach_check(): void
    {
        $hook = $this->hook(ReachRecord::create(['reachable' => true]));

        $this->putJson("/hooks/{$hook->id}", ['endpoint' => 'https://receiver.test/moved'])
            ->assertOk();

        $this->assertSame('https://receiver.test/moved', $hook->refresh()->endpoint);
    }

    // ── The defect ──────────────────────────────────────────────────────────────────────────────

    public function test_a_patch_may_not_repoint_a_hook_at_a_subject_the_actor_cannot_reach(): void
    {
        $mine = ReachRecord::create(['reachable' => true]);
        $theirs = ReachRecord::create(['reachable' => false]);

        $hook = $this->hook($mine);

        $this->putJson("/hooks/{$hook->id}", [
            'subject_type' => $theirs->getMorphClass(),
            'subject_id' => (string) $theirs->getKey(),
        ])->assertForbidden();

        // Refused BEFORE the write — the row still points where it did.
        $this->assertSame((string) $mine->getKey(), (string) $hook->refresh()->subject_id);
    }

    public function test_a_patch_may_repoint_a_hook_at_a_subject_the_actor_can_reach(): void
    {
        $a = ReachRecord::create(['reachable' => true]);
        $b = ReachRecord::create(['reachable' => true]);

        $hook = $this->hook($a);

        $this->putJson("/hooks/{$hook->id}", [
            'subject_type' => $b->getMorphClass(),
            'subject_id' => (string) $b->getKey(),
        ])->assertOk();

        $this->assertSame((string) $b->getKey(), (string) $hook->refresh()->subject_id);
    }

    public function test_a_repoint_at_a_record_that_does_not_exist_is_a_422_not_a_500(): void
    {
        $hook = $this->hook(ReachRecord::create(['reachable' => true]));

        $this->putJson("/hooks/{$hook->id}", [
            'subject_type' => (new ReachRecord)->getMorphClass(),
            'subject_id' => '99999',
        ])->assertStatus(422)->assertJsonValidationErrors('subject_id');
    }

    // ── The same defect in its other spelling: a subjectless hook widening its events ────────────

    public function test_widening_a_subjectless_hooks_events_is_vetted_as_a_new_subscription(): void
    {
        $hook = $this->hook(events: ['reaches.happened']);

        // `viewAny` on the backing is denied, so ADDING a name the actor has no blanket reach to is
        // the subjectless spelling of a re-point.
        $this->putJson("/hooks/{$hook->id}", [
            'events' => ['reaches.happened', 'reaches.other'],
        ])->assertForbidden();

        $this->assertSame(['reaches.happened'], array_values((array) $hook->refresh()->events));
    }

    public function test_an_update_naming_an_event_outside_the_catalog_is_a_422(): void
    {
        $hook = $this->hook(ReachRecord::create(['reachable' => true]));

        $this->putJson("/hooks/{$hook->id}", [
            'events' => ['reaches.happened', 'unicorns.pranced'],
        ])->assertStatus(422)->assertJsonValidationErrors('events');
    }
}

class ReachUser extends User
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    public function getKey()
    {
        return 1;
    }
}

class ReachRecord extends Model
{
    public $timestamps = false;

    protected $table = 'reach_records';

    protected $guarded = [];

    protected $casts = ['reachable' => 'bool'];
}

class ReachRecordData extends Data
{
    public function __construct(public string $id) {}
}

class UpdatableHookPolicy
{
    public function view($user, Hook $hook): bool
    {
        return true;
    }

    public function update($user, Hook $hook): bool
    {
        return true;
    }
}

class UnupdatableHookPolicy
{
    public function view($user, Hook $hook): bool
    {
        return true;
    }

    public function update($user, Hook $hook): bool
    {
        return false;
    }
}

class ReachRecordPolicy
{
    public function view($user, ReachRecord $record): bool
    {
        return $record->reachable;
    }

    public function viewAny($user): bool
    {
        return false;
    }
}
