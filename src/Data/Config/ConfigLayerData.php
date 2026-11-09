<?php

namespace Splicewire\Beam\Data\Config;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Config\ConfigTier;
use Splicewire\Beam\Data\BeamData;

/**
 * One tier's value for one key, bottom-up, as the Config area's cascade shows it (ADR-0245). `shadowed` is a
 * package value the host's top-level copy hides; `removed` is a key a service provider took out of `config()`.
 * A secret key's layers carry no values.
 */
#[TypeScript]
class ConfigLayerData extends BeamData
{
    public function __construct(
        public ConfigTier $tier,
        public mixed $value,
        public ?string $source,
        public bool $shadowed,
        public bool $removed,
    ) {}
}
