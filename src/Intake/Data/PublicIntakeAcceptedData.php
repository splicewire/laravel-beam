<?php

namespace Splicewire\Beam\Intake\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\Data;
use Splicewire\Beam\Models\BeamSubmission;

/**
 * The 201 body of the generic public intake door (beam-facade ticket 124).
 *
 * Fixed for every intake surface, unlike the request: whatever schema the submission was addressed
 * to, the door answers with the written record's key and the versioned `$id` it was bound to.
 *
 * `id` reports the WRITTEN model, never the instance handed to the writer. Under `x-beam-dedupe`'s
 * `ignore` mode the pipeline returns the row that MATCHED — a different object — and the body must be
 * byte-identical to a fresh capture's (ticket 50 §6), or the door becomes an existence oracle by the
 * shape of its own success response.
 */
#[TypeScript]
class PublicIntakeAcceptedData extends Data
{
    public function __construct(
        #[Description('The written '.BeamSubmission::class.' key — a uuid7. Under dedupe-ignore this is the MATCHED row\'s key, which is what makes a repeat capture indistinguishable from a first one.')]
        public string $id,

        #[Description('The versioned schema the capture was bound to: the resolved schema document\'s absolute `$id` when it declares one, else the record type derived from the route ref.')]
        public string $schemaRef,
    ) {}
}
