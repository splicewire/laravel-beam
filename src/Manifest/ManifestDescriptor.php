<?php

namespace Splicewire\Beam\Manifest;

/**
 * One entry in the {@see ManifestIndex} — a registry/manifest describing ITSELF to the index (name, what it
 * registers, its injection-seam shape, and where it lives). A registry-owning provider builds one of these
 * and pushes it down into the index, exactly as it self-registers an install step or a doctor audit.
 *
 * `registerHint` is the registry-SPECIFIC one-liner ("bind into `beam.client.sources`") that sharpens the
 * generic {@see ManifestSeam::blurb()} ("bind an implementation per config key") to this registry's own
 * config key / attribute / method. Keeping both means the index reads well even for a seam shape a host has
 * never seen.
 */
class ManifestDescriptor
{
    /**
     * @param  string  $name  the registry/manifest name as a host would search for it (e.g. "RouteManifestSource")
     * @param  string  $of  what it is a registry OF, in plain words (e.g. "client-SDK route manifests, per realm")
     * @param  ManifestSeam  $seam  the injection-point shape (how an entry gets IN)
     * @param  ManifestArity  $arity  the resolution arity (how many entries a read engages OUT) — the axis the
     *                                Registry/Manifest naming split was really tracking (canon: the-seam-is-a-registry)
     * @param  string  $registerHint  the registry-specific how-to-inject one-liner
     * @param  string  $where  the FQCN / config key / composer block the seam lives at
     * @param  string|null  $package  the owning package (for the "who registered this" column); null ⇒ app-local
     * @param  int  $order  render order, core/foundation first (ascending)
     */
    public function __construct(
        public string $name,
        public string $of,
        public ManifestSeam $seam,
        public ManifestArity $arity,
        public string $registerHint,
        public string $where,
        public ?string $package = null,
        public int $order = 100,
    ) {}
}
