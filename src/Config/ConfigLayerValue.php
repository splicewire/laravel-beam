<?php

namespace Splicewire\Beam\Config;

/**
 * One tier's value for one key. `shadowed` marks a package value the host's top-level copy hides; `removed` marks
 * a key a provider took out of `config()`.
 */
class ConfigLayerValue
{
    public function __construct(
        public ConfigTier $tier,
        public mixed $value,
        public ?string $source,
        public bool $shadowed = false,
        public bool $removed = false,
    ) {}
}
