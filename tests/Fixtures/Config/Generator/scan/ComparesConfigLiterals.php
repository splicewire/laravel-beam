<?php

namespace Splicewire\Beam\Tests\Fixtures\Config\Generator\scan;

/**
 * Fixture source for the generator's literal-comparison scan (config-sections ticket 06), shaped like beam-ux's
 * `PageGraph` (`config('beam.ux.head.publisher') === 'Person'`). It is scanned as text and never called.
 */
class ComparesConfigLiterals
{
    public function publisherIsAPerson(): bool
    {
        return config('beam.ux.head.publisher') === 'Person';
    }

    public function realmIsKnown(): bool
    {
        return in_array(config('beam.ux.frame_nav.default_realm'), ['tenant', 'operator'], true);
    }
}
