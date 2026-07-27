<?php

namespace Splicewire\Beam\Doctor;

use Rushing\Doctor\Finding;

/**
 * Verifies the site carries a valid Beam Manifest (BEAM.md at its repo root; legacy SATELLITE.md
 * still accepted during the rename): the file exists and declares at least the required identity
 * keys (`satellite`, `variant`). A beam site is self-describing (ADR-0001); a missing or
 * unparseable manifest means the Sweep and the reconcile flow have nothing to key off, so it's a
 * hard failure.
 *
 * Relocated from the satellite doctor (base/shell conformance is owned by beam:doctor). Distinct
 * from {@see FrameManifestAudit}, which audits the frame editor's admin-resource registry — same
 * word, different concern. Pure: the command reads the file + parses the front matter and hands
 * over the identity so this stays unit testable.
 */
class BeamManifestAudit
{
    public function run(bool $fileExists, ?string $satellite, ?string $variant): Finding
    {
        $check = 'beam manifest present and valid';

        if (! $fileExists) {
            return Finding::fail(
                $check,
                'BEAM.md is missing at the repo root — add one declaring `satellite`, `variant`, and any Deviations (see references/conformance.md).'
            );
        }

        $missing = [];
        if ($satellite === null || $satellite === '') {
            $missing[] = 'satellite';
        }
        if ($variant === null || $variant === '') {
            $missing[] = 'variant';
        }

        if ($missing !== []) {
            return Finding::fail(
                $check,
                'BEAM.md is missing required key(s): '.implode(', ', $missing).' — the front matter must declare them.'
            );
        }

        return Finding::pass($check, "BEAM.md declares site `{$satellite}` (variant `{$variant}`).");
    }
}
