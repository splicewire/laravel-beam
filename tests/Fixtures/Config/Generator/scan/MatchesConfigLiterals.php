<?php

namespace Splicewire\Beam\Tests\Fixtures\Config\Generator\scan;

/** Fixture source for the literal scan's `match` form (config-sections ticket 06). Scanned as text, never called. */
class MatchesConfigLiterals
{
    public function speed(): int
    {
        return match (config('cs06.registry.mode')) {
            'fast' => 1,
            'slow', 'idle' => 0,
            default => -1,
        };
    }
}
