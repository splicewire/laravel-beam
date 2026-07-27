<?php

namespace Splicewire\Beam\Doctor;

use Rushing\Doctor\Finding;

/**
 * Report-only readiness check for the sitemap surface. Flags the two silent
 * misconfigurations: the subsystem disabled, and a static public/ file that
 * shadows the mode-aware routes (nginx serves it before Laravel, defeating the
 * gate).
 *
 * Relocated from the satellite doctor (base/shell conformance is owned by beam:doctor);
 * generalized off the satellite naming — the enabled flag reads `beam.sitemap.enabled`. Pure so
 * the taxonomy is unit-testable without a filesystem.
 */
class SitemapReadinessAudit
{
    /**
     * @param  list<string>  $shadowingFiles  public/ paths present that would shadow the routes
     */
    public function run(bool $enabled, array $shadowingFiles): Finding
    {
        $check = 'sitemap routes serve dynamically';

        if (! $enabled) {
            return Finding::warn(
                $check,
                'the sitemap subsystem is off (beam.sitemap.enabled = false) — /sitemap.xml and /robots.txt are not served.',
            );
        }

        if ($shadowingFiles !== []) {
            return Finding::warn(
                $check,
                'a static '.implode(' and ', $shadowingFiles).' shadows the mode-aware route(s) — nginx serves the file before Laravel, so the gate never fires. Delete it (unless you are running `splicewire:sitemap:generate` on purpose).',
            );
        }

        return Finding::pass(
            $check,
            'enabled and no static public/ file shadows the routes.',
        );
    }
}
