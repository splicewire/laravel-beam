<?php

namespace Splicewire\Beam\Tests\Registries;

use PHPUnit\Framework\TestCase;
use Rushing\Popcorn\Registries\Docs\RegistryDocsCheck;

/**
 * laravel-beam adopts the package-tier registry docs check (registry-docs tickets 03 and 04): every
 * root in the adopted scope has its page, manifest pointer or exemption, and every page's generated
 * facts block is byte-identical to a fresh render of its `#[IsRegistry]` declaration.
 *
 * ⚠️ The scope is `src/Particle` — the `beam.particle.*` pilot cluster — not the whole of `src/`.
 * Measured 2026-11-03 by this same check: `src/` declares 19 roots, of which the pilot covers 5.
 * Exempting the other 14 would be false (they are documentable, just not yet written), so the scope
 * widens directory by directory as clusters are written (ticket 08's rollout) and ends at `src/`.
 * Never scope a directory in before its roots are covered.
 */
class RegistryDocsAdoptionTest extends TestCase
{
    /** The documented scope: widen as each cluster lands, ending at `src/` (then drop the argument). */
    private const SCOPE = ['src/Particle'];

    public function test_every_root_in_the_adopted_scope_is_documented_with_its_facts_in_step(): void
    {
        $package = dirname(__DIR__, 2);
        $report = RegistryDocsCheck::forPackage($package, array_map(fn (string $dir) => $package.'/'.$dir, self::SCOPE))->run();

        $this->assertSame([], $report->failures(), $report->summary());
        $this->assertCount(5, $report->coverage, 'the beam.particle.* cluster: '.implode(', ', array_keys($report->coverage)));
    }
}
