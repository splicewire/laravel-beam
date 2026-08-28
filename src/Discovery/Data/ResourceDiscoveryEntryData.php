<?php

namespace Splicewire\Beam\Discovery\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * One route on one mount, as the discovery listing publishes it (api-surface-coherence 105).
 *
 * Every field is read off the live route — its methods, its URI, its name, its `->beam()` metadata.
 * Nothing here is re-declared anywhere, which is the property that keeps the listing from drifting
 * away from the surface it describes.
 */
#[TypeScript]
class ResourceDiscoveryEntryData extends BeamData
{
    /**
     * @param  list<string>  $methods
     */
    public function __construct(
        #[Description('Which sub-surface of the resource this route belongs to: crud, operations, filters, renderings, events, or discovery.')]
        public string $subSurface,

        #[Description('HTTP methods, HEAD omitted.')]
        public array $methods,

        #[Description('The route URI, parameters included — `api/v1/circuits/{id}/op/run`.')]
        public string $uri,

        #[Description('The Laravel route name, or null for the handful of hand-written routes that never took one.')]
        public ?string $name,

        #[Description('The operation name for an `operations` entry — `run`, `login-as` — and null for every other sub-surface.')]
        public ?string $operation = null,

        #[Description('The OpenAPI operationId this route declared at its mount, when it declared one.')]
        public ?string $operationId = null,

        #[Description('The response DTO class-string the route declares through `->beam()->returns()`, when it declares one.')]
        public ?string $returns = null,

        #[Description('Whether the declared response DTO is a list rather than a single instance.')]
        public bool $returnsMany = false,
    ) {}
}
