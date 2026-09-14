<?php

namespace Splicewire\Beam\Summary;

use Schemastud\Frame\Contracts\ResourceSummaryProvider;
use Schemastud\Frame\Data\SummaryFigureData;
use Schemastud\Frame\Data\SummaryResponseData;
use Schemastud\Frame\Registry\ResourceDefinition;
use Splicewire\Beam\Particle\ScopedIndexQuery;

/**
 * Beam's default answer to frame's {@see ResourceSummaryProvider} — the provider every `#[ParticleResource]`
 * projects unless it declares its own `summaryProvider:`.
 *
 * One figure, `total`, counted through {@see ScopedIndexQuery} — the SAME scoped builder the Frame index
 * reads — so the figure a tenant-realm tile shows equals that actor's index total, never the table's.
 * A resource whose backing cannot compose a builder (streams-only, or model-less with no declaration)
 * DECLINES with null, and frame answers 404: an honest absence rather than an invented zero.
 *
 * The display word comes from {@see ResourceDefinition::resolvedLabel()}, never from `$nav->label` bare:
 * a resource that declares no nav label would otherwise tile as an empty string, and the fallback belongs
 * to the definition that owns the label rather than to each producer that renders one.
 */
class BeamResourceSummaryProvider implements ResourceSummaryProvider
{
    public function __construct(private ScopedIndexQuery $query) {}

    public function summary(ResourceDefinition $resource): ?SummaryResponseData
    {
        if (! $this->query->queryable($resource)) {
            return null;
        }

        $label = $resource->resolvedLabel();

        return new SummaryResponseData(
            key: $resource->key,
            label: $label,
            icon: $resource->nav->icon,
            figures: [
                new SummaryFigureData(key: 'total', label: $label, value: $this->query->forDefinition($resource)->count()),
            ],
            overview: null,
        );
    }
}
