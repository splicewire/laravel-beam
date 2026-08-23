<?php

namespace Splicewire\Beam\Rendering;

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
 * A rendering that declines this interface still documents: its 200 is the wildcard media type, with no
 * default and no headers. That is honest — "this endpoint delivers something and has not said what" — and it is meant
 * to read as an absence worth closing rather than a lie worth trusting.
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
}
