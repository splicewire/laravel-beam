<?php

namespace Splicewire\Beam\Tests\Nav;

use PHPUnit\Framework\TestCase;
use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Doctor\NavSectionAudienceAudit;
use Splicewire\Beam\Nav\NavAudience;
use Splicewire\Beam\Nav\NavSection;

/**
 * Lead decision 17:34Z (integrator 17:33Z): `audience` is DEPRECATE-WITH-DEFAULT, not required. A required argument
 * with no default fataled every installed host that declared a seat before UX-08a (fresh-tower 500'd with
 * "Argument #9 ($audience) not passed"). An old-shape declaration now boots as a product seat and says so, once per
 * declaring class, in the deprecation log and in `beam:doctor`. An explicit declaration behaves exactly as before.
 */
class NavSectionAudienceDefaultTest extends TestCase
{
    /** @var list<string> */
    private array $deprecations = [];

    protected function setUp(): void
    {
        parent::setUp();
        NavSection::forgetUndeclaredAudience();
        $this->deprecations = [];
        set_error_handler(function (int $level, string $message): bool {
            $this->deprecations[] = $message;

            return true;
        }, E_USER_DEPRECATED);
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        NavSection::forgetUndeclaredAudience();
        parent::tearDown();
    }

    public function test_an_old_shape_seat_without_an_audience_boots_as_a_product_seat_and_says_so_once(): void
    {
        $first = OldShapeSeats::declare('billing');
        $second = OldShapeSeats::declare('people');

        $this->assertSame(NavAudience::Product, $first->audience);
        $this->assertFalse($first->audienceDeclared);
        $this->assertSame(NavAudience::Product, $second->audience);

        // Once per DECLARING class, naming it, however many seats it declares.
        $this->assertCount(1, $this->deprecations);
        $this->assertStringContainsString(OldShapeSeats::class, $this->deprecations[0]);
        $this->assertSame([OldShapeSeats::class], NavSection::undeclaredAudience());
    }

    public function test_an_explicit_audience_is_unchanged_and_silent(): void
    {
        $seat = new NavSection(
            key: 'ops', realm: 'operator', label: 'Ops', icon: 'Server', href: '/ops', order: 80,
            entitlement: null, permission: null, audience: NavAudience::Developer,
        );

        $this->assertSame(NavAudience::Developer, $seat->audience);
        $this->assertTrue($seat->audienceDeclared);
        $this->assertSame([], $this->deprecations);
        $this->assertSame([], NavSection::undeclaredAudience());
    }

    public function test_the_doctor_names_each_declaring_class_that_omits_the_audience(): void
    {
        $this->assertSame(DoctorStatus::Pass, (new NavSectionAudienceAudit)->run()[0]->status);

        OldShapeSeats::declare('billing');

        $findings = (new NavSectionAudienceAudit)->run();
        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString(OldShapeSeats::class, $findings[0]->detail);
    }
}

/** A declaration written before UX-08a: named arguments, no `audience`. */
class OldShapeSeats
{
    public static function declare(string $key): NavSection
    {
        return new NavSection(
            key: $key, realm: 'operator', label: ucfirst($key), icon: 'Square', href: '/'.$key, order: 10,
            entitlement: null, permission: null,
        );
    }
}
