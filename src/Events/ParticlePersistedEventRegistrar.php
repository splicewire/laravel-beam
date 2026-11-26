<?php

namespace Splicewire\Beam\Events;

use Rushing\Popcorn\Registries\Registrar;
use Rushing\Popcorn\Registries\Registry;
use Splicewire\Beam\Particle\Backing\BackingResolver;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * Registers one `{resource}.persisted` entry for every writable model-backed particle resource.
 *
 * The `BeamParticlePersisted` writer event has no resource key. The catalog therefore marks an entry
 * delivered in process only when exactly one writable resource resolves to its model. The runtime
 * listener uses the same {@see ParticlePersistedEventResolver}, so an ambiguous model is neither
 * offered as an in-process event nor fanned out to multiple resources at write time.
 *
 * Registration stays eager: `EventTypeRegistry::all()` remains a read of declarations, not a generator.
 */
class ParticlePersistedEventRegistrar implements Registrar
{
    public const DESCRIPTION = 'record was written through the beam write pipeline.';

    private ParticlePersistedEventResolver $resolver;

    public function __construct(private ParticleResourceRegistry $resources, ?BackingResolver $backings = null)
    {
        $this->resolver = new ParticlePersistedEventResolver($resources, $backings);
    }

    public function fill(Registry $registry): void
    {
        foreach ($this->resources->all() as $resource) {
            $model = $this->resolver->writableModelFor($resource);

            if ($model === null) {
                continue;
            }

            $name = "{$resource->key}.persisted";
            $registry->register(
                new EventType(
                    name: $name,
                    subject: $model,
                    description: 'A '.$resource->key.' '.self::DESCRIPTION,
                    deliveredInProcess: $this->resolver->eventNameForModel($model) === $name,
                ),
                null,
                self::class,
            );
        }
    }

    public function source(): string
    {
        return 'BeamParticlePersisted, expanded per uniquely writable particle resource';
    }
}
