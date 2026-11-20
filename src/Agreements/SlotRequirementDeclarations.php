<?php

namespace Splicewire\Beam\Agreements;

use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * Beam's default {@see RequirementDeclarations} (agreements 05): the declarations' own `requires:` slots.
 *
 * An operation's Requirements are its behavior's ({@see ParticleOperation::key()}). A resource's go to
 * {@see ParticleResource::readBehavior()} (`on: read`), {@see ParticleResource::writeBehavior()} (`on: write`) or both.
 * It reads the BOOTED registries on every call, unfiltered, so an entry registered after boot is seen and a gated
 * registry cannot hide a Requirement.
 *
 * With no slot anywhere it declares nothing, exactly as {@see NullRequirementDeclarations}. An agreements seam that
 * binds its own declarations resolves this class to read the slots, so it stays public and resolvable on its own.
 *
 * @api
 */
class SlotRequirementDeclarations implements RequirementDeclarations
{
    public function __construct(
        protected ParticleOperationRegistry $operations,
        protected ParticleResourceRegistry $resources,
    ) {}

    public function declares(string $behavior): bool
    {
        return $this->requirementsFor($behavior) !== [];
    }

    public function requirementsFor(string $behavior): array
    {
        return $this->all()[$behavior] ?? [];
    }

    public function all(): array
    {
        $declared = [];

        foreach ($this->operations->unfiltered()->matches('beam.particle.operations') as $operation) {
            if ($operation instanceof ParticleOperation && $operation->requires !== null && $operation->requires !== []) {
                $declared[$operation->key()] = array_values($operation->requires);
            }
        }

        foreach ($this->resources->unfiltered()->all() as $resource) {
            if (! $resource instanceof ParticleResource || $resource->requires === null) {
                continue;
            }

            foreach ($resource->requires as $requirement) {
                if ($requirement->bindsRead()) {
                    $declared[$resource->readBehavior()][] = $requirement;
                }
                if ($requirement->bindsWrite()) {
                    $declared[$resource->writeBehavior()][] = $requirement;
                }
            }
        }

        return $declared;
    }
}
