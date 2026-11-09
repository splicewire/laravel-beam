<?php

namespace Splicewire\Beam\Config;

/**
 * An `env()` call feeding one config key: the variable's name and where the call sits.
 */
class ConfigEnvBinding
{
    public function __construct(
        public string $name,
        public ?string $file,
        public ?int $line,
    ) {}

    public function withFile(string $file): self
    {
        return new self($this->name, $file, $this->line);
    }
}
