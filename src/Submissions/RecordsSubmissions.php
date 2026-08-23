<?php

namespace Splicewire\Beam\Submissions;

use Illuminate\Contracts\Events\Dispatcher;
use Splicewire\Beam\Events\BeamParticlePersisted;
use Splicewire\Beam\Intake\IntakeProvenance;
use Splicewire\Beam\Models\BeamSubmission;
use Splicewire\Beam\Notifications\Listeners\NotifyOnSubmission;
use Splicewire\Beam\Schema\Contracts\SchemaTargetResolver;
use Splicewire\Beam\Write\ParticleWriter;
use Splicewire\Beam\Write\PermissiveAcceptanceGate;
use Splicewire\Beam\Write\PermissiveWriteGate;

/**
 * The generic capture store for a public-facing form (waitlist signup, contact/interest form,
 * anonymous intake) — a form key + a schema-shaped payload becomes exactly one
 * {@see BeamSubmission} (a beam BeamParticle), persisted through beam-core's single write
 * pipeline ({@see ParticleWriter}) and announced on {@see BeamParticlePersisted} — the one signal
 * every beam write path emits. A host that also composes `splicewire/laravel-beam-notifications`
 * gets a free notification for any form whose schema declares `x-beam-notify` (its
 * `NotifyOnSubmission` listener reacts to this same event, reading the schema this class stamps
 * into `meta.schema` below); this class carries no dependency on that package itself.
 *
 * Lives in beam-core, beside {@see BeamSubmission} and the write gates it depends on — not
 * beam-notifications, beam-accounts, or a satellite-tier package: nothing about it is
 * satellite-specific or account-specific, and every beam host already requires this package.
 *
 * Deliberately does NOT resolve the form's schema itself — the caller (typically a controller
 * that already validated the payload against its own copy of the schema for human-facing 422
 * errors) passes the resolved schema document straight through for notify-stamping. This keeps
 * the class free of any opinion about WHERE a host's form schemas live (a JSON file, a DB table,
 * a registry) — that is entirely the caller's concern.
 *
 * The write rides {@see PermissiveWriteGate} (authorization) and {@see PermissiveAcceptanceGate}
 * (schema conformance): this is a server-trusted CAPTURE flow whose caller has already authorized
 * and validated as it sees fit, and a submission is capture — partial/not-yet-conforming payloads
 * must land, not be rejected at write. beam's deny-by-default + validate-on-write defaults are
 * untouched for every other write path; this flow opts out explicitly here.
 */
class RecordsSubmissions
{
    public function __construct(
        private SchemaTargetResolver $targets,
        private Dispatcher $events,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>|null  $schema  the caller's already-resolved form schema
     *                                             document, stamped into `meta['schema']` so a
     *                                             beam-notifications host's snapshot schema
     *                                             resolver can read its `x-beam-notify` keyword.
     *                                             Null (no registered schema for the form) means
     *                                             no notification.
     * @param  IntakeProvenance|null  $intake  optional who/when/where facets, stamped into
     *                                         `meta['intake']` — the shape
     *                                         {@see NotifyOnSubmission}'s
     *                                         `submissionContext()` reads for a notification's
     *                                         `submitted_at`/`source`/`channel`. Omit only when the
     *                                         caller has no request-side provenance to offer.
     */
    public function record(
        string $captureKey,
        ?string $schemaRef,
        array $payload,
        array $context = [],
        ?string $userId = null,
        ?array $schema = null,
        ?IntakeProvenance $intake = null,
    ): BeamSubmission {
        $submission = new BeamSubmission([
            'capture_key' => $captureKey,
            'schema_ref' => $schemaRef,
            'context' => $context,
            'user_id' => $userId,
        ]);

        $meta = array_filter([
            'schema' => $schema,
            'intake' => $intake?->toArray(),
        ], static fn ($value) => $value !== null);

        if ($meta !== []) {
            $submission->setAttribute('meta', $meta);
        }

        $writer = new ParticleWriter(
            new PermissiveWriteGate,
            $this->targets,
            new PermissiveAcceptanceGate,
            $this->events,
        );

        /** @var BeamSubmission $written */
        $written = $writer->write($submission, $payload, null);

        return $written;
    }
}
