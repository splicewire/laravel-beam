<?php

namespace Splicewire\Beam\Collections;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Data;

#[Title('Collection fields')]
class CollectionInputData extends Data
{
    /** @param array<string, mixed> $fields */
    public function __construct(public array $fields) {}

    public function toModelAttributes(): array
    {
        return $this->fields;
    }
}
