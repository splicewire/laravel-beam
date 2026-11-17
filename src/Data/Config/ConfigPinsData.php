<?php

namespace Splicewire\Beam\Data\Config;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * A section's two pins at the operator tier (config-sections ticket 05; the PRD's "draft and published pins, and a
 * derived draft-pending flag"): the version numbers of the last saved draft (HEAD) and of the published version the
 * runtime applies. Null when the section has none. A draft is pending when the two differ.
 */
#[TypeScript, Title('Config pins')]
class ConfigPinsData extends BeamData
{
    public function __construct(
        public ?int $draft,
        public ?int $published,
        public bool $draftPending,
    ) {}
}
