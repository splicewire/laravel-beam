<?php

namespace Splicewire\Beam\Schema;

use InvalidArgumentException;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * Schema identity → read Data, derived from the resource declarations, not separately registered.
 *
 * Explicit schemaRef claims are unique by stem. With no explicit claim, the Data class itself is
 * the binding; multiple resources may share that class (for example, realm-specific dashboards).
 * Reads include headless resources and retain the source registry's live authorization filter.
 * Nothing is cached: late registration and same-key replacement cannot leave stale bindings behind.
 */
class SchemaBindingIndex
{
    public function __construct(private ParticleResourceRegistry $resources) {}

    /** @return class-string<Data>|null */
    public function dataClassFor(string $binding): ?string
    {
        $identity = SchemaId::from($binding)->recordType();

        foreach ($this->resources->all() as $resource) {
            if ($this->bindingFor($resource) === $identity) {
                return $resource->data;
            }
        }

        return null;
    }

    /** Validate before the owning registry mutates; registration is never actor-filtered. */
    public function assertCanRegister(ParticleResource $incoming): void
    {
        if ($incoming->schemaRef !== null && (
            trim($incoming->schemaRef) === '' || $incoming->data === null || $incoming->data === ''
        )) {
            throw new InvalidArgumentException(
                "Resource [{$incoming->key}] must declare a non-empty schemaRef and read Data class for an explicit schema binding.",
            );
        }

        $binding = $this->bindingFor($incoming);
        if ($binding === null) {
            return;
        }

        foreach ($this->resources->unfiltered()->all() as $existing) {
            if ($existing->key === $incoming->key) {
                continue; // Same-key supersession replaces the claim, too.
            }

            if ($existing->schemaRef === null && $incoming->schemaRef === null) {
                continue; // Sharing a read Data class is not claiming an explicit schema twice.
            }

            if ($this->bindingFor($existing) === $binding) {
                throw new InvalidArgumentException(
                    "Schema binding [{$binding}] is claimed by both resources [{$existing->key}] and [{$incoming->key}].",
                );
            }
        }
    }

    private function bindingFor(ParticleResource $resource): ?string
    {
        $binding = $resource->schemaRef ?? $resource->data;

        return $binding === null || $binding === '' ? null : SchemaId::from($binding)->recordType();
    }
}
