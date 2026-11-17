<?php

namespace Splicewire\Beam\Tests\Graph;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Doctor\GraphSlotIntegrityAudit;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Graph\Edges\EdgeTable;
use Splicewire\Beam\Graph\Edges\GraphEdges;
use Splicewire\Beam\Graph\Edges\ParentEdge;
use Splicewire\Beam\Graph\EloquentGraphBacking;
use Splicewire\Beam\Particle\Backing\GraphsRecords;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\Graph\Fixtures\AttributedTreeNodeData;
use Splicewire\Beam\Tests\Graph\Fixtures\GraphReadUser;
use Splicewire\Beam\Tests\Graph\Fixtures\GraphTreeNode;
use Splicewire\Beam\Tests\Graph\Fixtures\GraphWidgetBacking;
use Splicewire\Beam\Tests\TestCase;

/**
 * graph-operations 04 — **the `graph:` slot: a model-backed resource declares its edges.**
 *
 * The done-when, each measured here:
 *  - one `graph:` slot on a model-backed fixture yields ticket 02's four operations, with no backing class written;
 *  - a row outside the declared scope is absent from nodes, edges and paths;
 *  - a set over the cap is refused with the cap named, and a set under it is not;
 *  - (the generator's check, in {@see GraphSlotGeneratorTest}).
 *
 * The tree: 1 (A) → 2 (B) → 4 (unnamed), and 1 → 3 (C). Team `blue` is row 2; every other row is `red`.
 */
class GraphSlotTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('graph_tree_nodes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('name')->nullable();
            $table->string('team');
        });

        Schema::create('graph_tree_links', function (Blueprint $table): void {
            $table->unsignedBigInteger('from_id');
            $table->unsignedBigInteger('to_id');
            $table->string('kind')->nullable();
            $table->float('weight')->nullable();
        });

        DB::table('graph_tree_nodes')->insert([
            ['id' => 1, 'parent_id' => null, 'name' => 'A', 'team' => 'red'],
            ['id' => 2, 'parent_id' => 1, 'name' => 'B', 'team' => 'blue'],
            ['id' => 3, 'parent_id' => 1, 'name' => 'C', 'team' => 'red'],
            ['id' => 4, 'parent_id' => 2, 'name' => null, 'team' => 'red'],
        ]);
    }

    private function register(GraphEdges $graph, bool $scoped = false, string $key = 'tree-nodes'): void
    {
        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: $key,
            backing: GraphTreeNode::class,
            readOnly: true,
            scope: $scoped ? fn (Builder $query): Builder => $query->where('team', 'red') : null,
            graph: $graph,
        ));
    }

    private function mountAsReader(string $key = 'tree-nodes'): void
    {
        Gate::before(fn () => true); // admit the read gate; 02 proves the gate itself
        $this->actingAs(new GraphReadUser);
        Particle::mount($key)->ops(true)->register();
    }

    /** @return list<string> */
    private function ids(array $nodes): array
    {
        $ids = array_column($nodes, 'id');
        sort($ids);

        return $ids;
    }

    /** @return list<string> "from>to" pairs, sorted */
    private function pairs(array $edges): array
    {
        $pairs = array_map(fn (array $edge) => $edge['from'].'>'.$edge['to'], $edges);
        sort($pairs);

        return $pairs;
    }

    // ── one slot, four operations, no backing class ──────────────────────────────────────────────────

    public function test_one_graph_slot_yields_the_four_operations_with_no_backing_class(): void
    {
        $this->register(new ParentEdge('parent_id', label: 'name'));

        $this->assertSame(
            ['graph', 'graph.cycles', 'graph.neighbours', 'graph.path'],
            array_map(fn (ParticleOperation $op) => $op->name, $this->app->make(ParticleOperationRegistry::class)->forResource('tree-nodes')),
        );

        // The declaration names a bare model; beam supplies the port over it.
        $resource = $this->app->make(ParticleResourceRegistry::class)->get('tree-nodes');
        $this->assertSame(GraphTreeNode::class, $resource->backing);
        $this->assertInstanceOf(EloquentGraphBacking::class, $resource->backing());
        $this->assertInstanceOf(GraphsRecords::class, $resource->backing());
        $this->assertTrue($resource->graphsRecords());
    }

    public function test_the_same_model_without_the_slot_derives_nothing(): void
    {
        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'plain-tree-nodes',
            backing: GraphTreeNode::class,
            readOnly: true,
        ));

        $this->assertSame([], $this->app->make(ParticleOperationRegistry::class)->forResource('plain-tree-nodes'));
        $this->assertNotInstanceOf(GraphsRecords::class, $this->app->make(ParticleResourceRegistry::class)->get('plain-tree-nodes')->backing());
    }

    public function test_a_parent_edge_answers_the_four_over_rest(): void
    {
        $this->register(new ParentEdge('parent_id', label: 'name'));
        $this->mountAsReader();

        $slice = $this->getJson('/tree-nodes/graph?depth=5&limit=100')->assertOk();
        $this->assertSame(['1', '2', '3', '4'], $this->ids($slice->json('data.nodes')));
        $this->assertSame(['1>2', '1>3', '2>4'], $this->pairs($slice->json('data.edges')));
        $this->assertSame(['PARENT_OF'], array_values(array_unique(array_column($slice->json('data.edges'), 'type'))));

        // A node is a reference: its label is the declared label column, else its id; it names its resource.
        $labels = array_column($slice->json('data.nodes'), 'label', 'id');
        ksort($labels);
        $this->assertSame([1 => 'A', 2 => 'B', 3 => 'C', 4 => '4'], $labels);
        $this->assertSame(['tree-nodes'], array_values(array_unique(array_column($slice->json('data.nodes'), 'resource'))));

        // From a root, downward: the slot's edges run parent → child.
        $roots = $this->getJson('/tree-nodes/graph?roots[]=2&depth=5&limit=100')->assertOk();
        $this->assertSame(['2', '4'], $this->ids($roots->json('data.nodes')));

        $neighbours = $this->getJson('/tree-nodes/1/graph/neighbours?direction=descendants&depth=5&limit=100')->assertOk();
        $this->assertSame(['1', '2', '3', '4'], $this->ids($neighbours->json('data.nodes')));

        $path = $this->getJson('/tree-nodes/1/graph/path?target=4')->assertOk();
        $this->assertSame(['1', '2', '4'], array_column($path->json('data.nodes'), 'id'));

        $this->assertSame([], $this->getJson('/tree-nodes/graph/cycles?limit=10')->assertOk()->json('data.cycles'));
    }

    // ── the row scope applies before a single node exists ────────────────────────────────────────────

    public function test_a_row_outside_the_scope_is_absent_from_nodes_edges_and_paths(): void
    {
        $this->register(new ParentEdge('parent_id', label: 'name'), scoped: true);
        $this->mountAsReader();

        // Row 2 (blue) is outside the scope: not a node, and neither edge that touches it.
        $slice = $this->getJson('/tree-nodes/graph?depth=5&limit=100')->assertOk();
        $this->assertSame(['1', '3', '4'], $this->ids($slice->json('data.nodes')));
        $this->assertSame(['1>3'], $this->pairs($slice->json('data.edges')));

        // No path runs through it: 1 reaches 4 only through 2.
        $path = $this->getJson('/tree-nodes/1/graph/path?target=4')->assertOk();
        $this->assertSame([], $path->json('data.nodes'));

        $neighbours = $this->getJson('/tree-nodes/1/graph/neighbours?direction=descendants&depth=5&limit=100')->assertOk();
        $this->assertSame(['1', '3'], $this->ids($neighbours->json('data.nodes')));

        // And it is not an anchor: the scoped store has no such node.
        $this->getJson('/tree-nodes/2/graph/neighbours?direction=descendants&depth=5&limit=100')->assertNotFound();
    }

    public function test_an_edge_table_reads_types_and_weights_and_keeps_only_in_scope_edges(): void
    {
        DB::table('graph_tree_links')->insert([
            ['from_id' => 1, 'to_id' => 3, 'kind' => 'FEEDS', 'weight' => 2.5],
            ['from_id' => 3, 'to_id' => 4, 'kind' => null, 'weight' => null],
            ['from_id' => 4, 'to_id' => 1, 'kind' => 'FEEDS', 'weight' => 1],
            ['from_id' => 1, 'to_id' => 2, 'kind' => 'FEEDS', 'weight' => 1], // 2 is out of scope
        ]);

        $this->register(new EdgeTable('graph_tree_links', from: 'from_id', to: 'to_id', type: 'kind', weight: 'weight', label: 'name'), scoped: true);
        $this->mountAsReader();

        $slice = $this->getJson('/tree-nodes/graph?depth=5&limit=100')->assertOk();
        $this->assertSame(['1', '3', '4'], $this->ids($slice->json('data.nodes')));
        $this->assertSame(['1>3', '3>4', '4>1'], $this->pairs($slice->json('data.edges')));

        $byPair = [];
        foreach ($slice->json('data.edges') as $edge) {
            $byPair[$edge['from'].'>'.$edge['to']] = [$edge['type'], (float) $edge['weight']];
        }
        $this->assertSame(['FEEDS', 2.5], $byPair['1>3']);
        $this->assertSame(['LINKS', 1.0], $byPair['3>4']); // no type, no weight: the defaults

        // 1 → 3 → 4 → 1 is a cycle inside the scope.
        $cycles = $this->getJson('/tree-nodes/graph/cycles?limit=10')->assertOk();
        $this->assertCount(1, $cycles->json('data.cycles'));
    }

    // ── the cap (D5): refused with the cap named, never truncated ────────────────────────────────────

    public function test_a_set_over_the_cap_is_refused_naming_it_and_a_set_under_it_is_not(): void
    {
        $this->register(new ParentEdge('parent_id'));
        $this->mountAsReader();

        config()->set('beam.core.graph.max_nodes', 3);

        // Four scoped rows against a ceiling of three: refused before one is loaded, whatever op asks.
        foreach (['/tree-nodes/graph?depth=5&limit=3', '/tree-nodes/graph/cycles?limit=3', '/tree-nodes/1/graph/neighbours?direction=descendants&depth=2&limit=3', '/tree-nodes/1/graph/path?target=4'] as $uri) {
            $refused = $this->getJson($uri)->assertStatus(422);
            $this->assertSame('over_limit', $refused->json('code'), $uri);
            $this->assertSame('nodes', $refused->json('limit'), $uri);
            $this->assertSame(3, $refused->json('max'), $uri);
            $this->assertSame(4, $refused->json('requested'), $uri);
        }

        config()->set('beam.core.graph.max_nodes', 4);

        $this->assertCount(4, $this->getJson('/tree-nodes/graph?depth=5&limit=4')->assertOk()->json('data.nodes'));
    }

    public function test_the_cap_counts_the_scoped_set_not_the_table(): void
    {
        $this->register(new ParentEdge('parent_id'), scoped: true);
        $this->mountAsReader();

        config()->set('beam.core.graph.max_nodes', 3);

        // Four rows in the table, three in scope: under the ceiling.
        $this->assertCount(3, $this->getJson('/tree-nodes/graph?depth=5&limit=3')->assertOk()->json('data.nodes'));
    }

    // ── declaration: both spellings, and the refusal ──────────────────────────────────────────────────

    public function test_the_attribute_spelling_carries_the_slot(): void
    {
        $this->app->make(ParticleResourceRegistry::class)->registerClass(AttributedTreeNodeData::class);

        $resource = $this->app->make(ParticleResourceRegistry::class)->get('attributed-tree-nodes');
        $this->assertInstanceOf(ParentEdge::class, $resource->graph);
        $this->assertSame('name', $resource->graph->labelColumn());
        $this->assertCount(4, $this->app->make(ParticleOperationRegistry::class)->forResource('attributed-tree-nodes'));
    }

    public function test_a_graph_slot_beside_a_backing_that_already_graphs_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('declares graph: on backing');

        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'double-graph',
            backing: GraphWidgetBacking::class,
            readOnly: true,
            graph: new ParentEdge('parent_id'),
        ));
    }

    // ── the doctor (scope 6) ─────────────────────────────────────────────────────────────────────────

    public function test_the_doctor_names_a_column_or_table_the_model_does_not_have(): void
    {
        $audit = fn () => $this->app->make(GraphSlotIntegrityAudit::class)->run()[0];

        $this->assertFalse($audit()->conclusive);

        $this->register(new ParentEdge('parent_id', label: 'name'));
        $this->assertSame(DoctorStatus::Pass, $audit()->status);
        $this->assertTrue($audit()->conclusive);

        $this->register(new ParentEdge('mother_id'), key: 'broken-tree');
        $this->register(new EdgeTable('no_such_links', from: 'a', to: 'b'), key: 'broken-links');

        $finding = $audit();
        $this->assertSame(DoctorStatus::Warn, $finding->status);
        $this->assertStringContainsString('`graph_tree_nodes.mother_id`', $finding->detail);
        $this->assertStringContainsString('`no_such_links`', $finding->detail);
    }
}
