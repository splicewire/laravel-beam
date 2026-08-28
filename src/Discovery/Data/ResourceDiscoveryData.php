<?php

namespace Splicewire\Beam\Discovery\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * The body of `GET /{resource}/discovery` — one mount's reach, for the caller who asked
 * (api-surface-coherence 105, decided by 41 D1/D5/D6).
 *
 * ## It answers a question the reference cannot
 *
 * The OpenAPI reference is a BUILD-TIME artifact: it documents that a route exists, for everyone, once.
 * This is the runtime twin, and the only thing it adds is the one thing the reference structurally
 * cannot know — what THIS caller may reach. That is why the entries are gated per route rather than
 * aggregated, and why the same URL answers differently for two callers.
 *
 * ## `resource` is a stamp, not a registration
 *
 * A listing exists wherever a stamped route exists, which is deliberately NOT the same population as
 * the declared `#[ParticleResource]` registry. On the flagship, four keys with live routes are not
 * registered at all and thirteen registered keys have no route. A reader who assumes the two sets
 * coincide will be wrong in both directions.
 */
#[TypeScript]
class ResourceDiscoveryData extends BeamData
{
    /**
     * @param  list<ResourceDiscoveryEntryData>  $entries
     * @param  list<string>  $subSurfaces
     */
    public function __construct(
        #[Description('The `_particle` stamp every route in this listing carries. Not necessarily a registered resource — the listing follows routes, not registrations.')]
        public string $resource,

        #[Description('The URI prefix this listing covers — `api/v1/hooks`. A resource mounted twice publishes two listings, one per mount, each reporting only its own reach.')]
        public string $mount,

        #[DataCollectionOf(ResourceDiscoveryEntryData::class)]
        #[Description(
            'Every route on this mount the CALLER can reach, gated by each route\'s own middleware — never a union or '
            .'an intersection of the sub-surfaces\' abilities. ⚠️ An entry publishes an operation\'s EXISTENCE. A route '
            .'that authorizes against a specific record (`/circuits/{id}/op/run`) cannot be judged from a listing that '
            .'has no `{id}`, so it is listed and the invoke may still answer 403.'
        )]
        public array $entries,

        #[Description('The distinct sub-surfaces present in `entries`, in reading order. Empty sub-surfaces are absent rather than reported as empty.')]
        public array $subSurfaces,
    ) {}
}
