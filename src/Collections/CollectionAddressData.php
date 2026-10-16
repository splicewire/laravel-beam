<?php

namespace Splicewire\Beam\Collections;

use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;

/** The address is a stem, never a pinned schema version or an opaque filter. */
class CollectionAddressData extends Data
{
    public function __construct(#[Required] public string $collection) {}
}
