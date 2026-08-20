<?php

namespace Splicewire\Beam\OpenApi;

use Illuminate\Http\Request;

/**
 * The spec-source seam (ADR-0211 §3) — "which spec bytes, in which format, for this request."
 *
 * Beam ships ONE spec ({@see ConfiguredArtifactSpecSource}, reading the configured artifact), because
 * Scribe is strictly build-time and has no notion of request-time gating. The variance lives HERE rather
 * than in the artifact: a host that wants per-capability specs pre-generates them
 * (`scribe:generate --config=<name> --scribe-dir=<dir>` — N configs is N independent specs, natively) and
 * rebinds this contract, with **zero change to the route, the docs page, or the `<ApiReference>`
 * component**.
 *
 * That is the ADR-0145 warning honoured without paying for it: no variant *config schema* ships now,
 * because an unused enumeration of variants is the single-value assumption's mirror image. The seam alone
 * is what keeps the single-value assumption from being baked in.
 *
 * Runtime FILTERING is the option deliberately not taken. A per-caller OpenAPI filter would make beam
 * CORE reach for `Beam\Commerce\Entitlements\EntitlementGate` and `Beam\Tenancy\Tenant` — two tiers above
 * it — and would forfeit public cacheability. A rebinding host that wants exactly that is free to do it;
 * it just does not become beam's dependency graph.
 *
 * The `$request` is passed so a rebound implementation can resolve per caller. The default ignores it.
 */
interface OpenApiSpecSource
{
    /**
     * Resolve the spec for this request, or null when there is nothing to serve (⇒ the route 404s).
     *
     * Null is a first-class answer, not an error: a host that has never generated an artifact — a fresh
     * clone, a CI box that skipped the deploy script — is not misconfigured, it just has no spec yet.
     */
    public function spec(SpecFormat $format, Request $request): ?OpenApiSpec;
}
