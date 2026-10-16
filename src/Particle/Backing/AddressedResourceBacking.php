<?php

namespace Splicewire\Beam\Particle\Backing;

use Spatie\LaravelData\Data;

/** A runtime resource whose declared query address selects its backing population. */
interface AddressedResourceBacking extends ResourceBacking
{
    /** @return class-string<Data> */
    public function addressData(): string;
}
