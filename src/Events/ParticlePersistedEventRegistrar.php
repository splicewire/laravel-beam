<?php

namespace Splicewire\Beam\Events;

use Rushing\Popcorn\Registries\Registrar;
use Rushing\Popcorn\Registries\Registry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * Expands the one nameless producer — {@see BeamParticlePersisted} — into one flat catalog entry per
 * particle resource: `{resource}.persisted`, subject = that resource's model class.
 *
 * ## Why it is expanded at registration rather than generated at read
 *
 * `BeamParticlePersisted` carries no name string at all and fires for every beam write of every
 * resource, so it is the map's one genuinely multi-name producer. A read-time generator would make
 * `EventTypeRegistry::all()` a computation, and ticket 13 §7's totality assertion would then be
 * guarding the generator's own premise instead of the catalog (api-surface-coherence ticket 14 §2). So
 * the fan-out happens once, here, and `all()` stays data.
 *
 * ## Resources with no model class are skipped, and that is the measurement
 *
 * The chartering ticket said to take the subject from `ParticleResource::$model`. ⚠️ **That field no
 * longer exists** — it was a required non-nullable `string` and was the blocker preventing the two
 * declaration types from merging; its successor is the nullable
 * {@see ParticleResource::modelClass()}, which returns null for a resource
 * whose backing declares no single model (`members`, `review-queue`).
 *
 * That correction has a consequence the ticket did not anticipate: a nullable subject would have forced
 * the first entries onto the `subjectless` escape hatch, which §1 says ships EMPTY and whose first
 * member is a decision rather than a build step. The resolution is to skip: a resource with no single
 * model is not written through `ParticleWriter` as one model either, so `{resource}.persisted` for it
 * would be an event that cannot fire. Skipping keeps the subjectless allowlist empty *and* keeps the
 * catalog honest, where declaring them subjectless would have done neither.
 */
class ParticlePersistedEventRegistrar implements Registrar
{
    public function __construct(private ParticleResourceRegistry $resources) {}

    public function fill(Registry $registry): void
    {
        foreach ($this->resources->all() as $resource) {
            $model = $resource->modelClass();

            if ($model === null) {
                continue;
            }

            $registry->register(
                new EventType(
                    name: "{$resource->key}.persisted",
                    subject: $model,
                    description: 'A '.$resource->key.' record was written through the beam write pipeline.',
                ),
                null,
                self::class,
            );
        }
    }

    public function source(): string
    {
        return 'BeamParticlePersisted, expanded per particle resource';
    }
}
