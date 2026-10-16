<?php

namespace Splicewire\Beam\Collections;

use Spatie\LaravelData\Data;

class CollectionInputData extends Data
{
    /** @param array<string, mixed> $fields */
    public function __construct(public array $fields) {}

    public function toModelAttributes(): array
    {
        return $this->fields;
    }
}
