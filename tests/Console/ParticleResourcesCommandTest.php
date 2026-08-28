<?php

namespace Splicewire\Beam\Tests\Console;

use Illuminate\Support\Facades\Artisan;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\Particle\ReportStreamOnlyBacking;
use Splicewire\Beam\Tests\TestCase;

/**
 * The operator-facing half of the registry reading surface.
 *
 * The assertions worth keeping are the two that are about JUDGEMENT rather than rendering: the census
 * line counts the WHOLE registry even under a filter (a narrowed count printed under a census heading is
 * how a partial reading gets quoted forward as an estate figure), and `--section=none` addresses the
 * nav-invisible population, which has no other spelling — a null section cannot be typed as an option
 * value.
 */
class ParticleResourcesCommandTest extends TestCase
{
    /** How many resources beam itself had registered before this test added its two. */
    private int $baseline = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $registry = $this->app->make(ParticleResourceRegistry::class);

        // ⚠️ NOT an empty registry. beam boots with its own `schemas` resource registered, so every
        // count here is relative — hard-coding 2 would pass today and break the day beam declares a
        // second one of its own, which is the estate's stale-census failure in miniature.
        $this->baseline = count($registry->all());

        $registry->register(new ParticleResource(
            key: 'widgets',
            backing: 'App\\Models\\Widget',
            label: 'Widgets',
            section: 'operator',
        ), ['operator']);
        $registry->register(new ParticleResource(
            key: 'feed',
            backing: ReportStreamOnlyBacking::class,
            readOnly: true,
            showable: false,
        ), ['tenant']);
    }

    public function test_it_lists_every_registered_resource_with_its_capabilities(): void
    {
        $this->artisan('splicewire:beam:particle:resources')
            ->expectsOutputToContain('widgets')
            ->expectsOutputToContain('feed')
            ->expectsOutputToContain('list query write')
            ->assertSuccessful();
    }

    public function test_the_census_line_counts_the_whole_registry_not_the_filtered_view(): void
    {
        $this->artisan('splicewire:beam:particle:resources', ['--realm' => 'operator'])
            // One substring, not four: the whole census is a single written line, and the runner
            // consumes at most one expectation per line.
            ->expectsOutputToContain(sprintf(
                '%d registered · %d opt into nav (1 in no section) · 1 with an intent/capability disagreement',
                $this->baseline + 2,
                $this->baseline + 1,
            ))
            ->expectsOutputToContain('1 row(s) shown by the active filter')
            ->assertSuccessful();
    }

    public function test_section_none_addresses_the_resources_that_opt_out_of_nav(): void
    {
        $this->artisan('splicewire:beam:particle:resources', ['--section' => 'none', '--json' => true])
            ->assertSuccessful();

        $rows = $this->rowsFor(['--section' => 'none']);

        $this->assertSame(['feed'], array_column($rows, 'key'));
        $this->assertNull($rows[0]['section']);
    }

    public function test_disagreements_only_narrows_to_intent_exceeding_capability(): void
    {
        $rows = $this->rowsFor(['--disagreements' => true]);

        $this->assertSame(['feed'], array_column($rows, 'key'));
        $this->assertSame(['filterable but backing has no QueriesRecords'], $rows[0]['disagreements']);
    }

    /**
     * The handler column is filled from whatever resolver the host bound — here beam's own OOTB default,
     * which answers the singular particle handler for every key. That is the fall-through reading the
     * census counts, and it is a fact about the host, never about the declaration.
     */
    public function test_the_handler_column_names_the_hosts_resolved_handler(): void
    {
        $rows = $this->rowsFor(['--realm' => 'operator']);

        $this->assertNotNull($rows[0]['handler']);
        $this->assertStringContainsString('ParticleFrameResourceHandler', $rows[0]['handler']);
    }

    /**
     * Naming the resolver is not decoration. A host that binds a bespoke map and a host that binds
     * nothing produce handler cells that look alike at row level and mean opposite things, and the
     * flagship turned out to be the second — so the census number is only readable next to this line.
     */
    public function test_it_names_the_resolver_that_answered_the_handler_column(): void
    {
        $this->artisan('splicewire:beam:particle:resources')
            ->expectsOutputToContain('handlers resolved through DefaultParticleResourceHandlerResolver.')
            ->assertSuccessful();
    }

    public function test_a_filter_matching_nothing_says_so_rather_than_printing_an_empty_table(): void
    {
        $this->artisan('splicewire:beam:particle:resources', ['--realm' => 'nowhere'])
            ->expectsOutputToContain(sprintf('No resource matches that filter (%d registered).', $this->baseline + 2))
            ->assertSuccessful();
    }

    /**
     * @param  array<string, mixed>  $options
     * @return list<array<string, mixed>>
     */
    private function rowsFor(array $options): array
    {
        Artisan::call('splicewire:beam:particle:resources', $options + ['--json' => true]);

        return json_decode(Artisan::output(), true);
    }
}
