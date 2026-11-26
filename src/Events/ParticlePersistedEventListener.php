<?php

namespace Splicewire\Beam\Events;

/** Maps the generic writer signal to a resource event only when the resource match is unique. */
class ParticlePersistedEventListener
{
    public function __construct(private ParticlePersistedEventResolver $resolver) {}

    public function handle(BeamParticlePersisted $persisted): void
    {
        $eventName = $this->resolver->eventNameForModel($persisted->record::class);

        if ($eventName === null) {
            return;
        }

        BeamEventOccurred::dispatch($eventName, $persisted->record, $persisted->payload);
    }
}
