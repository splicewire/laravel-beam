<?php

namespace Splicewire\Beam\Intake\Data;

use Schemastud\DataSchemas\Attributes\Description;
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
 * intake schema) render Laravel's own `{message}` body from `AuthorizationException` /
 * `NotFoundHttpException` — framework shapes the door neither owns nor varies, so declaring them would
 * publish a contract beam does not control.
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
    ) {}
}
