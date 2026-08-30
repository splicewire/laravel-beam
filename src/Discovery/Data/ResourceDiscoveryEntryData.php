<?php

namespace Splicewire\Beam\Discovery\Data;

use Schemastud\DataSchemas\Attributes\ArrayItems;
use Schemastud\DataSchemas\Attributes\Description;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Rendering\RenderingCertifier;

/**
 * One route on one mount, as the discovery listing publishes it (api-surface-coherence 105).
 *
 * Every field is read off the live route — its methods, its URI, its name, its `->beam()` metadata.
 * Nothing here is re-declared anywhere, which is the property that keeps the listing from drifting
 * away from the surface it describes.
 *
 * ## The four DELIVERY fields are the rendering catalog's inheritance
 *
 * `GET {resource}/renderings` published exactly these facts for the three endpoints the rendering
 * registry mounted, out of a `RenderingDescriptorData` of its own. particle-operation-surface 13
 * dissolved that registry, and those three endpoints became OPERATIONS — which this listing already
 * enumerated, per resource, mounting even at zero entries. So the catalog route was not replaced with
 * a second listing of the same shape; the facts moved onto the entry that was already here, and the
 * route went. The one thing a reader loses is the `writable`/`fidelity` pair, which described a write
 * verb that has never been mounted for any rendering in the estate ({@see RenderingCertifier}
 * floors all four implementors at `Lossy`) — an absent route is now the only statement of it.
 *
 * They are read off the OPERATION's declared `delivery:`, live, so an enumeration computed off a
 * registry (the composition export's formats come off the profile registry) is never frozen — the same
 * discipline the catalog kept, for the same reason.
 */
#[TypeScript]
class ResourceDiscoveryEntryData extends BeamData
{
    /**
     * @param  list<string>  $methods
     * @param  list<string>  $formats
     * @param  list<string>  $mediaTypes
     * @param  array<string, string>  $deliveryHeaders
     */
    public function __construct(
        #[Description('Which sub-surface of the resource this route belongs to: crud, operations, filters, events, or discovery.')]
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

        #[Description('Whether this operation declares what it puts on the wire (`delivery:`). When false, the four fields below are empty because nothing was declared, not because nothing is delivered.')]
        public bool $declaresDelivery = false,

        #[ArrayItems('string')]
        #[Description(
            'The formats this operation accepts on `?format=`, most-canonical first. An EMPTY list means '
            .'no format axis at all: one representation, no `?format` parameter, and nothing rejected. '
            .'Read `declaresDelivery` rather than testing the list for emptiness.'
        )]
        public array $formats = [],

        #[ArrayItems('string')]
        #[Description('The distinct media types this operation can deliver, most-canonical first. Empty when `declaresDelivery` is false.')]
        public array $mediaTypes = [],

        #[Description('Response headers the operation adds on every delivery, mapped to what each carries. Transport-level `Content-Type` is not listed — it is implied by `mediaTypes`.')]
        public array $deliveryHeaders = [],

        #[Description('The format applied when the caller names none. Null when the operation has no format axis, or has not declared its delivery.')]
        public ?string $defaultFormat = null,
    ) {}
}
