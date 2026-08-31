<?php

namespace Splicewire\Beam\Tests\Particle;

use PHPUnit\Framework\Attributes\Test;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\Attributes\UngatedWriteDeclarations;
use Splicewire\Beam\Tests\Fixtures\OpGate\DeclaredUngatedWriteOpFixture;
use Splicewire\Beam\Tests\Fixtures\OpGate\GatedWriteOpFixture;
use Splicewire\Beam\Tests\Fixtures\OpGate\UngatedReadOpFixture;
use Splicewire\Beam\Tests\Fixtures\OpGate\UngatedWriteOpFixture;
use Splicewire\Beam\Tests\TestCase;

/**
 * **The host-free half of the `kind: Write` gate, pinned** (particle-write-surface ticket 02).
 *
 * `{@see UngatedWriteOperationAudit}` gates a HOST — it reads the booted registry and fails
 * `splicewire:beam:doctor`. This pins the other half: the source scan a DECLARING PACKAGE runs in its
 * own suite, with no host and no `surgeon:audit` (installed in 2 of 94 packages, measured 2026-08-31).
 *
 * Three assertions, in this order, and the order is the point. This estate's signature defect is *an
 * instrument that reports success by not running*, so the guard proves it looked ({@see $scanned}) and
 * that it could see everything it looked at ({@see $unloadable}) BEFORE it reports a clean tree. A
 * fourth test plants a real ungated declaration and watches the guard go red, because a guard nobody
 * has seen fail is not a guard.
 */
class DeclaredWriteOpsAreGatedTest extends TestCase
{
    /**
     * The guard itself: no `#[ParticleOp]` in beam's own source declares `kind: Write` without an
     * `ability:`. This is the assertion a declaring package copies, pointed at its own `src/`.
     */
    #[Test]
    public function test_no_write_operation_beam_declares_omits_its_ability(): void
    {
        $found = UngatedWriteDeclarations::in(dirname(__DIR__, 2).'/src');

        // Control 1 — the scan reached a real population. Wrong paths return an empty offender list
        // that is indistinguishable from a clean one.
        $this->assertGreaterThan(0, $found->scanned, 'The scan found no #[ParticleOp] declarations at all in beam/src — the paths are wrong, not the tree clean.');

        // Control 2 — nothing was silently skipped. `AttributedClassScanner` autoloads, and a class
        // whose parent is a missing `require-dev` is recorded here rather than dropped.
        $this->assertSame([], $found->unloadable, 'The scan could not autoload some classes, so its clean result is partial.');

        // The finding.
        $this->assertSame([], $found->offenders, $found->message());
    }

    /**
     * **The demonstration.** Pointed at a fixture directory holding one genuinely ungated write
     * declaration and three near-misses, the guard reports exactly the one.
     */
    #[Test]
    public function test_it_reports_a_planted_ungated_write_declaration(): void
    {
        $found = UngatedWriteDeclarations::in(dirname(__DIR__).'/Fixtures/OpGate');

        $this->assertSame(4, $found->scanned);
        $this->assertSame([], $found->unloadable);
        $this->assertSame([UngatedWriteOpFixture::class], $found->offenders);
        $this->assertStringContainsString('1 of 4', $found->message());
        $this->assertStringContainsString(UngatedWriteOpFixture::class, $found->message());
    }

    /**
     * The three near-misses, named, so a future widening of the rule has to delete an assertion rather
     * than quietly absorb a case. `ability: false` is a DECLARATION; a Read's gate is its query scope.
     */
    #[Test]
    public function test_a_declared_ability_a_declared_false_and_a_read_are_all_clean(): void
    {
        $offenders = UngatedWriteDeclarations::in(dirname(__DIR__).'/Fixtures/OpGate')->offenders;

        $this->assertNotContains(GatedWriteOpFixture::class, $offenders);
        $this->assertNotContains(DeclaredUngatedWriteOpFixture::class, $offenders);
        $this->assertNotContains(UngatedReadOpFixture::class, $offenders);
    }

    /**
     * A path that does not exist is silently skipped by the scanner, so `offenders === []` alone is a
     * false green. Pinned here because it is the failure mode control 1 exists for, and a control
     * nobody proved can fail is the same defect one tier up.
     */
    #[Test]
    public function test_a_scan_of_nothing_reports_zero_scanned_rather_than_a_clean_tree(): void
    {
        $found = UngatedWriteDeclarations::in(dirname(__DIR__).'/Fixtures/OpGate/does-not-exist');

        $this->assertSame(0, $found->scanned);
        $this->assertSame([], $found->offenders);
        $this->assertTrue(class_exists(ParticleOp::class));
    }
}
