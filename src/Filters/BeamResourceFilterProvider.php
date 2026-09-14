<?php

namespace Splicewire\Beam\Filters;

use Schemastud\Frame\Contracts\ResourceFilterProvider;
use Schemastud\Frame\Data\FilterOptionsResponseData;
use Schemastud\Frame\Data\FilterSchemaResponseData;
use Schemastud\Frame\Data\FilterVariantsResponseData;
use Schemastud\Frame\Registry\ResourceDefinition;

class BeamResourceFilterProvider implements ResourceFilterProvider
{
    public function __construct(private ResourceFilters $filters) {}

    public function schema(ResourceDefinition $resource, ?string $variant = null): FilterSchemaResponseData
    {
        return $this->filters->schema($resource->key, $variant);
    }

    public function options(ResourceDefinition $resource, string $ref, ?string $search = null): FilterOptionsResponseData
    {
        return $this->filters->options($resource->key, $ref, $search);
    }

    public function variants(ResourceDefinition $resource): FilterVariantsResponseData
    {
        return $this->filters->variants($resource->key);
    }
}
