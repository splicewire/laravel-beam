<?php

namespace Splicewire\Beam\Manifest;

use Splicewire\Beam\BeamServiceProvider;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Install\BeamInstallManifest;

/**
 * The "index of indexes" (beam-manifest-index) — a container SINGLETON every registry/manifest in the estate
 * describes ITSELF into, so `splicewire:beam:manifests` can answer the one question that otherwise lives only
 * in people's heads: "what registries exist, and where is each one's injection point?".
 *
 * It is the same self-registration manifest the estate already uses for installs and doctor audits (the
 * {@see BeamInstallManifest} / {@see BeamDoctorManifest}
 * pattern) turned on the registries themselves — the pattern applied to its own catalogue. The direction is
 * the same and load-bearing: an owner registers DOWN into the index from its OWN provider; **beam-core never
 * learns a consumer's name** (it renders whatever registered). A beam-* package or the host app adds ONE
 * `describe(...)` call per registry it owns; nothing here reaches up to discover them.
 *
 * beam-core self-describes its own registries from {@see BeamServiceProvider} (order-low), so
 * a fresh beam app already lists the full core set; consumers append their own.
 */
class ManifestIndex
{
    /** @var list<ManifestDescriptor> */
    private array $descriptors = [];

    /**
     * Describe a registry/manifest to the index. Idempotent per name — re-describing the same registry
     * replaces, so a provider that boots twice (test harness) doesn't double-list it.
     */
    public function describe(ManifestDescriptor $descriptor): void
    {
        $this->descriptors = array_values(array_filter(
            $this->descriptors,
            static fn (ManifestDescriptor $d): bool => $d->name !== $descriptor->name,
        ));

        $this->descriptors[] = $descriptor;
    }

    /**
     * All described registries, ordered foundation-first (ascending {@see ManifestDescriptor::$order}, ties
     * keeping registration order — `usort` has been stable since PHP 8.0).
     *
     * @return list<ManifestDescriptor>
     */
    public function descriptors(): array
    {
        $descriptors = $this->descriptors;
        usort($descriptors, static fn (ManifestDescriptor $a, ManifestDescriptor $b): int => $a->order <=> $b->order);

        return $descriptors;
    }
}
