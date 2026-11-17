<?php

namespace Splicewire\Beam\Data\Config;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * One version of a section's operator tier (config-sections ticket 05): every saved draft and every publish is one.
 * `draft` marks HEAD and `published` marks the version the runtime applies. A version carries no secret value.
 */
#[TypeScript, Title('Config version')]
class ConfigVersionData extends BeamData
{
    public function __construct(
        public string $id,
        public int $version,
        public ?string $label,
        public ?string $createdBy,
        public ?string $createdAt,
        public bool $draft,
        public bool $published,
    ) {}
}
