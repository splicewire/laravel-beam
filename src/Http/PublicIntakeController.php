<?php

namespace Splicewire\Beam\Http;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Schemastud\DataSchemas\Migration\AcceptanceGate;
use Splicewire\Beam\Concerns\PersistsBeamParticle;
use Splicewire\Beam\Http\Middleware\HoneypotMiddleware;
use Splicewire\Beam\Intake\IntakeProvenance;
use Splicewire\Beam\Intake\PublicIntakeWriteGate;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Models\BeamSubmission;
use Splicewire\Beam\Schema\Contracts\SchemaTargetResolver;
use Splicewire\Beam\Schema\SchemaId;
use Splicewire\Beam\Submissions\RecordsSubmissions;
use Splicewire\Beam\Validation\SchemaIntakeValidator;
use Splicewire\Beam\Write\ParticleWriter;
use Splicewire\Beam\Write\WriteNotAuthorized;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The optional generic public intake door (beam-write-pipeline ticket 04): a host accepts anonymous
 * form submissions with NO controller of its own by mounting this route. It generalizes the dissolved
 * submissions package's `POST /schema-forms/{form}` door DOWN into beam-core, riding {@see ParticleWriter}.
 *
 * Order is deliberately deny-first: resolve the form schema (404 unknown) → authorize the schema through
 * the permissive-but-allow-listed {@see PublicIntakeWriteGate} (403 if not marked public — the write is
 * refused before its payload is even validated) → format-validate the payload (422 with per-field errors)
 * → persist a {@see BeamSubmission} carrying {@see IntakeProvenance} facets through the pipeline (which
 * re-authorizes, boolean-validates, and emits `BeamParticlePersisted`). The honeypot short-circuit, when
 * enabled, is handled upstream by {@see HoneypotMiddleware}.
 *
 * THE DOOR WRITES A SUBMISSION, not a bare particle (beam-facade ticket 51). It used to write a
 * {@see BeamParticle}, which is the populator-AGNOSTIC skeleton — and a public form is as plainly
 * populated as a flow gets. The estate's precedent is that every populator keeps its own table
 * composing {@see PersistsBeamParticle} ({@see BeamSubmission}, `Thread`, `Message`, `BeamUxEntry`,
 * `Clip`, `Project`, `Track`, `SitemapRecord`), so `beam_particles` and `beam_submissions` are
 * SIBLING tables — there is no pivot and no parent/child relation between them. Writing the
 * submission model is also what puts this door and {@see RecordsSubmissions} on the same store,
 * which is the whole point of converging a host's hand-rolled intake onto it (ticket 41).
 *
 * `form_key` is the route's slug — the unversioned intake identity a host groups captures by, and
 * what the submitter actually addressed — while `schema_ref` carries the resolved, versioned `$id`.
 *
 * It stamps `meta['intake']` and NOT `meta['schema']`, deliberately. The snapshot tier exists for a
 * record with no `schema_ref` at all; every record this door writes carries one, so under ticket 47's
 * rule its snapshot is unreachable BY CONSTRUCTION — stamping one would write a decoy nothing reads.
 * `x-beam-notify` reaches a capture from here through the registry, off the `schema_ref`.
 */
class PublicIntakeController
{
    public function __construct(
        private SchemaTargetResolver $targets,
        private SchemaIntakeValidator $validator,
        private AcceptanceGate $acceptance,
        private Dispatcher $events,
    ) {}

    public function __invoke(Request $request, string $form): JsonResponse
    {
        // Map the URL-safe form slug to its schema stem (or accept a resolvable stem passed directly),
        // then resolve the target schema through beam's registry (filesystem tier). Unknown ⇒ 404.
        $forms = (array) config('beam.core.intake.forms', []);
        $stem = isset($forms[$form]) && $forms[$form] !== '' ? (string) $forms[$form] : $form;
        $targetSchema = $this->targets->targetFor($stem);
        if ($targetSchema === []) {
            throw new NotFoundHttpException("No public intake schema for [{$form}].");
        }

        // Strip the honeypot field so it never reaches the payload (defence works with the middleware
        // off too — a stray honeypot value is simply not persisted).
        $payload = $request->except([(string) config('beam.core.intake.honeypot.field', 'website')]);

        $gate = new PublicIntakeWriteGate((array) config('beam.core.intake.public_schemas', []));
        $actor = $request->user();

        // Deny-first: a schema not on the public allow-list is refused BEFORE its payload is validated.
        if (! $gate->authorizes($stem, $payload, $actor)) {
            throw WriteNotAuthorized::for($stem);
        }

        // Formatted per-field validation → 422, the human-facing door path (distinct from the pipeline's
        // boolean acceptance gate).
        $errors = $this->validator->validate($payload, $targetSchema);
        if ($errors !== []) {
            throw new HttpResponseException(new JsonResponse([
                'message' => "The submission for [{$form}] is invalid.",
                'errors' => $errors,
            ], Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        // `form_key` is the ROUTE's slug, not the resolved stem: it is the unversioned intake identity
        // a host groups captures by, and the slug is what the submitter actually addressed.
        $record = new BeamSubmission([
            'form_key' => $form,
            'schema_ref' => $this->schemaRef($stem, $targetSchema),
        ]);
        $record->meta = ['intake' => $this->provenance($request, $actor)->toArray()];

        $writer = new ParticleWriter($gate, $this->targets, $this->acceptance, $this->events);
        $writer->write($record, $payload, $actor);

        return new JsonResponse(['id' => $record->getKey(), 'schemaRef' => $record->schema_ref], 201);
    }

    /** Prefer the schema's own absolute `$id` as the binding; fall back to the route ref. */
    private function schemaRef(string $ref, array $schema): string
    {
        $id = $schema['$id'] ?? null;

        return is_string($id) && $id !== '' ? $id : SchemaId::from($ref)->recordType();
    }

    private function provenance(Request $request, mixed $actor): IntakeProvenance
    {
        return new IntakeProvenance(
            submittedAt: Carbon::now()->toIso8601String(),
            submittedBy: $actor?->getAuthIdentifier() !== null ? (string) $actor->getAuthIdentifier() : null,
            source: $request->fullUrl(),
            channel: 'public-intake',
            context: array_filter([
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ], static fn ($value) => $value !== null),
        );
    }
}
