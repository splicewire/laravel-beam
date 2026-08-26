<?php

namespace Splicewire\Beam\Intake\Data;

use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\Data;
use Splicewire\Beam\Http\PublicIntakeController;

/**
 * The request body of the generic public intake door (beam-facade ticket 124) — declared as the
 * OPEN DOCUMENT it is.
 *
 * This class deliberately declares no properties, and that is the contract rather than an omission.
 * Every other declared request shape in the estate fixes its fields at boot; this one cannot, because
 * the door's whole capability is that *a host mounts an intake surface with no PHP class of its own*
 * ({@see PublicIntakeController}'s own docblock). The concrete field list is the registered JSON
 * Schema the route's `{schema}` slug resolves to, per request — and that schema is itself a generated,
 * committed artifact under `resources/schemas`, so the door's per-surface request contract IS
 * published, just at the schema-registry tier instead of the PHP-class tier.
 *
 * Two consequences worth stating, because both are easy to read backwards:
 *
 * 1. **The emitted JSON Schema is open on purpose.** `additionalProperties: false` is stamped only in
 *    the generator's `llm_strict` mode, never in `request` mode, so an empty declared envelope means
 *    "an object whose properties are governed elsewhere" and not "an object that must be empty".
 * 2. **The honeypot field is not declared, and must never be.** It is stripped from the payload
 *    before anything else reads it (`config('beam.core.intake.honeypot.field')`, default `website`);
 *    publishing its name in the OpenAPI spec would hand a bot the one thing the trap depends on.
 */
#[TypeScript]
#[LiteralTypeScriptType('Record<string, unknown>')]
class PublicIntakeSubmissionData extends Data {}
