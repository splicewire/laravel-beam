<?php

namespace Splicewire\Beam\Tests\Nav;

use ReflectionMethod;
use ReflectionParameter;
use Splicewire\Beam\Nav\NavAudience;
use Splicewire\Beam\Nav\NavSection;
use Splicewire\Beam\Tests\TestCase;

/**
 * ux-walkthrough IA-8 / M4 (UX-08): every seat declares its audience. It was required with no default, so that "this
 * seat is for product users" and "I never decided" were not spelled the same way; that fataled every installed host
 * written before UX-08a, so it is deprecate-with-default (lead 17:34Z). The two stay apart: an omitted audience is
 * drawn as product but recorded as UNDECLARED (`audienceDeclared`), deprecated and named by `beam:doctor`
 * ({@see NavSectionAudienceDefaultTest}).
 */
class NavSectionTest extends TestCase
{
    public function test_audience_defaults_to_null_and_an_omitted_one_stays_distinguishable_from_product(): void
    {
        $audience = collect((new ReflectionMethod(NavSection::class, '__construct'))->getParameters())
            ->first(fn (ReflectionParameter $p): bool => $p->getName() === 'audience');

        $this->assertNotNull($audience);
        $this->assertTrue($audience->isOptional());
        $this->assertNull($audience->getDefaultValue());
        $this->assertSame(NavAudience::class, $audience->getType()?->getName());
        $this->assertTrue($audience->getType()?->allowsNull());

        $declared = new NavSection(
            key: 'billing', realm: 'operator', label: 'Billing', icon: 'Receipt', href: '/billing', order: 30,
            entitlement: null, permission: null, audience: NavAudience::Product,
        );
        $this->assertTrue($declared->audienceDeclared);
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
