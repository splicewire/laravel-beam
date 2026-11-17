<?php

namespace Splicewire\Beam\Tests\Graph;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Route as RouteInstance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Graph\GraphOperations;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\Graph\Fixtures\GraphReadUser;
use Splicewire\Beam\Tests\Graph\Fixtures\GraphWidgetBacking;
use Splicewire\Beam\Tests\Graph\Fixtures\PlainWidget;
use Splicewire\Beam\Tests\TestCase;

/**
 * graph-operations 02 — **four read operations, derived for every graph-capable resource.**
 *
 * A resource whose backing implements {@see GraphsRecords} gets `graph`, `graph.cycles`,
 * `graph.neighbours` and `graph.path` without declaring them; a resource without the capability gets
 * none. The done-when, each measured here:
 *
 *  - the four answer over REST at their D10 addresses with their declared output;
 *  - bad input is a 422; an over-limit request is the declared refusal, naming the limit;
 *  - a resource without the capability has none of the four — measured in the registry AND the route list;
 *  - with `->ops()` absent from the mount, none of the four is routed;
 *  - (the generated client is measured in {@see GraphClientSurfaceTest}).
 */
class GraphOperationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('graph_widgets', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        foreach (range(1, 5) as $id) {
            DB::table('graph_widgets')->insert(['id' => $id, 'name' => "W{$id}"]);
        }
    }

    private function registerGraphResource(): void
    {
        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'graph-widgets',
            backing: GraphWidgetBacking::class,
            readOnly: true,
        ));
    }

    /** @return list<string> every route name for a resource, in route-table order */
    private function routeNames(string $resource): array
    {
        Route::getRoutes()->refreshNameLookups();

        $names = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            /** @var RouteInstance $route */
            $name = $route->getName();

            if (is_string($name) && str_starts_with($name, "{$resource}.") && ! str_starts_with($name, "{$resource}.hooks")) {
                $names[] = $name;
            }
        }

        return $names;
    }

    private function uriOf(string $name): ?string
    {
        Route::getRoutes()->refreshNameLookups();

        return Route::getRoutes()->getByName($name)?->uri();
    }

    // ── derivation: registering a graph-capable resource registers exactly the four ───────────────────

    public function test_registering_a_graph_capable_resource_registers_the_four_operations(): void
    {
        $this->registerGraphResource();

        $operations = $this->app->make(ParticleOperationRegistry::class);

        $this->assertSame(
            ['graph', 'graph.cycles', 'graph.neighbours', 'graph.path'],
            array_map(fn (ParticleOperation $op) => $op->name, $operations->forResource('graph-widgets')),
        );

        // Each declares its input/output from ticket 01, kind Read, GET, and its refusal through errors:.
        $neighbours = $operations->get('graph-widgets', 'graph.neighbours');
        $this->assertSame(\Splicewire\Beam\Graph\Data\GraphNeighboursInput::class, $neighbours->input);
        $this->assertSame(\Splicewire\Beam\Graph\Data\GraphSliceData::class, $neighbours->output);
        $this->assertSame(\Splicewire\Beam\Routing\HttpMethod::Get, $neighbours->method);
        $this->assertSame([422 => \Splicewire\Beam\Graph\Data\GraphRefusalData::class], $neighbours->errors);
    }

    public function test_a_resource_without_the_capability_has_none_of_the_four_in_the_registry(): void
    {
        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'plain-widgets',
            backing: PlainWidget::class, // a bare Eloquent model — no GraphsRecords
            readOnly: true,
        ));

        $this->assertSame([], $this->app->make(ParticleOperationRegistry::class)->forResource('plain-widgets'));
    }

    public function test_rank_and_a_native_query_passthrough_are_not_among_the_derived_operations(): void
    {
        $names = array_map(fn (ParticleOperation $op) => $op->name, GraphOperations::operationsFor('graph-widgets'));

        $this->assertNotContains('rank', $names);
        $this->assertSame(['graph', 'graph.cycles', 'graph.neighbours', 'graph.path'], $names);
    }

    // ── the four answer over REST at their D10 addresses ──────────────────────────────────────────────

    public function test_the_four_answer_over_rest_at_their_addresses_with_declared_output(): void
    {
        Gate::before(fn () => true); // admit the resource's read gate; the gate itself is proven below
        $this->actingAs(new GraphReadUser);
        $this->registerGraphResource();
        Particle::mount('graph-widgets')->ops(true)->register();

        $this->assertSame('graph-widgets/graph', $this->uriOf('graph-widgets.graph'));
        $this->assertSame('graph-widgets/graph/cycles', $this->uriOf('graph-widgets.graph.cycles'));
        $this->assertSame('graph-widgets/{id}/graph/neighbours', $this->uriOf('graph-widgets.graph.neighbours'));
        $this->assertSame('graph-widgets/{id}/graph/path', $this->uriOf('graph-widgets.graph.path'));

        // graph — a bounded slice (no roots → the whole scoped snapshot, 5 nodes, 5 edges).
        $slice = $this->getJson('/graph-widgets/graph?depth=5&limit=100')->assertOk();
        $this->assertCount(5, $slice->json('data.nodes'));
        $this->assertCount(5, $slice->json('data.edges'));
        $this->assertSame(['id', 'type', 'resource', 'label'], array_keys($slice->json('data.nodes.0')));
        $this->assertSame('graph-widgets', $slice->json('data.nodes.0.resource'));

        // graph.neighbours — from record 1, descendants to depth 5.
        $neighbours = $this->getJson('/graph-widgets/1/graph/neighbours?direction=descendants&depth=5&limit=100')->assertOk();
        $ids = array_column($neighbours->json('data.nodes'), 'id');
        sort($ids);
        $this->assertSame(['1', '2', '3', '4', '5'], $ids);

        // graph.path — 1 → 5 is 1,2,3,4,5.
        $path = $this->getJson('/graph-widgets/1/graph/path?target=5')->assertOk();
        $this->assertSame(['1', '2', '3', '4', '5'], array_column($path->json('data.nodes'), 'id'));
        $this->assertCount(4, $path->json('data.edges'));

        // graph.cycles — one cycle 1→2→3→1.
        $cycles = $this->getJson('/graph-widgets/graph/cycles?limit=10')->assertOk();
        $this->assertCount(1, $cycles->json('data.cycles'));
        $this->assertNotEmpty($cycles->json('data.cycles.0.nodes'));
    }

    public function test_graph_path_with_no_route_answers_an_empty_path(): void
    {
        Gate::before(fn () => true);
        $this->actingAs(new GraphReadUser);
        $this->registerGraphResource();
        Particle::mount('graph-widgets')->ops(true)->register();

        // 5 → 1 has no forward path (5 is a sink).
        $path = $this->getJson('/graph-widgets/5/graph/path?target=1')->assertOk();
        $this->assertSame([], $path->json('data.nodes'));
        $this->assertSame([], $path->json('data.edges'));
    }

    // ── bad input is a 422 ────────────────────────────────────────────────────────────────────────────

    public function test_bad_input_is_a_422(): void
    {
        Gate::before(fn () => true);
        $this->actingAs(new GraphReadUser);
        $this->registerGraphResource();
        Particle::mount('graph-widgets')->ops(true)->register();

        // neighbours requires direction/depth/limit; an empty query cannot be validated.
        $this->getJson('/graph-widgets/1/graph/neighbours')->assertStatus(422);

        // and a direction outside the enum is a 422 too.
        $this->getJson('/graph-widgets/1/graph/neighbours?direction=sideways&depth=2&limit=5')->assertStatus(422);
    }

    // ── an over-limit request is the declared refusal, naming the limit ───────────────────────────────

    public function test_an_over_limit_request_is_the_declared_refusal_naming_the_limit(): void
    {
        Gate::before(fn () => true);
        $this->actingAs(new GraphReadUser);
        $this->registerGraphResource();
        Particle::mount('graph-widgets')->ops(true)->register();

        // depth 999 is past beam.core.graph.max_depth (10).
        $refused = $this->getJson('/graph-widgets/1/graph/neighbours?direction=descendants&depth=999&limit=5')
            ->assertStatus(422);

        $this->assertSame('over_limit', $refused->json('code'));
        $this->assertSame('depth', $refused->json('limit'));
        $this->assertSame(10, $refused->json('max'));
        $this->assertSame(999, $refused->json('requested'));

        // a node bound the scoped set exceeds is refused too, not truncated: 5 nodes, limit 2.
        $tooMany = $this->getJson('/graph-widgets/graph?depth=5&limit=2')->assertStatus(422);
        $this->assertSame('over_limit', $tooMany->json('code'));
        $this->assertSame('nodes', $tooMany->json('limit'));
        $this->assertSame(2, $tooMany->json('max'));
        $this->assertSame(5, $tooMany->json('requested'));
    }

    // ── negative depth / limit are rejected at validation (N7) ────────────────────────────────────────

    public function test_negative_depth_and_limit_are_rejected_422(): void
    {
        Gate::before(fn () => true);
        $this->actingAs(new GraphReadUser);
        $this->registerGraphResource();
        Particle::mount('graph-widgets')->ops(true)->register();

        $this->getJson('/graph-widgets/1/graph/neighbours?direction=descendants&depth=-1&limit=5')->assertStatus(422);
        $this->getJson('/graph-widgets/1/graph/neighbours?direction=descendants&depth=2&limit=0')->assertStatus(422);
        $this->getJson('/graph-widgets/graph?depth=5&limit=-3')->assertStatus(422);
        $this->getJson('/graph-widgets/graph/cycles?limit=-1')->assertStatus(422);
    }

    // ── the row scope: a record the actor cannot list is not a node (D5, N7) ──────────────────────────

    public function test_the_row_scope_narrows_what_is_a_node(): void
    {
        Gate::before(fn () => true);
        $this->actingAs(new GraphReadUser);
        $this->registerGraphResource();
        Particle::mount('graph-widgets')->ops(true)->register();

        // The `hidden` filter stands in for the actor's row scope: node 3 is out of scope.
        // A slice then does not contain it — it is not a node.
        $slice = $this->getJson('/graph-widgets/graph?depth=5&limit=100&filter[hidden][]=3')->assertOk();
        $ids = array_column($slice->json('data.nodes'), 'id');
        $this->assertNotContains('3', $ids);
        sort($ids);
        $this->assertSame(['1', '2', '4', '5'], $ids);

        // And the record itself, anchored, is a 404 — not a node in the actor's scoped graph.
        $this->getJson('/graph-widgets/3/graph/neighbours?direction=descendants&depth=5&limit=100&filter[hidden][]=3')
            ->assertNotFound();
    }

    // ── graph.path to an unknown target answers an empty path (N7) ────────────────────────────────────

    public function test_graph_path_to_an_unknown_target_answers_an_empty_path(): void
    {
        Gate::before(fn () => true);
        $this->actingAs(new GraphReadUser);
        $this->registerGraphResource();
        Particle::mount('graph-widgets')->ops(true)->register();

        // target 999 is not a node: the chosen answer is an empty path (no path), not a 404.
        $path = $this->getJson('/graph-widgets/1/graph/path?target=999')->assertOk();
        $this->assertSame([], $path->json('data.nodes'));
        $this->assertSame([], $path->json('data.edges'));
    }

    // ── the generated query round-trips into the input Data class (B1 r3) ─────────────────────────────

    public function test_a_generated_query_string_round_trips_into_the_input_data_class(): void
    {
        // The EXACT query strings the generated `beamQuery` produces (verified under node in
        // beamQuery.test.mjs), parsed the way Laravel parses a GET, then hydrated into the input Data class
        // the server validates — the round-trip the four graph reads actually make.
        parse_str('direction=descendants&depth=2&limit=5', $neighbours);
        $n = \Splicewire\Beam\Graph\Data\GraphNeighboursInput::from($neighbours);
        $this->assertSame(\Splicewire\Beam\Graph\GraphDirection::Descendants, $n->direction);
        $this->assertSame(2, $n->depth);
        $this->assertSame(5, $n->limit);

        parse_str('roots%5B%5D=1&roots%5B%5D=2&depth=2&limit=5', $slice);
        $s = \Splicewire\Beam\Graph\Data\GraphSliceInput::from($slice);
        $this->assertSame(['1', '2'], $s->roots);
        $this->assertSame(2, $s->depth);
        $this->assertSame(5, $s->limit);
    }

    // ── refusals: cycles node-ceiling + roots-required (N3) ───────────────────────────────────────────

    public function test_cycles_refuses_a_scoped_set_over_the_node_ceiling(): void
    {
        config(['beam.core.graph.max_nodes' => 3]); // the fixture graph has 5 nodes
        Gate::before(fn () => true);
        $this->actingAs(new GraphReadUser);
        $this->registerGraphResource();
        Particle::mount('graph-widgets')->ops(true)->register();

        $refused = $this->getJson('/graph-widgets/graph/cycles?limit=10')->assertStatus(422);
        $this->assertSame('over_limit', $refused->json('code'));
        $this->assertSame('nodes', $refused->json('limit'));
        $this->assertSame(3, $refused->json('max'));
        $this->assertSame(5, $refused->json('requested'));
    }

    public function test_the_slice_without_roots_declines_on_a_non_enumerable_store(): void
    {
        Gate::before(fn () => true);
        $this->actingAs(new GraphReadUser);
        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'ne-widgets',
            backing: Fixtures\NonEnumerableGraphBacking::class,
            readOnly: true,
        ));
        Particle::mount('ne-widgets')->ops(true)->register();

        // No roots + a store that cannot enumerate → the declared roots-required refusal.
        $refused = $this->getJson('/ne-widgets/graph?depth=5&limit=100')->assertStatus(422);
        $this->assertSame('roots_required', $refused->json('code'));
        $this->assertStringContainsString('roots', (string) $refused->json('message'));
    }

    // ── authorization: all four inherit the resource's read gate, fail-closed (D5, N7) ────────────────

    private const FOUR = [
        '/graph-widgets/graph?depth=5&limit=100',
        '/graph-widgets/graph/cycles?limit=10',
        '/graph-widgets/1/graph/neighbours?direction=descendants&depth=5&limit=100',
        '/graph-widgets/1/graph/path?target=5',
    ];

    public function test_all_four_are_forbidden_for_a_guest(): void
    {
        // No Gate::before, no actor: a guest. The fixture declares no Public Read, so the gate is
        // deny-default — exactly as a list read would be. A guest gets 403, never a record-revealing 404.
        $this->registerGraphResource();
        Particle::mount('graph-widgets')->ops(true)->register();

        foreach (self::FOUR as $uri) {
            $this->getJson($uri)->assertForbidden();
        }
    }

    public function test_all_four_are_forbidden_for_a_refused_signed_in_user(): void
    {
        // A signed-in reader whom the resource's gate refuses (no policy, no scope, no Gate::before):
        // deny-default, 403 — and for the record ops, 403 rather than a 404 that reveals the record exists.
        $this->actingAs(new GraphReadUser);
        $this->registerGraphResource();
        Particle::mount('graph-widgets')->ops(true)->register();

        foreach (self::FOUR as $uri) {
            $this->getJson($uri)->assertForbidden();
        }
    }

    // ── derivation never displaces a host's own op at a graph key (N5) ────────────────────────────────

    public function test_derivation_does_not_displace_a_host_registered_op_key(): void
    {
        // A host registered its OWN `graph.path` first (a different registrant).
        $host = new ParticleOperation(
            resource: 'graph-widgets',
            name: 'graph.path',
            kind: \Splicewire\Beam\Particle\OperationKind::Read,
            handle: fn () => ['data' => ['host' => true]],
        );
        $this->app->make(ParticleOperationRegistry::class)->register($host, by: 'acme/host');

        // Registering the graph resource derives the other three but LEAVES the host's graph.path.
        $this->registerGraphResource();

        $operations = $this->app->make(ParticleOperationRegistry::class);
        $this->assertSame($host, $operations->get('graph-widgets', 'graph.path'), 'the host op survives');
        // The other three are still derived.
        foreach (['graph', 'graph.cycles', 'graph.neighbours'] as $name) {
            $this->assertNotNull($operations->find('graph-widgets', $name), "{$name} is still derived");
        }
    }

    // ── a resource without the capability has none of the four — in the ROUTE LIST ────────────────────

    public function test_a_resource_without_the_capability_routes_none_of_the_four(): void
    {
        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'plain-widgets',
            backing: PlainWidget::class,
            readOnly: true,
        ));

        Particle::mount('plain-widgets')->ops(true)->register();

        foreach (['plain-widgets.graph', 'plain-widgets.graph.cycles', 'plain-widgets.graph.neighbours', 'plain-widgets.graph.path'] as $name) {
            $this->assertNull($this->uriOf($name), "{$name} should not be routed");
        }
    }

    // ── with `->ops()` absent from the mount, none of the four is routed ──────────────────────────────

    public function test_with_ops_absent_from_the_mount_none_of_the_four_is_routed(): void
    {
        $this->registerGraphResource();

        // The derivation stands — the ops ARE in the registry …
        $this->assertCount(4, $this->app->make(ParticleOperationRegistry::class)->forResource('graph-widgets'));

        // … but a mount that never writes ->ops() routes none of them (D6).
        Particle::mount('graph-widgets')->register();

        foreach (['graph-widgets.graph', 'graph-widgets.graph.cycles', 'graph-widgets.graph.neighbours', 'graph-widgets.graph.path'] as $name) {
            $this->assertNull($this->uriOf($name), "{$name} should not be routed without ->ops()");
        }
    }
}
