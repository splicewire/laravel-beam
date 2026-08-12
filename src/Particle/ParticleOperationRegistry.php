<?php

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

    public function has(string $resource, string $name): bool
    {
        return isset($this->operations["{$resource}:{$name}"]);
    }

    /**
     * The non-throwing twin of {@see get()} — for callers that ASK rather than demand. A codegen pass walking
     * the live route table hits routes whose operation was never registered; that is a reportable absence, not
     * an exception, so it needs a lookup that can say "no".
     */
    public function find(string $resource, string $name): ?ParticleOperation
    {
        return $this->operations["{$resource}:{$name}"] ?? null;
    }
}
