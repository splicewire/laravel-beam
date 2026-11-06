<?php

namespace Splicewire\Beam\Events;

use Rushing\Popcorn\Registries\Registrar;
use Rushing\Popcorn\Registries\Registry;
use Splicewire\Beam\Particle\Backing\BackingResolver;
use Splicewire\Beam\Particle\Backing\WritesRecords;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Webhooks\HookEmitter;
use Splicewire\Beam\Webhooks\HookSubscriptionReach;

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
 *
 * ## Catalog-only: these names are listed, and nothing delivers them
 *
 * {@see HookEmitter::emit()} has one caller, `DispatchWebhookJob::announce()`, which sends the `hooks.*`
 * lifecycle names. Nothing listens on {@see BeamParticlePersisted} to emit `{resource}.persisted`, so a
 * hook may subscribe to the name (it is in the catalog) and will receive nothing. {@see DESCRIPTION}
 * says so in the description `GET /hooks/events` publishes, and `ParticlePersistedCatalogOnlyTest` pins
 * the pair: a write reaches no subscriber, and the published description admits it.
 *
 * Delivery does not wire in at the write pipeline's emit stage without three decisions first:
 *
 *  - **Which resource.** The event carries the model, the payload and the schema binding, not the
 *    resource key. Mapping a model class back to a key is ambiguous wherever two resources share one
 *    model class, and nothing forbids that.
 *  - **What body.** The payload is the raw schema-shaped content that was written. A webhook body
 *    crosses the application's edge, so it needs a declared Data shape (the particle doctrine), most
 *    likely the resource's read output.
 *  - **Whose rows.** A subjectless subscription is vetted once, by `viewAny` on the model
 *    ({@see HookSubscriptionReach}), and that check is known to be wider than a resource's row scope.
 *    Fanning every write out to it would push rows the subscriber cannot read, and emission has no
 *    principal to re-ask.
 *
 * Whoever wires delivery changes {@see DESCRIPTION} and that test in the same change.
 */
class ParticlePersistedEventRegistrar implements Registrar
{
    /**
     * The description every `{resource}.persisted` entry publishes, after its `A <key> record` lead-in.
     * It states the catalog-only status, because the catalog is what a subscriber reads.
     */
    public const DESCRIPTION = 'record was written through the beam write pipeline. Catalog-only: Beam does not deliver this event to webhooks yet.';

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
                    description: 'A '.$resource->key.' '.self::DESCRIPTION,
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
