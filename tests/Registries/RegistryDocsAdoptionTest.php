<?php

namespace Splicewire\Beam\Tests\Registries;

use PHPUnit\Framework\TestCase;
use Rushing\Popcorn\Registries\Docs\RegistryDocsCheck;

/**
 * laravel-beam adopts the package-tier registry docs check (registry-docs tickets 03, 04 and 08): every
 * root this package declares has its page, manifest pointer or exemption, and every page's generated
 * facts block is byte-identical to a fresh render of its `#[IsRegistry]` declaration.
 *
 * The scope is the whole of `src/`, the check's default. Measured 2026-11-03 by this same check: `src/`
 * declares 19 roots — 18 pages under `docs/registries/` and one exemption in its `manifest.json`
 * (`beam.doctor.facade-scope`, rebind-only, explained on the `beam.doctor` cluster page).
 *
 * The count is asserted exactly so that a new `#[IsRegistry]` fails here until its page is written, and
 * so that an inactive check (no `docs/registries/`) cannot pass with an empty coverage map.
 */
class RegistryDocsAdoptionTest extends TestCase
{
    public function test_every_declared_root_is_documented_with_its_facts_in_step(): void
    {
        $report = RegistryDocsCheck::forPackage(dirname(__DIR__, 2))->run();

        $this->assertSame([], $report->failures(), $report->summary());
        $this->assertCount(20, $report->coverage, 'declared roots: '.implode(', ', array_keys($report->coverage)));
        $this->assertSame(
            ['beam.doctor.facade-scope'],
            array_keys(array_filter($report->coverage, fn (string $kind) => $kind === 'exempt')),
            'only the rebind-only facade scope is exempt; every other root has a page',
        );
    }
}
