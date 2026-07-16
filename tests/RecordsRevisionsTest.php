<?php

namespace Schemastud\Beam\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Schemastud\Beam\Models\SchemaRecord;

class RecordsRevisionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('schema_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('schema_ref')->nullable()->index();
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
        $record = SchemaRecord::create([
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

    public function test_revisions_are_grouped_by_correlation_for_batch_undo(): void
    {
        $record = SchemaRecord::create(['schema_ref' => 'x/1', 'payload' => ['n' => 0]]);

        $record->recordRevision(['payload' => ['n' => 0]], ['payload' => ['n' => 1]], 'refine', 'run-abc');
        $record->recordRevision(['payload' => ['n' => 1]], ['payload' => ['n' => 2]], 'refine', 'run-abc');

        $correlated = collect($record->revisions())
            ->filter(fn ($e) => $e->correlation === 'run-abc');

        $this->assertCount(2, $correlated);
    }
}
