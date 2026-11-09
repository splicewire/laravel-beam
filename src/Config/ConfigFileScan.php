<?php

namespace Splicewire\Beam\Config;

/**
 * The structure {@see ConfigFileScanner} read from one config file. `keys` is null when the file does not return
 * a literal array the scan can read (absent, unparseable, or built by a function call).
 */
class ConfigFileScan
{
    /**
     * @param  list<string>|null  $keys  the top-level keys, in file order
     * @param  array<string, ConfigEnvBinding>  $bindings  by JSON pointer, relative to the scanned array
     */
    public function __construct(
        public string $file,
        public ?array $keys,
        public array $bindings,
    ) {}
}
