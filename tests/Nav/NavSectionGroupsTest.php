<?php

namespace Splicewire\Beam\Tests\Nav;

use PHPUnit\Framework\Attributes\DataProvider;
use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Doctor\NavSectionGroupLabelAudit;
use Splicewire\Beam\Nav\NavAudience;
use Splicewire\Beam\Nav\NavSection;
use Splicewire\Beam\Nav\NavSectionRegistry;
use Splicewire\Beam\Tests\TestCase;

/**
 * analytics-area 22 (owner ruling 2026-11-12): a package section declares its own nav groups, `groups: [['label' => …,
 * 'match' => …]]`, as the flagship's `AppNavigation::section(groups:)` does. These pin beam's half: the field is
 * optional and validated, same-key seats merge their groups with the HOST's first and drop none, and a repeated label
 * is a `beam:doctor` warning rather than a silent override. Projecting the groups into the strip is beam-ux's.
 */
class NavSectionGroupsTest extends TestCase
{
    private function seat(int $order, array $groups = [], string $label = 'Reports'): NavSection
    {
        return new NavSection(
            key: 'reports', realm: 'operator', label: $label, icon: 'ChartBar', href: '/operator/reports', order: $order,
            entitlement: null, permission: null, audience: NavAudience::Product, groups: $groups,
        );
    }

    /** @param  array<string, NavSection>  $seats  registrant ('' = the host, registered with no `by:`) => seat */
    private function merged(array $seats): NavSection
    {
        $registry = new NavSectionRegistry;
        foreach ($seats as $by => $seat) {
            $registry->register($seat, by: $by === '' ? null : $by);
        }
        $sections = $registry->for('operator');
        $this->assertCount(1, $sections);

        return $sections[0];
    }

    public function test_a_seat_without_groups_has_none_and_merges_to_none(): void
    {
        $this->assertSame([], $this->seat(20)->groups);
        $this->assertSame([], $this->merged(['acme/reports' => $this->seat(20), '' => $this->seat(10)])->groups);
    }

    public function test_a_seat_keeps_its_groups_in_declared_order(): void
    {
        $groups = [['label' => 'Traffic', 'match' => 'analytics/traffic'], ['label' => 'Graph', 'match' => 'knowledge/graph'], ['label' => 'Panels', 'match' => 'analytics-traffic-*']];

        $this->assertSame($groups, $this->seat(20, $groups)->groups);
    }

    /** @return iterable<string, array{0: mixed}> */
    public static function malformedGroups(): iterable
    {
        yield 'not a list' => [['traffic' => ['label' => 'Traffic', 'match' => 'traffic']]];
        yield 'no label' => [[['match' => 'traffic']]];
        yield 'blank label' => [[['label' => '  ', 'match' => 'traffic']]];
        yield 'no match' => [[['label' => 'Traffic']]];
        yield 'a bare slash for match' => [[['label' => 'Traffic', 'match' => '/']]];
        yield 'a non-string match' => [[['label' => 'Traffic', 'match' => 7]]];
        yield 'a string, not a group' => [['Traffic']];
        yield 'a star inside the match' => [[['label' => 'Traffic', 'match' => 'analytics-*-trend']]];
        yield 'a bare star' => [[['label' => 'Traffic', 'match' => '*']]];
    }

    #[DataProvider('malformedGroups')]
    public function test_a_malformed_group_is_refused_where_it_is_declared(mixed $groups): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('operator.reports');

        $this->seat(20, $groups);
    }

    public function test_same_key_seats_merge_their_groups_with_the_hosts_first_whichever_seat_is_the_header(): void
    {
        $package = $this->seat(20, [['label' => 'Traffic', 'match' => 'reports/traffic']], 'Package reports');
        $host = $this->seat(30, [['label' => 'Archive', 'match' => 'reports/archive']], 'Host reports');

        foreach ([['acme/reports' => $package, '' => $host], ['' => $host, 'acme/reports' => $package]] as $seats) {
            $seat = $this->merged($seats);

            $this->assertSame('Package reports', $seat->label, 'the lower order still supplies the header');
            $this->assertSame(['Archive', 'Traffic'], array_column($seat->groups, 'label'), "the host's group comes first");
            $this->assertNull($seat->groupRegistrant(0));
            $this->assertSame('acme/reports', $seat->groupRegistrant(1));
        }
    }

    public function test_packages_keep_merge_order_behind_the_host_across_three_seats(): void
    {
        $seat = $this->merged([
            'acme/a' => $this->seat(10, [['label' => 'A', 'match' => 'reports/a']]),
            'acme/b' => $this->seat(20, [['label' => 'B', 'match' => 'reports/b']]),
            '' => $this->seat(30, [['label' => 'Host', 'match' => 'reports/host']]),
        ]);

        $this->assertSame(['Host', 'A', 'B'], array_column($seat->groups, 'label'));
        $this->assertSame([null, 'acme/a', 'acme/b'], [$seat->groupRegistrant(0), $seat->groupRegistrant(1), $seat->groupRegistrant(2)]);
    }

    public function test_a_duplicate_label_is_kept_not_overridden(): void
    {
        $seat = $this->merged([
            'acme/reports' => $this->seat(20, [['label' => 'Traffic', 'match' => 'reports/traffic']]),
            '' => $this->seat(10, [['label' => 'Traffic', 'match' => 'reports/visits']]),
        ]);

        $this->assertSame(['reports/visits', 'reports/traffic'], array_column($seat->groups, 'match'));
    }

    public function test_the_doctor_passes_without_a_repeated_label(): void
    {
        $registry = (new NavSectionRegistry)
            ->register($this->seat(20, [['label' => 'Traffic', 'match' => 'reports/traffic']]), by: 'acme/reports')
            ->register($this->seat(10), by: null);

        $findings = (new NavSectionGroupLabelAudit($registry))->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
    }

    public function test_the_doctor_names_a_repeated_label_and_both_declarers(): void
    {
        $registry = (new NavSectionRegistry)
            ->register($this->seat(20, [['label' => 'Traffic', 'match' => 'reports/traffic']]), by: 'acme/reports')
            ->register($this->seat(10, [['label' => 'Traffic', 'match' => 'reports/visits']]), by: null);

        $findings = (new NavSectionGroupLabelAudit($registry))->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertSame(NavSectionGroupLabelAudit::CHECK, $findings[0]->check);
        $this->assertStringContainsString('[operator.reports]', $findings[0]->detail);
        $this->assertStringContainsString('"Traffic"', $findings[0]->detail);
        $this->assertStringContainsString('the host, acme/reports', $findings[0]->detail);
    }
}
