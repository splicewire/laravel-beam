<?php

namespace Splicewire\Beam\Config;

/**
 * An `env()` call feeding one config key: the variable's name and where the call sits. `default` is the call's second
 * argument as printed source (`'fake'`, `false`, `null`), or null when the call passes none; only
 * {@see ConfigFileScanner::envReads()} fills it.
 */
class ConfigEnvBinding
{
    public function __construct(
        public string $name,
        public ?string $file,
        public ?int $line,
        public ?string $default = null,
    ) {}

    public function withFile(string $file): self
    {
        return new self($this->name, $file, $this->line, $this->default);
    }
}
