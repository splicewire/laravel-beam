<?php

namespace Splicewire\Beam\Collections;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;

/** The address is a stem, never a pinned schema version or an opaque filter. */
class CollectionAddressData extends Data
{
    public function __construct(
        #[Required]
        #[Description('The versionless schema stem returned by the tenant Collections catalog, selecting the Collection whose records or definition this request addresses.')]
        public string $collection,
    ) {}
}
