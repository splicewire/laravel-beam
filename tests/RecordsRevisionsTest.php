<?php

namespace Splicewire\Beam\Tests;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Revisions\RevertRefused;
use Splicewire\Beam\Tests\Fixtures\Revisions\NullSaveReturningParticle;
use Splicewire\Beam\Tests\Fixtures\Revisions\OwnerOverrideRestoreParticle;
use Splicewire\Beam\Tests\Fixtures\Revisions\SelfSavingOverrideRestoreParticle;

class RecordsRevisionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Each revision-fixture subclass gets its OWN durable morph alias, registered to its class, so
        // RevisionRecorder::resolveSubject() rebuilds the SUBCLASS on revert (via getMorphedModel) — not
        // the base BeamParticle the inherited `beam_particle` alias would otherwise resolve, which would
        // bypass each subclass's override and make the override/null-save laws prove nothing.
        Relation::morphMap([
            'owner_override_particle' => OwnerOverrideRestoreParticle::class,
            'null_save_particle' => NullSaveReturningParticle::class,
            'self_saving_override_particle' => SelfSavingOverrideRestoreParticle::class,
        ]);

        Schema::create(Beam::table('particles'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('schema_ref')->nullable()->index();
            $table->string('schema_id')->nullable()->index();
            $table->string('migration_status')->nullable()->index();
            $table->string('head_version')->nullable();
            $table->json('payload')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        // activitylog with uuid morphs — beam records use uuid7 keys.
        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->nullableUuidMorphs('subject', 'subject');
            $table->string('event')->nullable();
            $table->nullableUuidMorphs('causer', 'causer');
            $table->json('attribute_changes')->nullable();
            $table->json('properties')->nullable();
            $table->timestamps();
        });
    }

    public function test_a_schema_record_records_and_reverts_a_revision(): void
    {
        $record = BeamParticle::create([
            'schema_ref' => 'x/1',
            'payload' => ['body' => 'original'],
        ]);

        $old = ['payload' => $record->payload];
        $record->payload = ['body' => 'edited'];
        $record->save();

        $entry = $record->recordRevision($old, ['payload' => $record->payload], 'edit');

        // History exposes the revision as a typed entry.
        $history = $record->revisions();
        $this->assertNotEmpty($history);
        $this->assertSame('edit', $history[0]->cause);

        // Revert restores the pre-image (and records the reversal, append-only).
        $reverted = $record->revertTo($entry);
        $this->assertSame(['body' => 'original'], $reverted->fresh()->payload);

        $causes = collect($record->fresh()->revisions())->pluck('cause')->all();
        $this->assertContains('revert', $causes);
    }

    public function test_a_vetoed_revert_records_no_activity_and_leaves_the_subject_unchanged(): void
    {
        $record = BeamParticle::create([
            'schema_ref' => 'x/1',
            'payload' => ['body' => 'original'],
        ]);

        $old = ['payload' => $record->payload];
        $record->payload = ['body' => 'edited'];
        $record->save();

        $entry = $record->recordRevision($old, ['payload' => $record->payload], 'edit');
        $revisionsBefore = count($record->fresh()->revisions());

        // A halting `saving` listener refuses the write the revert attempts, so `save()` returns
        // false and the pre-image never lands — the real-world guard/policy/tenancy veto path.
        BeamParticle::saving(fn () => false);

        try {
            $record->revertTo($entry);
            $this->fail('revertTo should throw RevertRefused when the restoring write is refused.');
        } catch (RevertRefused $e) {
            // expected — the refusal is loud and mappable, not a swallowed boolean.
            // The subject rides on properties so a host can key its own user-facing message...
            $this->assertSame($record->getMorphClass(), $e->subjectType);
            $this->assertSame($record->getKey(), $e->subjectKey);
            // ...and the message stays generic: no internal `[type:id]` identity leaks through it.
            $this->assertStringNotContainsString($record->getMorphClass(), $e->getMessage());
            $this->assertStringNotContainsString((string) $record->getKey(), $e->getMessage());
        }

        // The subject is untouched: the edited value still stands, the pre-image was not applied.
        $this->assertSame(['body' => 'edited'], $record->fresh()->payload);

        // The append-only log did NOT gain a `revert` entry for a write that never stored.
        $this->assertSame($revisionsBefore, count($record->fresh()->revisions()));
        $causes = collect($record->fresh()->revisions())->pluck('cause')->all();
        $this->assertNotContains('revert', $causes);
    }

    public function test_a_noop_vetoed_revert_through_the_plain_branch_is_refused_not_recorded(): void
    {
        $record = BeamParticle::create([
            'schema_ref' => 'x/1',
            'payload' => ['body' => 'original'],
        ]);

        // A pre-image that already equals the live state: the revert's forceFill() changes nothing,
        // so the model never goes dirty. isDirty() then cannot tell a STORED write from a vetoed one —
        // the regression this guards. (No owner/workflow meta, so the plain `forceFill()` branch runs.)
        $entry = $record->recordRevision(
            ['payload' => ['body' => 'original']],
            ['payload' => ['body' => 'original']],
            'edit',
        );
        $revisionsBefore = count($record->fresh()->revisions());

        // A halting `saving` listener refuses the write: save() returns false WITHOUT persisting.
        BeamParticle::saving(fn () => false);

        try {
            $record->revertTo($entry);
            $this->fail('a no-op revert whose write is vetoed must still throw RevertRefused.');
        } catch (RevertRefused $e) {
            $this->assertSame($record->getMorphClass(), $e->subjectType);
            $this->assertSame($record->getKey(), $e->subjectKey);
        }

        // The append-only log did NOT gain a `revert` entry for a write that never stored — the whole
        // point of the fix: under isDirty() this clean-but-vetoed revert was recorded as if it landed.
        $this->assertSame($revisionsBefore, count($record->fresh()->revisions()));
        $this->assertNotContains('revert', collect($record->fresh()->revisions())->pluck('cause')->all());
    }

    public function test_a_noop_vetoed_revert_through_the_owner_branch_is_refused_not_recorded(): void
    {
        $record = BeamParticle::create([
            'schema_ref' => 'thing/1',
            'payload' => ['body' => 'original'],
            'meta' => ['owner' => ['id' => 'u1']],
        ]);

        // Owner meta routes the revert through BeamParticle::restoreVersionSnapshot() + its outcome
        // slot; the pre-image equals the live payload, so that restore is a no-op and the model stays
        // clean whether the write stores or a veto refuses it — isDirty() is blind here too.
        $entry = $record->recordRevision(
            ['payload' => ['body' => 'original']],
            ['payload' => ['body' => 'original']],
            'edit',
        );
        $revisionsBefore = count($record->fresh()->revisions());

        BeamParticle::saving(fn () => false);

        try {
            $record->revertTo($entry);
            $this->fail('a no-op revert through the owner branch whose write is vetoed must throw RevertRefused.');
        } catch (RevertRefused $e) {
            $this->assertSame($record->getMorphClass(), $e->subjectType);
            $this->assertSame($record->getKey(), $e->subjectKey);
        }

        // Still no `revert` recorded, and the owned record keeps its original payload and owner meta.
        $this->assertSame($revisionsBefore, count($record->fresh()->revisions()));
        $this->assertNotContains('revert', collect($record->fresh()->revisions())->pluck('cause')->all());
        $this->assertSame(['body' => 'original'], $record->fresh()->payload);
        $this->assertSame(['id' => 'u1'], $record->fresh()->meta['owner']);
    }

    public function test_a_subclass_restore_override_runs_on_revert_through_the_owner_branch(): void
    {
        OwnerOverrideRestoreParticle::$overrideCalls = 0;

        // Owner meta routes revert through the owner branch. A BeamParticle subclass that overrides
        // restoreVersionSnapshot() must have THAT override honoured on revert — the recorder dispatches
        // through the overridable method, not a private worker that would silently skip it.
        $record = OwnerOverrideRestoreParticle::create([
            'schema_ref' => 'thing/1',
            'payload' => ['body' => 'original'],
            'meta' => ['owner' => ['id' => 'u1']],
        ]);

        $old = ['payload' => ['body' => 'original']];
        $record->payload = ['body' => 'edited'];
        $record->save();

        $entry = $record->recordRevision($old, ['payload' => $record->payload], 'edit');

        $record->revertTo($entry);

        // The subclass's override RAN on revert (pre-fix: the owner branch called a private worker
        // directly, so a subclass override of restoreVersionSnapshot() was never reached).
        $this->assertSame(1, OwnerOverrideRestoreParticle::$overrideCalls);

        // ...and the revert still landed and was recorded (dispatching through the override did not
        // lose the store outcome the recorder keys on).
        $this->assertSame(['body' => 'original'], $record->fresh()->payload);
        $this->assertContains('revert', collect($record->fresh()->revisions())->pluck('cause')->all());
    }

    public function test_a_revert_whose_save_override_returns_null_is_treated_as_stored_not_refused(): void
    {
        // No owner/workflow meta, so the plain `forceFill()->save()` branch runs. This subclass'
        // save() PERSISTS but reports null (a host-override shape; Eloquent's save() is untyped). A
        // null result is not a veto — the write stored — so the revert must be recorded, not refused.
        $record = NullSaveReturningParticle::create([
            'schema_ref' => 'x/1',
            'payload' => ['body' => 'original'],
        ]);

        $old = ['payload' => ['body' => 'original']];
        $record->payload = ['body' => 'edited'];
        $record->save();

        $entry = $record->recordRevision($old, ['payload' => $record->payload], 'edit');

        // Pre-fix the `! $saved` predicate treated null as refused and threw RevertRefused on a write
        // that had in fact landed; the fix keys refusal on a strict `=== false`, so this does not throw.
        $record->revertTo($entry);

        $this->assertSame(['body' => 'original'], $record->fresh()->payload);
        $this->assertContains('revert', collect($record->fresh()->revisions())->pluck('cause')->all());
    }

    public function test_a_self_persisting_override_that_reports_a_vetoed_save_is_refused_not_recorded(): void
    {
        // An override that owns its OWN write (never calls parent) must report its save() result through
        // recordRestoreOutcome(); a reported `false` is a refusal. Owner meta routes the revert through
        // the owner branch, which clears the slot, dispatches through the override, then reads it. The
        // pre-image equals the live payload, so the restore is a no-op and the model stays clean —
        // isDirty() is blind here, and ONLY the reported false tells the vetoed write from a stored one.
        $record = SelfSavingOverrideRestoreParticle::create([
            'schema_ref' => 'thing/1',
            'payload' => ['body' => 'original'],
            'meta' => ['owner' => ['id' => 'u1']],
        ]);

        $entry = $record->recordRevision(
            ['payload' => ['body' => 'original']],
            ['payload' => ['body' => 'original']],
            'edit',
        );
        $revisionsBefore = count($record->fresh()->revisions());

        // The override's own save() is vetoed; keyed on the CONCRETE class, since Eloquent model events
        // fire per concrete class — a listener on the base would not reach this subclass's save().
        SelfSavingOverrideRestoreParticle::saving(fn () => false);

        try {
            $record->revertTo($entry);
            $this->fail('a self-saving override that reports a vetoed save must throw RevertRefused.');
        } catch (RevertRefused $e) {
            // The reported `false` surfaced as a loud refusal, carried through recordRestoreOutcome()
            // from an override that never touched parent.
            $this->assertSame($record->getMorphClass(), $e->subjectType);
            $this->assertSame($record->getKey(), $e->subjectKey);
        }

        // No `revert` recorded for a write the override reported refused, and no new revision at all.
        $this->assertSame($revisionsBefore, count($record->fresh()->revisions()));
        $this->assertNotContains('revert', collect($record->fresh()->revisions())->pluck('cause')->all());
    }

    public function test_revisions_are_grouped_by_correlation_for_batch_undo(): void
    {
        $record = BeamParticle::create(['schema_ref' => 'x/1', 'payload' => ['n' => 0]]);

        $record->recordRevision(['payload' => ['n' => 0]], ['payload' => ['n' => 1]], 'refine', 'run-abc');
        $record->recordRevision(['payload' => ['n' => 1]], ['payload' => ['n' => 2]], 'refine', 'run-abc');

        $correlated = collect($record->revisions())
            ->filter(fn ($e) => $e->correlation === 'run-abc');

        $this->assertCount(2, $correlated);
    }
}
