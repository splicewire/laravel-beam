<?php

namespace Splicewire\Beam\Tests\Nav;

use ReflectionMethod;
use ReflectionParameter;
use Splicewire\Beam\Nav\NavAudience;
use Splicewire\Beam\Nav\NavSection;
use Splicewire\Beam\Tests\TestCase;

/**
 * ux-walkthrough IA-8 / M4 (UX-08): every seat declares its audience. Required and with NO default, for the reason the
 * gate slots have none: "this seat is for product users" and "I never decided" must not be spelled the same way.
 */
class NavSectionTest extends TestCase
{
    public function test_audience_is_required_and_has_no_default(): void
    {
        $audience = collect((new ReflectionMethod(NavSection::class, '__construct'))->getParameters())
            ->first(fn (ReflectionParameter $p): bool => $p->getName() === 'audience');

        $this->assertNotNull($audience);
        $this->assertFalse($audience->isOptional());
        $this->assertSame(NavAudience::class, $audience->getType()?->getName());
    }

    public function test_there_are_exactly_two_audiences(): void
    {
        $this->assertSame(['product', 'developer'], array_map(fn (NavAudience $a): string => $a->value, NavAudience::cases()));
    }

    public function test_a_seat_carries_its_declared_audience(): void
    {
        $seat = new NavSection(
            key: 'ops', realm: 'operator', label: 'Ops', icon: 'Server', href: '/ops', order: 80,
            entitlement: null, permission: null, audience: NavAudience::Developer,
        );

        $this->assertSame(NavAudience::Developer, $seat->audience);
    }
}
