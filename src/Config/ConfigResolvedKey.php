<?php

namespace Splicewire\Beam\Config;

use Splicewire\Beam\Enums\ConfigTier;

/**
 * One leaf key's place on the ladder: the value it resolves to, the tier that set it, and every tier's value
 * bottom-up.
 */
class ConfigResolvedKey
{
    /**
     * @param  list<ConfigLayerValue>  $layers
     */
    public function __construct(
        public string $pointer,
        public mixed $value,
        public ConfigTier $tier,
        public bool $hostShadowsPackage,
        public array $layers,
    ) {}
}
