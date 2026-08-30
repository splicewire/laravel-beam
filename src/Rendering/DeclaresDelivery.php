<?php

namespace Splicewire\Beam\Rendering;

use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Scribe\Strategies\ParticleOperationParameterStrategy;

/**
 * Optional companion to {@see ResourceRendering}: what a rendering puts ON THE WIRE, stated statically
 * so the reference can document it without rendering a record (api-surface-coherence ticket 32 §C).
 *
 * The sibling of {@see ReversibleRendering} in shape — a rendering opts in, and nothing breaks if it
 * does not. What it opts into is different in kind, though: `ReversibleRendering` submits EVIDENCE that
 * the certifier then judges, while this interface is taken at its word. It has to be: a media type is
 * not derivable from anything else the rendering declares.
 *
 * `render()` already knows every fact here — it hands back a {@see RenderedDocument} carrying a content
 * type, extra headers, and a default it substituted for a null `$format`. But it only knows them one
 * record at a time, at request time, and a spec is written at build time against no record at all.
 * Hence the restatement. The way to keep the two from drifting is to make `render()` READ these methods
 * rather than repeat their values — `$format ?? $this->defaultFormat()` instead of `$format ?? 'html'`.
 *
 * An operation that declines this interface still documents: its 200 is the wildcard media type, with no
 * default and no headers. That is honest — "this endpoint delivers something and has not said what" — and it is meant
 * to read as an absence worth closing rather than a lie worth trusting.
 *
 * ## It is now the OPERATION surface's delivery port too, and that is why it grew `formats()`
 *
 * particle-operation-surface 11 decided that `#[ParticleOp]` gains a `delivery:` slot typed on THIS
 * interface, and that the interface absorbs `formats()` from the dissolving {@see ResourceRendering} —
 * so a declared operation can state the same four wire facts a rendering stated. Ticket 13 has since
 * retired the rendering registry, its mount and its controllers, and this is why that was possible
 * without regressing format validation from enforced-and-published to per-rendering ad hoc. **This
 * interface is now the ONLY declaration of a wire contract in beam**, and its readers are all on the
 * operation surface.
 *
 * ⚠️ **Adding a fourth method to a published interface is normally a break, and this one is MEASURED
 * INERT.** Every `DeclaresDelivery` implementor in the estate on 2026-08-29 — the three flagship
 * renderings (`~/Herd/splicewire-app/app/Renderings/{Composition,Circuit,Disclosure}*.php`) and beam's
 * own `tests/Fixtures/Rendering/TranscriptRendering.php` — also implements {@see ResourceRendering},
 * which ALREADY requires `formats(): array` with this exact signature. Four of four. So no implementor
 * gains an unimplemented method, and the interface can grow without touching a tree this package does
 * not own. Re-take that sweep before adding a fifth method; the property is a fact about today's
 * population, not a guarantee.
 *
 * ⚠️ **This interface OUTLIVES the subsystem its namespace is named after, and it is STILL not moved.**
 * 14 deferred the relocation to 13 on the theory that 13 empties this directory. It does not: 13 keeps
 * {@see RenderingCertifier}, {@see ReversibleRendering}, {@see ReversibilityProof},
 * {@see RenderedDocument} and {@see ResourceRendering} as the dormant reversibility seam (13 §3), so
 * `Splicewire\Beam\Rendering\` survives either way and the move buys a tidier `use` line at the cost
 * of editing three files in a host tree plus every consumer of a published interface. It stays until
 * something other than aesthetics asks for it.
 */
interface DeclaresDelivery
{
    /**
     * The DISTINCT media types this rendering can deliver, most-canonical first.
     *
     * Deliberately a list rather than a `format => mediaType` map. The consumer is the OpenAPI 200,
     * which keys its `content` by media type and has no slot for the format that selected one; several
     * formats routinely collapse onto one type (`json` and `structured` are both `application/json`,
     * `raw` and `fountain` are both `text/plain`), and a rendering with NO formats at all — one
     * representation, no parameter — still has exactly one media type to name.
     *
     * @return list<string>
     */
    public function mediaTypes(): array;

    /**
     * Response headers this rendering sets on every delivery, mapped to what each one carries.
     *
     * Only the headers the RENDERING adds — a transport-level `Content-Type` is the controller's and is
     * already implied by {@see mediaTypes()}.
     *
     * @return array<string, string>
     */
    public function deliveryHeaders(): array;

    /**
     * The format applied when the caller names none, or null when the rendering has no format axis.
     *
     * NOT inferable from `formats()[0]`, despite that method's "most-canonical first" contract: a
     * rendering whose set is enumerated live off a registry (compositions, off the profile registry)
     * has an enumeration order it does not control, and the default is a fixed choice rather than
     * whatever happened to register first.
     */
    public function defaultFormat(): ?string;

    /**
     * The representations this delivery can emit, most-canonical first — the VALIDATION contract.
     *
     * Absorbed from {@see ResourceRendering::formats()} (particle-operation-surface 11) so the
     * operation surface enforces and publishes the same set the rendering surface does, out of one
     * declaration. Its two readers are
     * {@see ParticleOperationController::format()}, which 422s an
     * unlisted value before the handler runs, and
     * {@see ParticleOperationParameterStrategy}, which publishes the
     * enum — the enforced set and the published set are one expression, never two that agree today.
     *
     * An EMPTY list means "no format axis": one representation, no `?format` parameter documented,
     * nothing validated, and no 422 written into the spec. It is a stronger statement than a one-member
     * list — a delivery that has never read `$format` should say so here rather than advertise the
     * single value it would have ignored. A binary download is the canonical empty case.
     *
     * @return list<string>
     */
    public function formats(): array;
}
