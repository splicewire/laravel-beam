<?php

namespace Splicewire\Beam\Particle;

use RuntimeException;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\RegistryArity;

/** Container-singleton registry of {@see ParticleOperation}s, keyed `resource:name`. */
#[IsRegistry(
    root: 'beam.particle.operations',
    of: 'named particle operations (custom actions) mounted on the generic op controller',
    arity: RegistryArity::PickOne,
    entryType: ParticleOperation::class,
    onDuplicate: OnDuplicate::Supersede,
    note: 'Its live keys are `resource:name`, and `:` is REJECTED by Key rather than folded — this '
        .'registry cannot be migrated by rekeying alone (registry-kernel ticket 05).',
    order: 13,
)]
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

    /**
     * Every registered operation, registration order — for auditors walking the DECLARED set rather
     * than looking one up (the schema-projection drift audit, particle-doctrine-followups 14).
     *
     * @return list<ParticleOperation>
     */
    public function all(): array
    {
        return array_values($this->operations);
    }
}
