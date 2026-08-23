<?php

namespace Splicewire\Beam\Tests\Schema;

use Splicewire\Beam\Schema\Keywords;
use Splicewire\Beam\Tests\TestCase;

/**
 * The keyword-ownership guard beam-core gained with its FIRST owned keyword (beam-facade ticket 66;
 * design in ticket 50 §3), mirroring the one `splicewire/laravel-beam-notifications` ships.
 *
 * The doctrine it enforces: there is no central keyword list to curate — a keyword is legitimate
 * because some package declares it here, and drift is caught by each package asserting that what it
 * reads and emits stays within `base ∪ own`. So this file is the whole of beam-core's claim.
 */
class KeywordOwnershipTest extends TestCase
{
    public function test_beam_core_owns_x_beam_dedupe_under_the_x_beam_family_prefix(): void
    {
        $this->assertSame('x-beam-dedupe', Keywords::Dedupe);
        $this->assertSame('x-beam', Keywords::Prefix);
        $this->assertContains('x-beam-dedupe', Keywords::owned());
    }

    public function test_every_owned_keyword_carries_the_declared_family_prefix(): void
    {
        foreach (Keywords::owned() as $keyword) {
            $this->assertStringStartsWith(Keywords::Prefix.'-', $keyword);
        }
    }

    /**
     * `x-beam-notify` is `splicewire/laravel-beam-notifications`' — beam-core reads it nowhere and
     * must never claim it. Homing dedupe here and notify there is the split ticket 50 §3 argued:
     * dedupe is a property of CAPTURE, which is core's, while notify is an optional capability.
     */
    public function test_beam_core_does_not_claim_another_packages_keyword(): void
    {
        $this->assertNotContains('x-beam-notify', Keywords::owned());
    }
}
