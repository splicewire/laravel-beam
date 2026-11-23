<?php

namespace Splicewire\Beam\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Revisions\RevertRefused;

class RecordsRevisionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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
