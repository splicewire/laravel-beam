<?php

namespace Splicewire\Beam\Events;

use Splicewire\Beam\Particle\Backing\BackingResolver;
use Splicewire\Beam\Particle\Backing\WritesRecords;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/** Shares the persisted-event catalog mark and runtime mapping rule. */
class ParticlePersistedEventResolver
{
    private BackingResolver $backings;

    public function __construct(private ParticleResourceRegistry $resources, ?BackingResolver $backings = null)
    {
        $this->backings = $backings ?? new BackingResolver;
    }

    /** A model class only has a catalogable persisted event when its resource is writable and model-backed. */
    public function writableModelFor(ParticleResource $resource): ?string
    {
        if (! $this->backings->hasCapability($resource->backing, WritesRecords::class)) {
            return null;
        }

        return $resource->modelClass();
    }

    /** The unique event name for this model, or null when no unique writable resource owns it. */
    public function eventNameForModel(string $model): ?string
    {
        $matches = [];

        foreach ($this->resources->all() as $resource) {
            if ($this->writableModelFor($resource) === $model) {
                $matches[] = (string) $resource->key;
            }
        }

        if (count($matches) !== 1) {
            return null;
        }

        return $matches[0].'.persisted';
    }
}
