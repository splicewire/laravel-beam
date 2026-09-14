<?php

namespace Splicewire\Beam\Filters\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Splicewire\Beam\Data\BeamData;

class SavedFilterListResponseData extends BeamData
{
    /** @param list<SavedFilterData> $data */
    public function __construct(#[DataCollectionOf(SavedFilterData::class)] public array $data) {}
}
