<?php

namespace Splicewire\Beam\Tests\Nav;

use ReflectionMethod;
use ReflectionParameter;
use Splicewire\Beam\Nav\NavAudience;
use Splicewire\Beam\Nav\NavPage;
use Splicewire\Beam\Tests\TestCase;

/**
 * A host page seat (app-walkthrough M4′, APP-1, APP-15): ONE declaration of a bespoke page feeds both its RouteContext leaf
 * and its nav row, and the row's `href` is minted from the leaf, never authored.
 */
class NavPageTest extends TestCase
{
    private function page(?int $navOrder = 3, string $shell = 'app', ?string $guard = null): NavPage
    {
        return new NavPage(
            realm: 'operator',
            routeName: 'usage.index',
            path: 'usage',
            shell: $shell,
            mounts: 'detail',
            guard: $guard,
            section: 'platform',
            label: 'Usage & Cost',
            icon: 'Activity',
            navOrder: $navOrder,
            audience: NavAudience::Product,
        );
    }

    public function test_no_slot_has_a_default(): void
    {
        $defaulted = collect((new ReflectionMethod(NavPage::class, '__construct'))->getParameters())
            ->filter(fn (ReflectionParameter $parameter): bool => $parameter->isDefaultValueAvailable())
            ->map(fn (ReflectionParameter $parameter): string => $parameter->getName())
            ->values()->all();

        $this->assertSame([], $defaulted, 'a page seat states its shell, guard, order and audience on the record');
    }

    public function test_it_carries_no_href_slot_so_a_href_cannot_be_authored(): void
    {
        $names = collect((new ReflectionMethod(NavPage::class, '__construct'))->getParameters())
            ->map(fn (ReflectionParameter $parameter): string => $parameter->getName())->all();

        $this->assertNotContains('href', $names);
    }

    public function test_it_projects_the_route_context_standalone_entry(): void
    {
        $this->assertSame(
            ['routeName' => 'usage.index', 'path' => 'usage', 'mounts' => 'detail', 'guard' => null, 'shell' => 'app'],
            $this->page()->standalone(),
        );
        $this->assertSame('system', $this->page(shell: 'system')->standalone()['shell']);
        $this->assertSame('central', $this->page(guard: 'central')->standalone()['guard']);
    }

    public function test_its_nav_row_takes_the_minted_href(): void
    {
        $this->assertSame(
            ['title' => 'Usage & Cost', 'href' => '/operator/usage', 'icon' => 'Activity', 'routeName' => 'usage.index', 'navOrder' => 3],
            $this->page()->navRow(['usage.index' => '/operator/usage', 'other' => '/x']),
        );
    }

    public function test_a_row_with_no_order_carries_no_nav_order_key(): void
    {
        $this->assertArrayNotHasKey('navOrder', $this->page(navOrder: null)->navRow(['usage.index' => '/operator/usage']));
    }

    public function test_a_page_whose_leaf_mints_no_href_refuses_rather_than_spelling_one(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('usage.index');

        $this->page()->navRow(['tenants.index' => '/operator/tenants']);
    }

    public function test_the_standalone_list_is_one_realms_pages_in_declared_order(): void
    {
        $tenant = new NavPage(realm: 'tenant', routeName: 'calendar', path: 'calendar', shell: 'app', mounts: 'detail',
            guard: null, section: 'calendar', label: 'Calendar', icon: 'CalendarDays', navOrder: null, audience: NavAudience::Product);

        $this->assertSame(['usage.index'], array_column(NavPage::standaloneFor([$this->page(), $tenant], 'operator'), 'routeName'));
        $this->assertSame(['calendar'], array_column(NavPage::standaloneFor([$this->page(), $tenant], 'tenant'), 'routeName'));
    }

    public function test_the_rows_for_a_section_are_minted_and_filtered_by_realm_and_section(): void
    {
        $other = new NavPage(realm: 'operator', routeName: 'market.review-queue.index', path: 'market/review-queue', shell: 'app',
            mounts: 'detail', guard: null, section: 'market', label: 'Review queue', icon: 'ClipboardCheck', navOrder: null,
            audience: NavAudience::Product);
        $hrefs = ['usage.index' => '/operator/usage', 'market.review-queue.index' => '/operator/market/review-queue'];

        $rows = NavPage::rowsFor([$this->page(), $other], 'operator', 'platform', $hrefs);

        $this->assertSame(['usage.index'], array_column($rows, 'routeName'));
        $this->assertSame([], NavPage::rowsFor([$this->page(), $other], 'tenant', 'platform', $hrefs));
    }
}
