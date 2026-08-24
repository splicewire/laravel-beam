<?php

namespace Splicewire\Beam\Rendering\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\Data;

/**
 * What one resource offers by way of renderings — the body of `GET {resource}/renderings`
 * (api-surface-coherence ticket 09 §7, build 33).
 *
 * Deliberately NOT a projection of `ManifestIndex`, which 09 §7 ruled out permanently and which could
 * not have served this anyway: a `ManifestDescriptor` carries no entries and no handle back to its
 * registry, so it knows the string `"ResourceRenderingRegistry"` without knowing that circuits has a
 * rendering called `export`. This reads the registry's ENTRIES, exactly as the six sibling discovery
 * endpoints read theirs.
 *
 * `renderings` may legitimately be empty. A resource whose route file mounts the macro but which has
 * declared no rendering answers with an empty list, because absence of renderings is not absence of
 * resource — and a resource with no rendering surface at all has no route here to answer with, which is
 * the 404.
 */
#[TypeScript]
class ResourceRenderingCatalogData extends Data
{
    /**
     * @param  list<RenderingDescriptorData>  $renderings
     */
    public function __construct(
        #[Description('The registry key this catalog describes — the resource token the route was mounted for, not the URL segment it is reached at. The two legitimately diverge.')]
        public string $resource,

        #[DataCollectionOf(RenderingDescriptorData::class)]
        #[Description('Every rendering mounted for this resource, in registration order. Empty when the resource declares none.')]
        public array $renderings,
    ) {}
}
