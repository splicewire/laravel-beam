<?php

namespace Splicewire\Beam\Events;

use Rushing\Popcorn\Registries\Registrar;
use Rushing\Popcorn\Registries\Registry;
use Splicewire\Beam\Particle\Backing\BackingResolver;
use Splicewire\Beam\Particle\Backing\WritesRecords;
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
 *
 * ⚠️ **CORRECTED 2026-08-31 — that argument is right and it was tested on the wrong axis.** "An event
 * that cannot fire" is about **`WritesRecords`**; `modelClass() === null` is about **`BacksModel`**.
 * `ResourceBacking` is a marker and every real job is a separate sub-interface, so the two are
 * independent. The old guard was correct only by coincidence of a three-member population —
 * {@see EloquentBacking} has both, `MembershipSource` and `ReviewQueueUnionSource` have neither — so the
 * axes happened to coincide exactly.
 *
 * Both off-diagonal cases are legal to construct, and they failed in opposite directions:
 *
 *  - **model, no write** — the old guard saw a non-null `modelClass()` and REGISTERED an event with no
 *    producer, which is precisely the outcome the paragraph above exists to prevent. Reachable in the
 *    estate, not hypothetical: `assertAffordancesWithinCapability()` forces such a resource to declare
 *    itself `readOnly`, and a read-only model-backed resource is an ordinary thing to want.
 *  - **write, no model** — skipped before and skipped now, but for its OWN reason (no subject to name),
 *    which the code now states separately instead of folding into the model check.
 *
 * `ParticlePersistedCapabilityGuardTest` pins all four quadrants; the `model, no write` case was
 * verified red against the old guard.
 */
class ParticlePersistedEventRegistrar implements Registrar
{
    private BackingResolver $backings;

    public function __construct(private ParticleResourceRegistry $resources, ?BackingResolver $backings = null)
    {
        // Defaulted rather than required: every existing construction site passes one argument, and a
        // resolver is stateless. Substitutable at the seam all the same.
        $this->backings = $backings ?? new BackingResolver;
    }

    public function fill(Registry $registry): void
    {
        foreach ($this->resources->all() as $resource) {
            // (1) NOT WRITABLE ⇒ `{resource}.persisted` can never fire. This is the docblock's actual
            // argument, tested on the axis it is about. Decided statically off the class-string, so no
            // backing is resolved (and no constructor runs) merely to build the catalog.
            if (! $this->backings->hasCapability($resource->backing, WritesRecords::class)) {
                continue;
            }

            $model = $resource->modelClass();

            // (2) WRITABLE BUT MODELLESS ⇒ the event genuinely could fire, and has no subject to name.
            // Skipped for its own reason, not (1)'s: `EventType` requires a `subject:`, so registering
            // this would put the first member into the `subjectless` allowlist — which §1 above says
            // ships empty and whose first entry is a DECISION, not a build step. Empty population today.
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
