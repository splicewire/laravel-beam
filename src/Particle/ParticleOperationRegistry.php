<?php

declare(strict_types=1);

namespace Splicewire\Beam\Particle;

use RuntimeException;

/** Container-singleton registry of {@see ParticleOperation}s, keyed `resource:name`. */
class ParticleOperationRegistry
{
    /** @var array<string, ParticleOperation> */
    private array $operations = [];

    public function register(ParticleOperation $operation): void
    {
        $this->operations[$operation->key()] = $operation;
    }

    public function get(string $resource, string $name): ParticleOperation
    {
        return $this->operations["{$resource}:{$name}"]
            ?? throw new RuntimeException("No particle operation [{$name}] on resource [{$resource}].");
    }
}
