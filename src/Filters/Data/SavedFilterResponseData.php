<?php

namespace Splicewire\Beam\Filters\Data;

use Splicewire\Beam\Data\BeamData;

class SavedFilterResponseData extends BeamData
{
    public function __construct(public SavedFilterData $data) {}
}
