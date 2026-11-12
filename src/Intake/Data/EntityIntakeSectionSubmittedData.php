<?php

namespace Splicewire\Beam\Intake\Data;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/** The existing section-submission success body; the host supplies its 201 response. */
#[TypeScript]
class EntityIntakeSectionSubmittedData extends BeamData
{
    public function __construct(
        #[MapOutputName('submissionId')]
        public string $submissionId,
    ) {}
}
