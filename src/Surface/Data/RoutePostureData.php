<?php

namespace Splicewire\Beam\Surface\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Surface\PostureFacet;

/**
 * One live route's **security posture** as the running application actually presents it.
 *
 * The counterpart of {@see ResourceSeamData}: that class is what the document *claims*, this is what
 * the router *does*. Neither is authoritative on its own — the value is in the diff.
 *
 * `facets` holds only the facets the projector could determine. Read it through {@see facet()}, which
 * returns `null` for an absent key, and treat that null as a gap: it is not `false`.
 */
#[TypeScript]
class RoutePostureData extends BeamData
{
    /**
     * @param  array<string, bool>  $facets  determinable facets only, keyed by {@see PostureFacet} value
     * @param  list<string>  $middleware  the resolved middleware stack, so a finding is a work-list entry
     */
    public function __construct(
        public string $signature,
        public string $path,
        public string $method,
        public ?string $name = null,
        public array $facets = [],
        public ?string $resourceKey = null,
        public ?string $operationName = null,
        public ?string $ability = null,
        public array $middleware = [],
    ) {}

    /** True / false when the router answered; **null when the facet was undeterminable** — a gap. */
    public function facet(PostureFacet $facet): ?bool
    {
        return $this->facets[$facet->value] ?? null;
    }

    /** @return list<PostureFacet> */
    public function undeterminedFacets(): array
    {
        return array_values(array_filter(
            PostureFacet::cases(),
            fn (PostureFacet $facet) => ! array_key_exists($facet->value, $this->facets),
        ));
    }
}
