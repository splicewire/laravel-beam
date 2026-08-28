<?php

namespace Splicewire\Beam\Filters\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Splicewire\Beam\Data\BeamData;

/**
 * The `GET /{resource}/filters/variants` response — every addressable filter vocabulary for one
 * resource.
 *
 * Non-optional by ticket 10 §4: the variant segment is an address space, and an address space nobody
 * can enumerate is not a surface.
 */
class ResourceFilterVariantsData extends BeamData
{
    /**
     * @param  list<ResourceFilterVariantData>  $variants
     */
    public function __construct(
        #[Description('The canonical resource key — the `{resource}` path segment these variants hang off.')]
        public string $resource,

        #[Description('Every registry key whose declaration names this resource, canonical one included.')]
        public array $variants,
    ) {}
}
