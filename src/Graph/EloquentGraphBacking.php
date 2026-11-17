<?php

namespace Splicewire\Beam\Graph;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use LogicException;
use Rushing\Graphine\Contracts\GraphStore;
use Rushing\Graphine\Drivers\InMemoryDriver;
use Rushing\Graphine\Dto\Node;
use Rushing\Graphine\Dto\NodeId;
use Splicewire\Beam\Graph\Edges\GraphEdges;
use Splicewire\Beam\Particle\Backing\BindsResource;
use Splicewire\Beam\Particle\Backing\EloquentBacking;
use Splicewire\Beam\Particle\Backing\GraphsRecords;
use Splicewire\Beam\Particle\ParticleListQuery;
use Splicewire\Beam\Particle\ParticleResource;

/**
 * The {@see GraphsRecords} port beam supplies for a model-backed resource that declares a `graph:` slot
 * (graph-operations 04, D2). No backing class is written: {@see ParticleResource::backing()} wraps the
 * resource's model in this when the slot is set.
 *
 * It is an {@see EloquentBacking} in every other respect, so the resource keeps exactly the capabilities a
 * plain model-backed resource has, and gains one.
 *
 * ## The store is the list (D5)
 *
 * The nodes are the rows {@see ParticleListQuery::forList()} returns for this resource and request: the
 * backing's query, then the declared `scope`, then the guest's `publicScope`, then the request's filter
 * selection. That is the same composition the list read uses, so a row the actor cannot list is not a node,
 * and since a {@see GraphEdges} declaration only keeps edges whose both ends are nodes, no path runs through
 * it. The read gate itself is the handler's ({@see GraphOperationHandler}), applied before this is asked.
 *
 * ## The ceiling (D5)
 *
 * The scoped rows are COUNTED before one is loaded. Over `beam.core.graph.max_nodes` the read is refused
 * with {@see GraphScopeTooLarge}, ticket 02's `over_limit` refusal naming `nodes`. It is never truncated.
 *
 * A node is a reference (D4): its id is the row key, its type the resource key, its label the declaration's
 * label column (else the id), and it names this resource.
 */
final class EloquentGraphBacking extends EloquentBacking implements BindsResource, GraphsRecords
{
    /**
     * @param  class-string<Model>  $model
     */
    public function __construct(
        string $model,
        protected GraphEdges $edges,
        protected ?ParticleResource $resource = null,
        protected ?Request $request = null,
    ) {
        parent::__construct($model);
    }

    public function forResource(ParticleResource $resource, Request $request): static
    {
        return new self($this->model, $this->edges, $resource, $request);
    }

    public function edges(): GraphEdges
    {
        return $this->edges;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function graph(array $filters): GraphStore
    {
        if ($this->resource === null) {
            // ParticleResource::backing() always binds it; an unbound instance has no list to read.
            throw new LogicException('An EloquentGraphBacking reads its resource\'s list; resolve it through ParticleResource::backing().');
        }

        $query = app(ParticleListQuery::class)->forList($this->resource, $filters, $this->request ?? app(Request::class));
        // The list's eager loads are for its projection; a node needs only the key and the declared columns.
        $query->setEagerLoads([]);

        $max = (int) config('beam.core.graph.max_nodes', 1000);
        $count = (clone $query)->toBase()->getCountForPagination();

        if ($count > $max) {
            throw new GraphScopeTooLarge($max, $count);
        }

        $model = $query->getModel();
        $columns = array_values(array_unique(array_map(
            fn (string $column): string => $model->qualifyColumn($column),
            [$model->getKeyName(), ...$this->edges->nodeColumns()],
        )));
        $rows = $query->get($columns);

        $label = $this->edges->labelColumn();
        $ids = [];
        $store = new InMemoryDriver;

        foreach ($rows as $row) {
            $id = (string) $row->getKey();
            $ids[$id] = $row->getKey();
            $text = $label !== null ? $row->getAttribute($label) : null;

            $store->putNode(new Node(new NodeId($id), $this->resource->key, [
                'resource' => $this->resource->key,
                'label' => $text !== null && (string) $text !== '' ? (string) $text : $id,
            ]));
        }

        foreach ($this->edges->edges($model, $rows, $ids) as $edge) {
            $store->putEdge($edge);
        }

        return $store;
    }
}
