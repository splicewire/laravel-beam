<?php

namespace Splicewire\Beam\Intake\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Validation\SchemaIntakeValidator;

/**
 * The 422 body of the generic public intake door (beam-facade ticket 124) — the shape a client
 * actually programs against, which is why it is declared rather than left to the success shape alone.
 *
 * `errors` is {@see SchemaIntakeValidator}'s opis-formatted map, keyed by **JSON pointer into the
 * submitted document** (`/applicant/email`, and `` for a root-level violation) rather than by dotted
 * Laravel field path. That is not a detail a client can guess from the success shape, and it is the
 * difference between rendering per-field errors and rendering a blob.
 *
 * Bounded, not unbounded: the validator reports at most {@see SchemaIntakeValidator::MAX_ERRORS}
 * violations per pass, so a large array payload against a strict schema cannot produce a runaway body.
 * Namespace-vocabulary violations merge into this SAME map — one coherent error shape, never a second
 * channel.
 *
 * Only the 422 is declared here. The door's 403 (schema not on the public allow-list) and 404 (no such
 * intake schema) render the framework's `{message}` body from `AuthorizationException` /
 * `NotFoundHttpException`, which beam does not declare. Since localization 21, a JSON request also gets
 * the stable `code` beside it: `write_not_authorized` and `intake_schema_unknown`.
 */
#[TypeScript]
class PublicIntakeErrorData extends BeamData
{
    /**
     * @param  array<string, list<string>>  $errors
     */
    public function __construct(
        #[Description('A human-readable summary naming the intake surface the submission was addressed to.')]
        public string $message,

        #[LiteralTypeScriptType('Record<string, string[]>')]
        #[Description('Violations keyed by JSON pointer into the submitted document — `/field`, or the empty string for a root-level violation. NOT a dotted Laravel field path.')]
        public array $errors,

        // localization 21 (REVIEW-B8 B2): the summary's stable machine code, beside its localized `message`. Additive:
        // a body built without one leaves the member out, so the existing two-member shape holds.
        #[Description('The stable machine code of the refusal (`intake_submission_invalid`), the same in every language.')]
        public string|Optional $code = new Optional,
    ) {}
}
