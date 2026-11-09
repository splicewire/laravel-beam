<?php

namespace Splicewire\Beam\Config;

/**
 * What {@see ConfigLadder::fold()} returns: the effective values (as observed) and each leaf key's provenance.
 */
class ConfigResolution
{
    /**
     * @param  array<array-key, mixed>  $values
     * @param  array<string, ConfigResolvedKey>  $keys  by JSON pointer, in leaf order
     * @param  list<string>  $shadowedPackageKeys  package leaves hidden by the host's top-level copy
     */
    public function __construct(
        public array $values,
        public array $keys,
        public array $shadowedPackageKeys,
    ) {}
}
