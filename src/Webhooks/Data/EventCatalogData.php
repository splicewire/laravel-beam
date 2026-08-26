<?php

namespace Splicewire\Beam\Webhooks\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\Data;

/**
 * The body of `GET /hooks/events` and of its scoped twin `GET /{resource}/hooks/events`
 * (api-surface-coherence ticket 38, decided by 12 §3).
 *
 * **One route serves both exposures**, which is why `resource` is nullable rather than two shapes:
 * the scoped mount fills it from the URL segment and the root mount leaves it null. A client reading
 * this body can tell which catalog it holds without being told out of band.
 */
#[TypeScript]
class EventCatalogData extends Data
{
    /**
     * @param  list<EventTypeDescriptorData>  $events
     */
    public function __construct(
        #[Description('The resource key this catalog was filtered to, or null for the unfiltered root catalog.')]
        public ?string $resource,

        #[DataCollectionOf(EventTypeDescriptorData::class)]
        #[Description('Every event type a hook may subscribe to, in registration order. Empty is a legal answer — a host that registered no producers has no vocabulary, which is not an error.')]
        public array $events,
    ) {}
}
