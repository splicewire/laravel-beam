<?php

namespace Splicewire\Beam\Http;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Rushing\LaravelDataSchemasScribe\Attributes\RequestFromData;
use Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData;
use Schemastud\DataSchemas\Migration\AcceptanceGate;
use Splicewire\Beam\Concerns\PersistsBeamParticle;
use Splicewire\Beam\Http\Middleware\HoneypotMiddleware;
use Splicewire\Beam\Intake\Data\PublicIntakeAcceptedData;
use Splicewire\Beam\Intake\Data\PublicIntakeErrorData;
use Splicewire\Beam\Intake\Data\PublicIntakeSubmissionData;
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
 * intake submissions with NO controller of its own by mounting this route. It generalizes the dissolved
 * submissions package's `POST /schema-forms/{form}` door DOWN into beam-core, riding {@see ParticleWriter}.
 *
 * Order is deliberately deny-first: resolve the target schema (404 unknown) → authorize the schema through
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
 * `capture_key` is the route's `{schema}` slug — the unversioned intake identity a host groups captures
 * by, and what the submitter actually addressed — while `schema_ref` carries the resolved, versioned `$id`.
 *
 * It stamps `meta['intake']` and NOT `meta['schema']`, deliberately. The snapshot tier exists for a
 * record with no `schema_ref` at all; every record this door writes carries one, so under ticket 47's
 * rule its snapshot is unreachable BY CONSTRUCTION — stamping one would write a decoy nothing reads.
 * `x-beam-notify` reaches a capture from here through the registry, off the `schema_ref`.
 *
 * THE DOOR IS DECLARED AT THE THIRD SITE, not as a particle (beam-facade tickets 89 and 124). Particle
 * doctrine's invariant is that every boundary-crossing shape is a declared Data class at one of three
 * legal declaration sites — and this surface reaches for the third,
 * `#[RequestFromData]`/`#[ResponseFromData]`, on purpose. It cannot be a `#[ParticleOp]`: every op
 * mounts at `{resource}/{id}/{name}` and binds an existing record, while intake CREATES one; no op
 * in the estate is mounted outside `auth:sanctum`; and decisively, an op's `input:` is a PHP
 * class-string resolved at boot, where this door's input is a JSON Schema resolved per request from a
 * slug. Requiring a compile-time Data class per intake surface would REVERSE the capability stated two
 * paragraphs up — a host mounting an intake surface with no controller and no class of its own.
 */
class PublicIntakeController
{
    public function __construct(
        private SchemaTargetResolver $targets,
        private SchemaIntakeValidator $validator,
        private AcceptanceGate $acceptance,
        private Dispatcher $events,
    ) {}

    /**
     * Submit an anonymous intake document
     *
     * The request body is the document itself, governed by the JSON Schema the `{schema}` slug
     * resolves to — see {@see PublicIntakeSubmissionData} for why that shape is declared open rather
     * than fixed. 201 returns the written capture's key; 422 returns a JSON-pointer-keyed violation
     * map; 403 and 404 render the framework's own `{message}` body.
     */
    #[RequestFromData(PublicIntakeSubmissionData::class, description: 'The intake document, shaped by the registered JSON Schema for this route\'s {schema} slug rather than by a fixed class.')]
    #[ResponseFromData(PublicIntakeAcceptedData::class, status: 201, description: 'The capture was written.')]
    #[ResponseFromData(PublicIntakeErrorData::class, status: 422, description: 'The document did not validate against its schema.')]
    public function __invoke(Request $request, string $schema): JsonResponse
    {
        // Map the URL-safe schema slug to its schema stem (or accept a resolvable stem passed directly),
        // then resolve the target schema through beam's registry (filesystem tier). Unknown ⇒ 404.
        $slugs = (array) config('beam.core.intake.slugs', []);
        $stem = isset($slugs[$schema]) && $slugs[$schema] !== '' ? (string) $slugs[$schema] : $schema;
        $targetSchema = $this->targets->targetFor($stem);
        if ($targetSchema === []) {
            throw new NotFoundHttpException("No public intake schema for [{$schema}].");
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
            $body = new PublicIntakeErrorData(
                message: "The submission for [{$schema}] is invalid.",
                errors: $errors,
            );

            // Render the DTO's OWN defended response (RendersJsonSafely) rather than reaching past
            // it. This is beam's one hostile-input door: unauthenticated, and `errors` is keyed by
            // JSON pointer into the SUBMITTED document, so the keys are the caller's field names.
            // Reaching past the DTO meant one invalid UTF-8 byte in a field name threw from
            // JsonResponse's constructor BEFORE the wrapping HttpResponseException existed, so
            // nothing on the path caught it and the submitter got a 500 naming JSON instead of a
            // 422 naming their field. HttpResponseException needs a Response, not a Responsable, so
            // the body is rendered here and re-statused instead of being returned as the DTO.
            throw new HttpResponseException(
                $body->toResponse($request)->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY)
            );
        }

        // `capture_key` is the ROUTE's slug, not the resolved stem: it is the unversioned intake identity
        // a host groups captures by, and the slug is what the submitter actually addressed.
        $record = new BeamSubmission([
            'capture_key' => $schema,
            'schema_ref' => $this->schemaRef($stem, $targetSchema),
        ]);
        $record->meta = ['intake' => $this->provenance($request, $actor)->toArray()];

        $writer = new ParticleWriter($gate, $this->targets, $this->acceptance, $this->events);

        // Report the WRITTEN model, never the instance handed in. Under `x-beam-dedupe`'s `ignore`
        // mode the pipeline hands back the row that MATCHED — a different object — and the response
        // must be byte-identical to a fresh capture's (ticket 50 §6): reading `$record` here would
        // return the unsaved instance's key instead, turning a public door into an
        // email-existence oracle by the shape of its own success body.
        $written = $writer->write($record, $payload, $actor);

        $body = new PublicIntakeAcceptedData(
            id: (string) $written->getKey(),
            schemaRef: (string) $written->schema_ref,
        );

        // Same reach-past as the 422 above, benign payload or not — fixed together so it cannot
        // regrow by copy from the neighbouring branch.
        return $body->toResponse($request)->setStatusCode(201);
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
