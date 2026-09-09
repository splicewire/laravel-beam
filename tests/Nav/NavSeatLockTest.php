<?php

namespace Splicewire\Beam\Tests\Nav;

use Splicewire\Beam\Nav\NavSection;
use Splicewire\Beam\Nav\NavSeatLock;
use Splicewire\Beam\Tests\TestCase;

/**
 * The DECLARATION half of a soft-gated nav seat (Frame OS ticket 11 / ADR-0014 §A4) — what a package
 * says, as opposed to what a projector then does with it.
 *
 * A seat's `entitlement` list is an any-of gate; without a lock, failing it is HARD and the seat is
 * omitted, so a plan-gated section is one an unentitled user cannot discover — right for protection,
 * useless for monetization. {@see NavSeatLock} makes the same gate soft.
 *
 * Two rules carry the whole design and both are pinned below, because getting either backwards
 * produces a wrong answer that still looks like a working nav:
 *
 *  - **soft needs BOTH a lock and a gate.** A stray lock on an ungated seat would hide an
 *    always-visible section behind an upsell for everyone.
 *  - **a lock softens the ENTITLEMENT axis only.** It surrenders that axis (or a host's entitlement
 *    stage would omit the very node the lock keeps visible) and leaves `permission` untouched. A lock
 *    says "your plan does not include this"; a permission denial says "not for you". Softening the
 *    latter shows an upsell to a user whose organisation already pays.
 */
class NavSeatLockTest extends TestCase
{
    private function seat(?array $entitlement, ?string $permission, ?NavSeatLock $lock): NavSection
    {
        return new NavSection(
            key: 'studio',
            realm: 'tenant',
            label: 'Studio',
            icon: 'Clapperboard',
            href: '/studio',
            order: 10,
            entitlement: $entitlement,
            permission: $permission,
            lock: $lock,
        );
    }

    public function test_a_seat_is_soft_gated_only_when_it_declares_BOTH_a_lock_and_an_entitlement_gate(): void
    {
        $lock = new NavSeatLock('Available on the Songwriter plan', 'go-songwriter');

        $this->assertTrue($this->seat(['composition.music'], null, $lock)->isSoftGated());

        // A lock with nothing to soften.
        $this->assertFalse($this->seat(null, null, $lock)->isSoftGated());
        // A gate with no lock — hard, which is every seat declared before this existed.
        $this->assertFalse($this->seat(['composition.music'], null, null)->isSoftGated());
        // A lock cannot soften the permission axis into softness on its own.
        $this->assertFalse($this->seat(null, 'studio.view', $lock)->isSoftGated());
    }

    /**
     * `entitlement: []` is a gate that was DECLARED and admits nobody, distinct from `null`, which is
     * ungated — the distinction {@see NavSection} argues at length. It is a gate, so it softens.
     */
    public function test_a_declared_but_empty_entitlement_list_is_still_a_gate_a_lock_can_soften(): void
    {
        $this->assertTrue($this->seat([], null, new NavSeatLock('Coming soon'))->isSoftGated());
    }

    public function test_a_soft_seat_surrenders_its_entitlement_axis_and_keeps_its_permission_axis(): void
    {
        $soft = $this->seat(['composition.music'], 'studio.view', new NavSeatLock('Upgrade', 'go'));

        // The declaration itself is unchanged — `gate()` still reports what the package said.
        $this->assertSame(['entitlement' => ['composition.music'], 'permission' => 'studio.view'], $soft->gate());

        // What a projector hands the gate has the softened axis removed and nothing else touched.
        $this->assertSame(['permission' => 'studio.view'], $soft->gateAfterLock());
    }

    /** For a hard or ungated seat the two reads are identical, so a projector needs only one of them. */
    public function test_gate_after_lock_is_identical_to_gate_for_a_seat_that_is_not_soft(): void
    {
        foreach ([
            $this->seat(['composition.music'], 'studio.view', null),   // hard
            $this->seat(null, 'studio.view', null),                    // permission only
            $this->seat(null, null, null),                             // ungated
            $this->seat(null, 'studio.view', new NavSeatLock('x')),    // lock with nothing to soften
        ] as $i => $section) {
            $this->assertSame($section->gate(), $section->gateAfterLock(), "case {$i}");
        }
    }

    /** An upsell token is optional: a lock may state a reason with no actionable upgrade path. */
    public function test_a_lock_may_carry_a_reason_with_no_upsell_token(): void
    {
        $lock = new NavSeatLock('Coming soon');

        $this->assertSame('Coming soon', $lock->reason);
        $this->assertNull($lock->upsell);
    }

    /**
     * The default is HARD. This addition must be inert for every seat already declared across the
     * family, which is why `$lock` is the one slot on {@see NavSection} that has a default at all.
     */
    public function test_a_seat_declaring_no_lock_defaults_to_hard(): void
    {
        $this->assertNull($this->seat(['composition.music'], null, null)->lock);
        $this->assertFalse($this->seat(['composition.music'], null, null)->isSoftGated());
    }
}
