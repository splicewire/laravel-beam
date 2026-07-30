<?php

declare(strict_types=1);

namespace Splicewire\Beam\Write;

use RuntimeException;

/**
 * Thrown when the acceptance gate refuses a payload — it does not conform to its target schema
 * (beam-write-pipeline ticket 03). When it is raised, NOTHING has persisted: validation is the second
 * pipeline stage, after authorization and before the save.
 *
 * This is the BOOLEAN acceptance gate's refusal (the same gate the migration ladder uses). A
 * formatted-error path for an HTTP 422 body is a distinct, richer concern (DESIGN §7 L10) the public
 * route (ticket 04) layers on; beam-core keeps the pass/fail gate here and names the offending record
 * type so a caller can translate.
 */
final class PayloadRejected extends RuntimeException
{
    public static function for(string $recordType): self
    {
        return new self("The payload does not conform to the target schema for record type [{$recordType}].");
    }
}
