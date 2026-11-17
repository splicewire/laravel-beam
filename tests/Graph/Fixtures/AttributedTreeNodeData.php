<?php

namespace Splicewire\Beam\Tests\Graph\Fixtures;

use Spatie\LaravelData\Data;
use Splicewire\Beam\Graph\Edges\ParentEdge;
use Splicewire\Beam\Particle\Attributes\ParticleResource;

/** The attribute spelling of the `graph:` slot (graph-operations 04, scope 1): a `new` expression as an attribute argument. */
#[ParticleResource(
    key: 'attributed-tree-nodes',
    backing: GraphTreeNode::class,
    input: false,
    readOnly: true,
    graph: new ParentEdge('parent_id', label: 'name'),
)]
class AttributedTreeNodeData extends Data
{
    public function __construct(public int $id) {}
}
