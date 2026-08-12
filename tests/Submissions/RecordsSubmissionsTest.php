<?php

namespace Splicewire\Beam\Tests\Submissions;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Beam;
use Splicewire\Beam\Events\BeamParticlePersisted;
use Splicewire\Beam\Intake\IntakeProvenance;
use Splicewire\Beam\Models\BeamSubmission;
use Splicewire\Beam\Submissions\RecordsSubmissions;
use Splicewire\Beam\Tests\TestCase;

class RecordsSubmissionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create(Beam::table('submissions'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('form_key')->index();
            $table->string('schema_ref')->nullable();
            $table->string('schema_id')->nullable()->index();
            $table->string('migration_status')->nullable()->index();
            $table->json('payload');
            $table->json('context')->nullable();
            $table->json('meta')->nullable();
            $table->uuid('user_id')->nullable()->index();
            $table->timestamps();
        });
    }

    public function test_persists_a_capture_as_a_beam_submission_through_the_write_pipeline(): void
    {
        $submission = $this->app->make(RecordsSubmissions::class)->record(
            formKey: 'waitlist',
            schemaRef: 'waitlist/1',
            payload: ['email' => 'ada@example.test'],
            context: ['source' => 'marquee-soon'],
        );

        $this->assertInstanceOf(BeamSubmission::class, $submission);
        $this->assertTrue($submission->exists);
        $this->assertSame('waitlist', $submission->form_key);
        $this->assertSame('waitlist/1', $submission->schema_ref);
        $this->assertSame(['email' => 'ada@example.test'], $submission->payload);
        $this->assertSame(['source' => 'marquee-soon'], $submission->context);
    }

    /**
     * No $schema passed at all — the write pipeline's target resolver finds nothing registered
     * for the 'interest' stem, so ValidateStage skips validation entirely regardless of the
     * permissive acceptance gate. Proves the capture flow never rejects at write time.
     */
    public function test_accepts_a_payload_that_does_not_conform_to_any_schema(): void
    {
        $submission = $this->app->make(RecordsSubmissions::class)->record(
            formKey: 'interest',
            schemaRef: null,
            payload: ['name' => 'partial only'],
        );

        $this->assertTrue($submission->exists);
    }

    /**
     * RecordsSubmissions' contract ends at "write + stamp + emit" — whether that then produces an
     * actual notification send is splicewire/laravel-beam-notifications' own tested concern (its
     * NotifyOnSubmission listener reacts to ANY BeamParticlePersisted, not just a BeamSubmission).
     * beam-core does not require beam-notifications, so this only asserts the event contract.
     */
    public function test_stamps_the_caller_resolved_schema_into_meta_and_emits_beam_particle_persisted(): void
    {
        Event::fake([BeamParticlePersisted::class]);

        $schema = [
            'title' => 'Waitlist',
            'type' => 'object',
            'x-beam-notify' => ['to' => ['ops@site.test'], 'channels' => ['mail']],
        ];

        $submission = $this->app->make(RecordsSubmissions::class)->record(
            formKey: 'waitlist',
            schemaRef: 'waitlist/1',
            payload: ['email' => 'ada@example.test'],
            schema: $schema,
        );

        $this->assertSame(['schema' => $schema], $submission->meta);

        Event::assertDispatched(
            BeamParticlePersisted::class,
            fn (BeamParticlePersisted $event): bool => $event->record->is($submission)
                && data_get($event->record, 'meta.schema.x-beam-notify.to') === ['ops@site.test'],
        );
    }

    /**
     * The `$intake` facet set is what NotifyOnSubmission's `submissionContext()` reads
     * (`meta.intake.submitted_at`/`source`/`channel`) — a caller passing it must see it land
     * verbatim under `meta['intake']`, alongside `meta['schema']` when both are given.
     */
    public function test_stamps_intake_provenance_into_meta_alongside_the_schema(): void
    {
        $intake = new IntakeProvenance(
            submittedAt: '2026-08-11T00:00:00+00:00',
            source: 'https://audiostud.test/request-access',
            channel: 'request-access',
            context: ['ip' => '127.0.0.1'],
        );

        $submission = $this->app->make(RecordsSubmissions::class)->record(
            formKey: 'request-access',
            schemaRef: 'request-access',
            payload: ['email' => 'ada@example.test'],
            schema: ['title' => 'Request access'],
            intake: $intake,
        );

        $this->assertSame(['title' => 'Request access'], $submission->meta['schema']);
        $this->assertSame($intake->toArray(), $submission->meta['intake']);
    }

    public function test_stamps_only_intake_into_meta_when_no_schema_is_passed(): void
    {
        $intake = new IntakeProvenance(submittedAt: '2026-08-11T00:00:00+00:00');

        $submission = $this->app->make(RecordsSubmissions::class)->record(
            formKey: 'interest',
            schemaRef: null,
            payload: ['name' => 'Ada'],
            intake: $intake,
        );

        $this->assertSame(['intake' => $intake->toArray()], $submission->meta);
    }

    public function test_emits_beam_particle_persisted_with_no_schema_in_meta_when_none_is_passed(): void
    {
        Event::fake([BeamParticlePersisted::class]);

        $this->app->make(RecordsSubmissions::class)->record(
            formKey: 'interest',
            schemaRef: null,
            payload: ['name' => 'Ada'],
        );

        Event::assertDispatched(
            BeamParticlePersisted::class,
            fn (BeamParticlePersisted $event): bool => data_get($event->record, 'meta.schema') === null,
        );
    }
}
