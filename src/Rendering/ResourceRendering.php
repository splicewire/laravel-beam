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
 * Format validation is the rendering's own business, not the controller's. `formats()` is the
 * enumeration (for documentation, discovery and manifests); `render()` receives whatever the caller
 * asked for — including `null` for "your default" — and is responsible for rejecting a format it does
 * not support, in whatever shape its surface already rejects it.
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
