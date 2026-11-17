<?php

namespace Splicewire\Beam\Graph;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Rushing\Graphine\Contracts\ComputeStore;
use Rushing\Graphine\Contracts\EdgeContract;
use Rushing\Graphine\Contracts\EnumerableStore;
use Rushing\Graphine\Contracts\GraphStore;
use Rushing\Graphine\Contracts\NodeContract;
use Rushing\Graphine\Contracts\PathContract;
use Rushing\Graphine\Contracts\StructureStore;
use Rushing\Graphine\Dto\NodeId;
use Rushing\Graphine\Enums\Capability;
use Rushing\Graphine\Enums\TraversalDirection;
use Splicewire\Beam\Authorization\ResourceReadGuard;
use Splicewire\Beam\Graph\Data\GraphCyclesData;
use Splicewire\Beam\Graph\Data\GraphCyclesInput;
use Splicewire\Beam\Graph\Data\GraphEdgeData;
use Splicewire\Beam\Graph\Data\GraphNeighboursInput;
use Splicewire\Beam\Graph\Data\GraphNodeData;
use Splicewire\Beam\Graph\Data\GraphPathData;
use Splicewire\Beam\Graph\Data\GraphPathInput;
use Splicewire\Beam\Graph\Data\GraphRefusalData;
use Splicewire\Beam\Graph\Data\GraphSliceData;
use Splicewire\Beam\Graph\Data\GraphSliceInput;
use Splicewire\Beam\Localization\CodedValidationException;
use Splicewire\Beam\Particle\Backing\GraphsRecords;
use Splicewire\Beam\Particle\OperationCall;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * Runs the four derived graph read operations (graph-operations 02). One request-resolved object behind
 * the four handler closures {@see GraphOperations} registers, so the closures capture only a resource
 * KEY (a string) and the real work — the read gate, the scoped store, the limits, the wire mapping —
 * lives here where it can take the container.
 *
 * Every handler does the same four things in the same order:
 *
 *   1. **Inherit the resource's read gate, FAIL-CLOSED** (D5, review N4). The list read's gate sequence —
 *      the declared read ability, the guest gate, then the read binding — but through
 *      {@see ResourceReadGuard::inspectRead()}, which is STRICTER than the list's
 *      {@see ResourceReadGuard::bindReadContext()} public-read floor: a resource a guest may LIST through
 *      a declared Public Read still refuses the four graph ops, because a graph read exposes topology the
 *      Public Read never narrowed. This is deliberate and fail-closed, and it is the handler's FIRST
 *      action. Beam's operation controller validates `input:` BEFORE the handler runs
 *      ({@see \Splicewire\Beam\Http\Particle\ParticleOperationController::invoke()}), so a guest sending
 *      MALFORMED input gets a 422 first — but a 422 about the input shape reveals nothing about a record.
 *      What the gate protects is record EXISTENCE: the record-anchored ops take their `{id}` from
 *      {@see \Splicewire\Beam\Graph\GraphRecordAnchor}, which does NOT look a record up, so an unauthorized
 *      actor with WELL-FORMED input is refused 403 here rather than a 404 that would reveal whether the
 *      record exists; the row-scope decision is the scoped store's {@see StructureStore::getNode()}, below.
 *      (The controller's validate-before-authorize order is shared code and out of scope to reorder.)
 *   2. **Build the store from `graph($filters)`** under the request's own `filter[...]` bag — the SAME
 *      opaque bag {@see \Splicewire\Beam\Particle\Backing\StreamsRecords::records()} takes. The backing
 *      owns the scoping, so the store is already narrowed to the rows the actor may read: a record the
 *      actor cannot list is not a node, and no path runs through it. The handler NEVER re-queries or
 *      widens the store it was handed.
 *   3. **Branch on {@see GraphStore::supports()}**, never on a concrete driver class. A store that cannot
 *      enumerate declines the anchorless slice; a store that cannot compute declines cycles/path.
 *   4. **Hold the config ceiling** ({@see \Splicewire\Beam\Graph} `beam.core.graph`). Depth and node
 *      count are capped; a request past a cap, or a scoped set too large to answer within the asked
 *      bound, is REFUSED with {@see GraphRefusalData} naming the limit — never truncated silently (D5).
 */
class GraphOperationHandler
{
    /** `graph.neighbours` — from a record, walk `direction` out to `depth`, at most `limit` nodes. */
    public function neighbours(string $resourceKey, OperationCall $call): mixed
    {
        /** @var GraphNeighboursInput $input */
        $input = $call->input;
        $request = $call->context->request;

        $store = $this->authorizedStore($resourceKey, $request);

        if ($refusal = $this->depthAndLimitCeiling($input->depth, $input->limit)) {
            return $refusal;
        }

        if (! $store instanceof StructureStore || ! $store->supports(Capability::Declare)) {
            // The mandatory spine; a backing whose store cannot declare topology cannot answer neighbours.
            throw CodedValidationException::for('graph', GraphOperationError::StoreCannotReadNeighbours);
        }

        $anchorId = (string) $request->route('id');
        $anchor = $store->getNode(new NodeId($anchorId));

        if ($anchor === null) {
            // The record is not a node in the actor's scoped graph — the row-scope gate, in node terms.
            abort(404);
        }

        $nodes = $this->keyById(array_merge(
            [$anchor],
            $store->neighbours(new NodeId($anchorId), $input->direction->toTraversalDirection(), $input->depth),
        ));

        if ($refusal = $this->nodeCountCeiling($nodes, $input->limit)) {
            return $refusal;
        }

        return $this->sliceData($nodes, $store, $resourceKey);
    }

    /** `graph.path` — the shortest path from the source record to the `target` id. */
    public function path(string $resourceKey, OperationCall $call): mixed
    {
        /** @var GraphPathInput $input */
        $input = $call->input;
        $request = $call->context->request;

        $store = $this->authorizedStore($resourceKey, $request);

        if (! $store instanceof ComputeStore || ! $store->supports(Capability::Compute)) {
            throw CodedValidationException::for('graph', GraphOperationError::StoreCannotComputePath);
        }

        $fromId = (string) $request->route('id');

        if ($store instanceof StructureStore && $store->getNode(new NodeId($fromId)) === null) {
            abort(404);
        }

        /** @var PathContract|null $path */
        $path = $store->shortestPath(new NodeId($fromId), new NodeId($input->target));

        if ($path === null) {
            // "An empty `nodes` is no path." (GraphPathData)
            return new GraphPathData(nodes: [], edges: []);
        }

        if (count($path->nodes()) > $this->maxNodes()) {
            return $this->refuse(GraphRefusalData::overLimit('nodes', $this->maxNodes(), count($path->nodes())));
        }

        $walk = $this->resolveWalk($path, $store, $resourceKey);

        return new GraphPathData(nodes: $walk['nodes'], edges: $walk['edges']);
    }

    /** `graph` — a bounded slice, optionally from `roots`; the whole scoped graph within the bound otherwise. */
    public function slice(string $resourceKey, OperationCall $call): mixed
    {
        /** @var GraphSliceInput $input */
        $input = $call->input;
        $request = $call->context->request;

        $store = $this->authorizedStore($resourceKey, $request);

        if ($refusal = $this->depthAndLimitCeiling($input->depth, $input->limit)) {
            return $refusal;
        }

        if ($input->roots === null) {
            // Anchorless: the whole bounded snapshot. A store that cannot enumerate declines it (D5 /
            // scope 2): without an anchor there is no `neighbours()` to walk from.
            if (! $store instanceof EnumerableStore || ! $store->supports(Capability::Enumerate)) {
                return $this->refuse(GraphRefusalData::rootsRequired());
            }

            $nodes = $this->keyById($store->nodes());

            if ($refusal = $this->nodeCountCeiling($nodes, $input->limit)) {
                return $refusal;
            }

            return new GraphSliceData(
                nodes: $this->nodeData($nodes, $resourceKey),
                edges: $this->edgeData($store->edges(), $nodes),
            );
        }

        if (! $store instanceof StructureStore || ! $store->supports(Capability::Declare)) {
            throw CodedValidationException::for('graph', GraphOperationError::StoreCannotReadSlice);
        }

        // From the named roots, the region reachable within depth (Descendants: the sub-graph under the
        // roots). Each root is itself in the slice.
        $collected = [];

        foreach ($input->roots as $root) {
            $rootNode = $store->getNode(new NodeId((string) $root));

            if ($rootNode !== null) {
                $collected[] = $rootNode;
            }

            foreach ($store->neighbours(new NodeId((string) $root), TraversalDirection::Descendants, $input->depth) as $node) {
                $collected[] = $node;
            }
        }

        $nodes = $this->keyById($collected);

        if ($refusal = $this->nodeCountCeiling($nodes, $input->limit)) {
            return $refusal;
        }

        return $this->sliceData($nodes, $store, $resourceKey);
    }

    /** `graph.cycles` — at most `limit` cycles in the scoped graph; empty when acyclic. */
    public function cycles(string $resourceKey, OperationCall $call): mixed
    {
        /** @var GraphCyclesInput $input */
        $input = $call->input;
        $request = $call->context->request;

        $store = $this->authorizedStore($resourceKey, $request);

        if (! $store instanceof ComputeStore || ! $store->supports(Capability::Compute)) {
            throw CodedValidationException::for('graph', GraphOperationError::StoreCannotDetectCycles);
        }

        // Cycle detection reads the whole scoped store, so it carries the same node ceiling the slice does
        // (D5, review N3): a scoped set too large to load is REFUSED (naming `nodes`), not quietly walked.
        // `limit` here counts CYCLES, not nodes, so it is NOT compared to max_nodes.
        if ($refusal = $this->scopedNodeCeiling($store)) {
            return $refusal;
        }

        // The input asks for "at most `limit` cycles", so taking the first `limit` HONOURS the request
        // rather than truncating an answer the caller wanted whole — the slice/neighbours node bound,
        // which the caller wants whole, refuses instead.
        $cycles = array_slice($store->detectCycles(), 0, max(0, $input->limit));

        return new GraphCyclesData(cycles: array_values(array_map(
            function (PathContract $cycle) use ($store, $resourceKey): GraphPathData {
                $walk = $this->resolveWalk($cycle, $store, $resourceKey);

                return new GraphPathData(nodes: $walk['nodes'], edges: $walk['edges']);
            },
            $cycles,
        )));
    }

    // ── gate + store ────────────────────────────────────────────────────────────────────────────────

    /**
     * Apply the resource's own read gate, then return its {@see GraphStore} scoped to the actor through
     * `graph($filters)`. The gate is the list read's exact sequence, so a graph read inherits it rather
     * than inventing a parallel one (D5).
     */
    private function authorizedStore(string $resourceKey, Request $request): GraphStore
    {
        $resource = app(ParticleResourceRegistry::class)->get($resourceKey);

        $guard = ResourceReadGuard::forApp();
        $guard->inspectDeclaredAbility($resource, Gate::forUser($request->user()), $request)->authorize();
        $guard->inspectGuestReadFor($resource, $request->user())->authorize();
        $guard->inspectRead($resource, $request)->authorize();

        $backing = $resource->backing($request);

        if (! $backing instanceof GraphsRecords) {
            // Unreachable through the mount — derivation only registers these on a GraphsRecords backing —
            // but asserted rather than assumed, since the backing resolves per request.
            throw CodedValidationException::for('graph', GraphOperationError::ResourceDoesNotBackGraph, ['resource' => $resourceKey]);
        }

        return $backing->graph((array) $request->input('filter', []));
    }

    // ── limits ──────────────────────────────────────────────────────────────────────────────────────

    private function maxDepth(): int
    {
        return (int) config('beam.core.graph.max_depth', 10);
    }

    private function maxNodes(): int
    {
        return (int) config('beam.core.graph.max_nodes', 1000);
    }

    /** The request asked past the depth or node-count ceiling before any traversal runs. */
    private function depthAndLimitCeiling(int $depth, int $limit): mixed
    {
        if ($depth > $this->maxDepth()) {
            return $this->refuse(GraphRefusalData::overLimit('depth', $this->maxDepth(), $depth));
        }

        if ($limit > $this->maxNodes()) {
            return $this->refuse(GraphRefusalData::overLimit('nodes', $this->maxNodes(), $limit));
        }

        return null;
    }

    /**
     * The built node set exceeds the asked bound — the scoped set is too large to answer within it, so it
     * is refused, not trimmed (D5).
     *
     * @param  array<string, NodeContract>  $nodes
     */
    private function nodeCountCeiling(array $nodes, int $limit): mixed
    {
        $count = count($nodes);

        if ($count > $limit) {
            return $this->refuse(GraphRefusalData::overLimit('nodes', $limit, $count));
        }

        if ($count > $this->maxNodes()) {
            return $this->refuse(GraphRefusalData::overLimit('nodes', $this->maxNodes(), $count));
        }

        return null;
    }

    /**
     * Refuse an anchorless whole-store read (cycles) over a scoped set too large to load (D5, review N3).
     * Only an enumerable store can be sized before the walk; a non-enumerable one cannot, so it runs (its
     * own anchorless operations having already been declined elsewhere).
     */
    private function scopedNodeCeiling(GraphStore $store): mixed
    {
        if (! $store instanceof EnumerableStore || ! $store->supports(Capability::Enumerate)) {
            return null;
        }

        $count = count($store->nodes());

        return $count > $this->maxNodes()
            ? $this->refuse(GraphRefusalData::overLimit('nodes', $this->maxNodes(), $count))
            : null;
    }

    /** The declared refusal, written as the bare body at 422 (the handler owns its response; `errors:` publishes the shape). */
    private function refuse(GraphRefusalData $refusal): mixed
    {
        return response()->json($refusal->toArray(), 422);
    }

    // ── wire mapping ──────────────────────────────────────────────────────────────────────────────────

    /**
     * A {@see GraphSliceData} over a node set, with the edges among those nodes when the store can
     * enumerate them (else none — a non-enumerable store answers the nodes without an edge dump).
     *
     * @param  array<string, NodeContract>  $nodes
     */
    private function sliceData(array $nodes, GraphStore $store, string $resourceKey): GraphSliceData
    {
        return new GraphSliceData(
            nodes: $this->nodeData($nodes, $resourceKey),
            edges: $this->edgeData($this->enumerableEdges($store), $nodes),
        );
    }

    /**
     * Resolve a path/cycle's ordered node-id walk into reference nodes and the edges between consecutive
     * nodes.
     *
     * @return array{nodes: list<GraphNodeData>, edges: list<GraphEdgeData>}
     */
    private function resolveWalk(PathContract $path, GraphStore $store, string $resourceKey): array
    {
        $edgeIndex = $this->edgeIndex($this->enumerableEdges($store));

        $nodes = [];
        $edges = [];
        $previous = null;

        foreach ($path->nodes() as $id) {
            $value = (string) $id->value();
            $node = $store instanceof StructureStore ? $store->getNode($id) : null;

            $nodes[] = $node !== null
                ? $this->node($node, $resourceKey)
                : new GraphNodeData(id: $value, type: '', resource: $resourceKey, label: $value);

            if ($previous !== null) {
                $edges[] = $edgeIndex["{$previous}\0{$value}"]
                    ?? new GraphEdgeData(from: $previous, to: $value, type: '', weight: null);
            }

            $previous = $value;
        }

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    /**
     * @param  array<string, NodeContract>  $nodes
     * @return list<GraphNodeData>
     */
    private function nodeData(array $nodes, string $resourceKey): array
    {
        return array_map(fn (NodeContract $node): GraphNodeData => $this->node($node, $resourceKey), array_values($nodes));
    }

    private function node(NodeContract $node, string $resourceKey): GraphNodeData
    {
        $properties = $node->properties();

        return new GraphNodeData(
            id: (string) $node->id()->value(),
            type: $node->type(),
            // A node names the beam resource it belongs to (D4); its own if it does not say otherwise,
            // which is what a cross-resource edge target carries.
            resource: isset($properties['resource']) ? (string) $properties['resource'] : $resourceKey,
            label: isset($properties['label']) ? (string) $properties['label'] : (string) $node->id()->value(),
        );
    }

    /**
     * The edges among a node set, as reference edges (D4). Only edges whose BOTH endpoints are in the set.
     *
     * @param  iterable<EdgeContract>  $edges
     * @param  array<string, NodeContract>  $nodes
     * @return list<GraphEdgeData>
     */
    private function edgeData(iterable $edges, array $nodes): array
    {
        $out = [];

        foreach ($edges as $edge) {
            $from = (string) $edge->from()->value();
            $to = (string) $edge->to()->value();

            if (isset($nodes[$from], $nodes[$to])) {
                $out[] = new GraphEdgeData(from: $from, to: $to, type: $edge->type(), weight: $edge->weight());
            }
        }

        return $out;
    }

    /**
     * @param  iterable<EdgeContract>  $edges
     * @return array<string, GraphEdgeData> keyed `from\0to`
     */
    private function edgeIndex(iterable $edges): array
    {
        $index = [];

        foreach ($edges as $edge) {
            $from = (string) $edge->from()->value();
            $to = (string) $edge->to()->value();
            $index["{$from}\0{$to}"] = new GraphEdgeData(from: $from, to: $to, type: $edge->type(), weight: $edge->weight());
        }

        return $index;
    }

    /**
     * The store's edges when it can enumerate them, else none — branched on {@see GraphStore::supports()}
     * and the role type, never a concrete driver.
     *
     * @return list<EdgeContract>
     */
    private function enumerableEdges(GraphStore $store): array
    {
        return $store instanceof EnumerableStore && $store->supports(Capability::Enumerate) ? $store->edges() : [];
    }

    /**
     * @param  list<NodeContract>  $nodes
     * @return array<string, NodeContract> deduped by id, first wins
     */
    private function keyById(array $nodes): array
    {
        $map = [];

        foreach ($nodes as $node) {
            $id = (string) $node->id()->value();
            $map[$id] ??= $node;
        }

        return $map;
    }
}
