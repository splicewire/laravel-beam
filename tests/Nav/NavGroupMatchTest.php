<?php

namespace Splicewire\Beam\Tests\Nav;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Splicewire\Beam\Nav\NavGroupMatch;

/**
 * Root ruling f46b2b53 (analytics-area 22): a group's `match` is realm-relative and keeps the flagship's path-prefix
 * meaning, plus one additive form, a trailing `*` for a raw string prefix. The flagship's existing Knowledge -> Graph
 * group must read exactly as it always has.
 */
class NavGroupMatchTest extends TestCase
{
    public function test_a_path_match_on_a_root_realm_is_the_flagships_meaning_unchanged(): void
    {
        $graph = NavGroupMatch::resolve('knowledge/graph', '/');

        $this->assertSame('/knowledge/graph', $graph->stem);
        $this->assertTrue($graph->takes('/knowledge/graph'));
        $this->assertTrue($graph->takes('/knowledge/graph/concepts'));
        $this->assertFalse($graph->takes('/knowledge/graphs'));
        $this->assertFalse($graph->takes('/knowledge'));
        $this->assertSame('knowledge/graph*', $graph->linkMatch());
        $this->assertSame('knowledge.graph.section', $graph->routeName());
    }

    public function test_a_match_is_resolved_against_the_realms_route_base(): void
    {
        $graph = NavGroupMatch::resolve('/knowledge/graph/', '/operator/');

        $this->assertSame('/operator/knowledge/graph', $graph->stem);
        $this->assertTrue($graph->takes('/operator/knowledge/graph/rules'));
        $this->assertFalse($graph->takes('/knowledge/graph/rules'), 'the root realm\'s page is not the operator realm\'s');
        $this->assertSame('operator.knowledge.graph.section', $graph->routeName());
    }

    public function test_a_trailing_star_takes_a_raw_prefix_and_not_the_area_it_extends(): void
    {
        foreach (['/' => '', '/operator' => '/operator'] as $routeBase => $base) {
            $panels = NavGroupMatch::resolve('analytics-traffic-*', $routeBase);

            $this->assertTrue($panels->raw);
            $this->assertSame($base.'/analytics-traffic-', $panels->stem);
            foreach (['trend', 'pages', 'referrers'] as $panel) {
                $this->assertTrue($panels->takes($base.'/analytics-traffic-'.$panel));
            }
            $this->assertFalse($panels->takes($base.'/analytics-traffic'), 'the Traffic area keeps its own tab');
            $this->assertFalse($panels->takes($base.'/analytics-events'));
            $this->assertSame(ltrim($base.'/analytics-traffic-*', '/'), $panels->linkMatch());
            $this->assertSame(ltrim(str_replace('/', '.', $base).'.analytics-traffic.section', '.'), $panels->routeName());
        }
    }

    /** @return iterable<string, array{0: string}> */
    public static function malformed(): iterable
    {
        yield 'empty' => [''];
        yield 'slashes only' => ['//'];
        yield 'a bare star' => ['*'];
        yield 'a star inside' => ['analytics-*-trend'];
        yield 'two stars' => ['analytics**'];
    }

    #[DataProvider('malformed')]
    public function test_a_malformed_match_is_refused(string $match): void
    {
        $this->assertFalse(NavGroupMatch::isWellFormed($match));
        $this->expectException(\InvalidArgumentException::class);

        NavGroupMatch::resolve($match, '/');
    }
}
