<?php

namespace Splicewire\Beam\Rendering;

/**
 * One named, derived projection of a resource — the thing `Route::resourceRenderings()` mounts.
 *
 * A rendering is *derived truth* (see the exporter's standing line: "the compiled document is derived
 * truth"). The resource's own records remain the durable, editable source of record; a rendering reads
 * them and emits a deliverable. A projection that becomes independently addressable AND independently
 * editable has stopped being a rendering and become a publish — that is `PublishPayload`'s seam, not
 * this one.
 *
 * Deliberately NO `fidelity()` method. A rendering may not describe its own reversibility: the read
 * verb is unconditional, and the write verb is granted only by {@see RenderingCertifier}, which
 * exercises the well-behavedness laws. Implement {@see ReversibleRendering} to *submit evidence* for a
 * write verb; you cannot declare your way to one.
 *
 * **`formats()` is the validation contract** (api-surface-coherence ticket 32 §D). It used to be
 * decoration — documented here as existing "for documentation, discovery and manifests", with zero call
 * sites estate-wide, while each rendering rejected a bad format in whatever shape its own surface
 * happened to reject in. Those shapes were three and two were wrong (a 500, and a silent ignore).
 * {@see Http\RenderingsController} now validates against this method before calling `render()`, so it is
 * the set the wire ENFORCES and the set the reference publishes, and the three cannot drift apart.
 *
 * `render()` still receives `null` for "your default" — the controller does not substitute one — and a
 * rendering may still reject on a per-RECORD basis (the route accepts the union; a record's own profile
 * narrows it). What it no longer has to do is police the union itself.
 *
 * Implement {@see DeclaresDelivery} to state what comes back — media types, added headers, and the
 * default this rendering applies to a null `$format` — so the reference can document a real response
 * instead of an untyped one.
 */
interface ResourceRendering
{
    /**
     * The rendering's name. This is the terminal URI segment AND the route-name segment the macro
     * mounts (`{resource}/{id}/{name}`), so it must be a bare, slash-free, URL-safe token — `export`,
     * `timeline`, `transcript`.
     */
    public function name(): string;

    /**
     * The formats this rendering can emit, most-canonical first. Enumerated live (typically off a
     * registry) rather than hard-coded, so a newly registered profile widens the rendering without an
     * edit here.
     *
     * An EMPTY list means "no format axis" — one representation, no `?format=` parameter documented, and
     * nothing for the controller to validate against. It is a stronger statement than a one-member list:
     * a rendering that has never read `$format` should say so here rather than advertise the single
     * value it would have ignored.
     *
     * @return list<string>
     */
    public function formats(): array;

    /**
     * Compile the subject into a deliverable. A null `$format` means "apply your own default" — the
     * controller never substitutes one, so a rendering's existing default behaviour is preserved
     * byte-for-byte when an endpoint migrates onto the macro.
     */
    public function render(object $subject, ?string $format = null): RenderedDocument;
}
