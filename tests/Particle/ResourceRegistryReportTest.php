<?php

namespace Splicewire\Beam\Tests\Particle;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Pagination\CursorPaginator as Paginator;
use Schemastud\Frame\Contracts\FrameResourceHandler;
use Schemastud\Frame\Contracts\FrameResourceHandlerResolver;
use Schemastud\Frame\Registry\ResourceDefinition;
use Splicewire\Beam\Particle\Backing\ResolvedRecord;
use Splicewire\Beam\Particle\Backing\ResolvesRecord;
use Splicewire\Beam\Particle\Backing\StreamsRecords;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Particle\ResourceRegistryReport;
use Splicewire\Beam\Particle\ResourceRegistryRow;
use Splicewire\Beam\Tests\Fixtures\WidgetGateData;
use Splicewire\Beam\Tests\TestCase;

/**
 * The registry's reading surface (otb-ui-frontier-sidebar DESIGN-02): capability read from the backing by
 * `instanceof`, intent read from the declaration, and the disagreement between them named.
 *
 * The load-bearing assertion in here is {@see test_capability_comes_from_the_backing_not_from_a_flag} —
 * every other column could be produced by copying declared fields, and a report that did that would agree
 * with the declaration by construction and could never disagree with it.
 */
class ResourceRegistryReportTest extends TestCase
{
    public function test_it_projects_a_model_backed_resource_with_its_realms_and_section(): void
    {
        $registry = new ParticleResourceRegistry;
        $registry->register(new ParticleResource(
            key: 'widgets',
            backing: 'App\\Models\\Widget',
            data: WidgetGateData::class,
            label: 'Widgets',
            section: 'operator',
            policy: 'widgets.manage',
        ), ['operator', 'tenant']);

        $row = $this->only($registry);

        $this->assertSame('widgets', $row->key);
        $this->assertSame('Widgets', $row->label);
        $this->assertSame(['operator', 'tenant'], $row->realms);
        $this->assertSame('operator', $row->section);
        $this->assertSame('widgets.manage', $row->policy);
        $this->assertTrue($row->framed);
        $this->assertSame('App\\Models\\Widget', $row->model);
    }

    /**
     * A resource naming no `section` is the DEFAULT, not a defect — and it is the population the whole
     * report exists to make readable, so the null has to survive the projection rather than being
     * papered over with a placeholder value.
     */
    public function test_a_resource_that_opts_out_of_nav_reports_a_null_section(): void
    {
        $registry = new ParticleResourceRegistry;
        $registry->register(new ParticleResource(key: 'silos', backing: 'App\\Models\\Silo'));

        $row = $this->only($registry);

        $this->assertNull($row->section);
        $this->assertFalse($row->framed);
    }

    /**
     * The report is not allowed to believe the declaration about what its backing can do.
     *
     * A model class-string becomes an `EloquentBacking` — list/query/write, and NOT `ResolvesRecord` (a
     * model-backed detail rides the declaration's own read projection). A hand-written stream-only
     * backing gets `list` and nothing else. Neither answer appears anywhere in either declaration.
     */
    public function test_capability_comes_from_the_backing_not_from_a_flag(): void
    {
        $registry = new ParticleResourceRegistry;
        $registry->register(new ParticleResource(key: 'widgets', backing: 'App\\Models\\Widget'));
        $registry->register(new ParticleResource(
            key: 'feed',
            backing: ReportStreamOnlyBacking::class,
            readOnly: true,
            filterable: false,
            showable: false,
        ));

        $rows = $this->keyed($registry);

        $this->assertTrue($rows['widgets']->streams);
        $this->assertTrue($rows['widgets']->queries);
        $this->assertTrue($rows['widgets']->writes);
        $this->assertFalse($rows['widgets']->resolves);
        $this->assertSame('list query write', $rows['widgets']->capabilities());

        $this->assertTrue($rows['feed']->streams);
        $this->assertFalse($rows['feed']->queries);
        $this->assertFalse($rows['feed']->resolves);
        $this->assertFalse($rows['feed']->writes);
        $this->assertSame('list', $rows['feed']->capabilities());
    }

    /**
     * The column that earns the command. `filterable` defaults to TRUE, so a custom backing acquires the
     * claim by saying nothing — and nothing at registration checks it, which is exactly why this is the
     * disagreement that occurs in practice while the write ones cannot.
     */
    public function test_filterable_against_a_backing_with_no_query_is_a_disagreement(): void
    {
        $registry = new ParticleResourceRegistry;
        $registry->register(new ParticleResource(
            key: 'feed',
            backing: ReportStreamOnlyBacking::class,
            readOnly: true,
            showable: false,
        ));

        $row = $this->only($registry);

        $this->assertSame(['filterable but backing has no QueriesRecords'], $row->disagreements);
    }

    public function test_showable_against_a_backing_that_cannot_resolve_a_record_is_a_disagreement(): void
    {
        $registry = new ParticleResourceRegistry;
        $registry->register(new ParticleResource(
            key: 'feed',
            backing: ReportStreamOnlyBacking::class,
            readOnly: true,
            filterable: false,
        ));

        $row = $this->only($registry);

        $this->assertSame(['showable but backing can neither ResolveRecord nor query'], $row->disagreements);
    }

    /**
     * A resolving backing clears the detail claim without implementing `QueriesRecords`, and an Eloquent
     * one clears it the other way — the two legal shapes of an honest detail read.
     */
    public function test_a_resolving_backing_and_a_model_backing_both_satisfy_showable(): void
    {
        $registry = new ParticleResourceRegistry;
        $registry->register(new ParticleResource(
            key: 'union',
            backing: ReportResolvingBacking::class,
            readOnly: true,
            filterable: false,
        ));
        $registry->register(new ParticleResource(key: 'widgets', backing: 'App\\Models\\Widget'));

        $rows = $this->keyed($registry);

        $this->assertSame([], $rows['union']->disagreements);
        $this->assertSame([], $rows['widgets']->disagreements);
    }

    /**
     * Narrowing is the mechanism working, not a finding. A writing backing declared `readOnly` must stay
     * out of the disagreement column, or every read-only resource in the estate buries the real defects.
     */
    public function test_a_narrowed_declaration_is_not_a_disagreement(): void
    {
        $registry = new ParticleResourceRegistry;
        $registry->register(new ParticleResource(key: 'widgets', backing: 'App\\Models\\Widget', readOnly: true));

        $row = $this->only($registry);

        $this->assertSame([], $row->disagreements);
        $this->assertTrue($row->writes);
        $this->assertFalse($row->creatable);
        $this->assertFalse($row->editable);
        $this->assertFalse($row->deletable);
    }

    /**
     * `editable`/`deletable` are nullable and FOLLOW the create gate when unset — the resolution
     * `toResourceDefinition()` performs. Reporting the raw null would understate what the resource opens.
     */
    public function test_nullable_affordances_are_resolved_the_way_the_manifest_resolves_them(): void
    {
        $registry = new ParticleResourceRegistry;
        $registry->register(new ParticleResource(key: 'widgets', backing: 'App\\Models\\Widget'));

        $row = $this->only($registry);

        $this->assertTrue($row->editable);
        $this->assertTrue($row->deletable);
        $this->assertSame('create edit delete show filter', $row->intent());
    }

    public function test_the_handler_column_reads_the_hosts_bound_resolver(): void
    {
        $registry = new ParticleResourceRegistry;
        $registry->register(new ParticleResource(key: 'widgets', backing: 'App\\Models\\Widget'));

        $report = new ResourceRegistryReport($registry, new ReportFixedHandlerResolver);

        $this->assertSame(ReportStubHandler::class, $report->rows()[0]->handler);
    }

    /**
     * No resolver bound is a legal host shape, and a report that cannot name a handler must still name
     * everything else — the estate's rule that a host-dependent answer is reported, never thrown.
     */
    public function test_no_bound_resolver_reports_an_unknown_handler_rather_than_failing(): void
    {
        $registry = new ParticleResourceRegistry;
        $registry->register(new ParticleResource(key: 'widgets', backing: 'App\\Models\\Widget'));

        $this->assertNull($this->only($registry)->handler);
    }

    public function test_it_filters_by_realm_and_by_section_including_the_no_section_population(): void
    {
        $registry = new ParticleResourceRegistry;
        $registry->register(new ParticleResource(
            key: 'widgets',
            backing: 'App\\Models\\Widget',
            data: WidgetGateData::class,
            label: 'Widgets',
            section: 'operator',
        ), ['operator']);
        $registry->register(new ParticleResource(key: 'silos', backing: 'App\\Models\\Silo'), ['tenant']);

        $report = new ResourceRegistryReport($registry);

        $this->assertSame(['widgets'], $this->keys($report->filtered(realm: 'operator')));
        $this->assertSame(['silos'], $this->keys($report->filtered(realm: 'tenant')));
        $this->assertSame(['widgets'], $this->keys($report->filtered(section: 'operator')));
        $this->assertSame(['silos'], $this->keys($report->filtered(section: 'none')));
    }

    public function test_it_filters_to_disagreements_only(): void
    {
        $registry = new ParticleResourceRegistry;
        $registry->register(new ParticleResource(key: 'widgets', backing: 'App\\Models\\Widget'));
        $registry->register(new ParticleResource(
            key: 'feed',
            backing: ReportStreamOnlyBacking::class,
            readOnly: true,
            showable: false,
        ));

        $report = new ResourceRegistryReport($registry);

        $this->assertSame(['feed'], $this->keys($report->filtered(disagreementsOnly: true)));
    }

    /** @param  list<ResourceRegistryRow>  $rows */
    private function keys(array $rows): array
    {
        return array_map(fn (ResourceRegistryRow $row) => $row->key, $rows);
    }

    private function only(ParticleResourceRegistry $registry): ResourceRegistryRow
    {
        $rows = (new ResourceRegistryReport($registry))->rows();

        $this->assertCount(1, $rows);

        return $rows[0];
    }

    /** @return array<string, ResourceRegistryRow> */
    private function keyed(ParticleResourceRegistry $registry): array
    {
        $keyed = [];

        foreach ((new ResourceRegistryReport($registry))->rows() as $row) {
            $keyed[$row->key] = $row;
        }

        return $keyed;
    }
}

class ReportStreamOnlyBacking implements StreamsRecords
{
    public function records(array $filters, ?string $cursor, int $perPage): CursorPaginator
    {
        return new Paginator([], $perPage);
    }
}

class ReportResolvingBacking implements ResolvesRecord, StreamsRecords
{
    public function records(array $filters, ?string $cursor, int $perPage): CursorPaginator
    {
        return new Paginator([], $perPage);
    }

    public function resolve(string $id, array $filters): ?ResolvedRecord
    {
        return null;
    }
}

class ReportStubHandler implements FrameResourceHandler
{
    public function index(ResourceDefinition $definition, array $params): array
    {
        return [];
    }

    public function show(ResourceDefinition $definition, string $id): array
    {
        return [];
    }

    public function store(ResourceDefinition $definition, array $input): array
    {
        return [];
    }

    public function update(ResourceDefinition $definition, string $id, array $input): array
    {
        return [];
    }

    public function destroy(ResourceDefinition $definition, string $id): void {}
}

class ReportFixedHandlerResolver implements FrameResourceHandlerResolver
{
    public function handlerFor(string $resource): FrameResourceHandler
    {
        return new ReportStubHandler;
    }
}
