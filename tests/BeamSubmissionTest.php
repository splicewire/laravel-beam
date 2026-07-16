<?php

namespace Schemastud\Beam\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Schemastud\Beam\Models\BeamSubmission;
use Schemastud\Beam\Models\SchemaRecord;

class BeamSubmissionTest extends TestCase
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

        Schema::create('beam_submissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('schema_record_id')->index();
            $table->uuid('submitted_by')->nullable()->index();
            $table->timestamp('submitted_at')->nullable();
            $table->string('source')->nullable();
            $table->string('channel')->nullable();
            $table->json('context')->nullable();
            $table->timestamps();
        });
    }

    public function test_a_submission_references_a_schema_record_by_composition_not_inheritance(): void
    {
        // The narrow record carries the payload + schema identity as a string ref.
        $record = SchemaRecord::create([
            'schema_ref' => 'waitlist/1',
            'payload' => ['name' => 'Jane', 'email' => 'jane@example.com'],
        ]);

        // The generic reference carries ONLY submission facets, and points AT the record.
        $submission = BeamSubmission::create([
            'schema_record_id' => $record->id,
            'submitted_at' => now(),
            'source' => 'web',
            'channel' => 'form',
            'context' => ['ip' => '203.0.113.7'],
        ]);

        // Composition, NOT inheritance: a submission is not a kind of SchemaRecord.
        $this->assertNotInstanceOf(SchemaRecord::class, $submission);
        $this->assertInstanceOf(BeamSubmission::class, $submission);

        // One submission = one record (payload) + one reference (facets).
        $this->assertTrue($submission->record->is($record));
        $this->assertSame('waitlist/1', $submission->record->schema_ref);
        $this->assertSame(['name' => 'Jane', 'email' => 'jane@example.com'], $submission->record->payload);

        // The generic reference deliberately has NO form_key column — the record bears its schema.
        $this->assertArrayNotHasKey('form_key', $submission->getAttributes());
    }

    public function test_schema_ref_is_a_plain_string_keeping_beam_schema_source_agnostic(): void
    {
        // A form-def key straight from a file registry — no Data class, no SchemaIdentity object.
        $fromFile = SchemaRecord::create(['schema_ref' => 'contact', 'payload' => []]);
        // A name@version derived from a PHP Data class.
        $fromData = SchemaRecord::create(['schema_ref' => 'App\\Data\\LeadData@2', 'payload' => []]);

        $this->assertIsString($fromFile->schema_ref);
        $this->assertIsString($fromData->schema_ref);
        $this->assertSame('contact', $fromFile->schema_ref);
        $this->assertSame('App\\Data\\LeadData@2', $fromData->schema_ref);
    }

    public function test_submitted_by_is_nullable_for_anonymous_public_forms(): void
    {
        $record = SchemaRecord::create(['schema_ref' => 'contact', 'payload' => []]);

        $submission = BeamSubmission::create([
            'schema_record_id' => $record->id,
            'submitted_by' => null,
        ]);

        $this->assertNull($submission->submitted_by);
    }
}
