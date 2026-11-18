<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Graph\Edges\ParentEdge;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\Graph\Fixtures\GraphReadUser;
use Splicewire\Beam\Tests\Graph\Fixtures\GraphTreeNode;

// A tree by a parent column: 1 Root → 2 Branch → 3 Leaf, and 1 → 4 Other. Row 5 hangs under 1 but is private.
Schema::create('graph_tree_nodes', function (Blueprint $table): void {
    $table->id();
    $table->unsignedBigInteger('parent_id')->nullable();
    $table->string('name')->nullable();
    $table->string('team');
});
DB::table('graph_tree_nodes')->insert([
    ['id' => 1, 'parent_id' => null, 'name' => 'Root', 'team' => 'public'],
    ['id' => 2, 'parent_id' => 1, 'name' => 'Branch', 'team' => 'public'],
    ['id' => 3, 'parent_id' => 2, 'name' => 'Leaf', 'team' => 'public'],
    ['id' => 4, 'parent_id' => 1, 'name' => null, 'team' => 'public'],
    ['id' => 5, 'parent_id' => 1, 'name' => 'Hidden', 'team' => 'private'],
]);

// One slot on a model-backed resource: beam supplies the graph and derives the four read operations.
app(ParticleResourceRegistry::class)->register(new ParticleResource(
    key: 'regions',
    backing: GraphTreeNode::class,
    readOnly: true,
    scope: fn (Builder $query): Builder => $query->where('team', 'public'),
    graph: new ParentEdge('parent_id', label: 'name'),
));

// The host asks for them: nothing is routed until `->ops()`.
Particle::mount('regions')->ops(true)->register();

// An authenticated reader the resource's read gate admits. The four gate fail-closed: Public Read does not open them.
Gate::before(fn () => true);
auth()->setUser(new GraphReadUser);

$get = function (string $uri): array {
    $response = app()->handle(Request::create($uri, 'GET', server: ['HTTP_ACCEPT' => 'application/json']));

    return ['status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getContent(), true)];
};

return [
    'GET /regions/graph?depth=5&limit=100' => $get('/regions/graph?depth=5&limit=100'),
    'GET /regions/1/graph/neighbours?direction=descendants&depth=1&limit=100' => $get('/regions/1/graph/neighbours?direction=descendants&depth=1&limit=100'),
    'GET /regions/1/graph/path?target=3' => $get('/regions/1/graph/path?target=3'),
    'GET /regions/3/graph/path?target=1' => $get('/regions/3/graph/path?target=1'),
    'GET /regions/graph/cycles?limit=10' => $get('/regions/graph/cycles?limit=10'),
    'GET /regions/5/graph/neighbours?direction=descendants&depth=1&limit=100' => $get('/regions/5/graph/neighbours?direction=descendants&depth=1&limit=100'),
    'GET /regions/1/graph/path?target=5' => $get('/regions/1/graph/path?target=5'),
    'GET /regions/1/graph/neighbours?direction=descendants&depth=99&limit=100' => $get('/regions/1/graph/neighbours?direction=descendants&depth=99&limit=100'),
];
