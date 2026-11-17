<?php

namespace Splicewire\Beam\Graph\Data;

use Spatie\LaravelData\Data;

/**
 * One edge on the wire — a reference between two node ids (D4, ruled 2026-11-12).
 *
 * `{ from, to, type, weight }`: the `id` of each endpoint, the edge `type`, and an optional numeric
 * `weight` (null for an unweighted edge). Like {@see GraphNodeData} it carries no payload bag.
 */
class GraphEdgeData extends Data
{
    public function __construct(
        public string $from,
        public string $to,
        public string $type,
        public ?float $weight = null,
    ) {}
}
