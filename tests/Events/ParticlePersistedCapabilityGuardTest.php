<?php

namespace Splicewire\Beam\Tests\Events;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Events\EventTypeRegistry;
use Splicewire\Beam\Events\ParticlePersistedEventRegistrar;
use Splicewire\Beam\Events\ResourceKeyOracle;
use Splicewire\Beam\Particle\Backing\BacksModel;
use Splicewire\Beam\Particle\Backing\QueriesRecords;
use Splicewire\Beam\Particle\Backing\ResolvedRecord;
use Splicewire\Beam\Particle\Backing\ResolvesRecord;
use Splicewire\Beam\Particle\Backing\WritableRecord;
use Splicewire\Beam\Particle\Backing\WritesRecords;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\TestCase;

/**
 * `{resource}.persisted` must be gated on **writability**, not on model-ness.
 *
 * {@see ParticlePersistedEventRegistrar}'s docblock states the rule it is enforcing —
 * *"a resource with no single model is not written through `ParticleWriter` as one model either, so
 * `{resource}.persisted` for it would be an event that cannot fire"*. That reason is about `WritesRecords`.
 * The guard tested `modelClass() === null`, which is `BacksModel`. They are independent capabilities
 * (`ResourceBacking` is a marker; every real job is a sub-interface), and the guard was correct only by
 * coincidence of a three-member population — `EloquentBacking` has both, `MembershipSource` and
 * `ReviewQueueUnionSource` have neither, so the two axes happened to coincide exactly.
 *
 * Both off-diagonal cases are legal to construct, and they fail in opposite directions:
 *
 * - **model, no write** — the old guard REGISTERED an event that can never fire. This is the case that
 *   goes red against the old code, and it is the exact outcome the docblock says it is preventing.
 * - **write, no model** — the event could genuinely fire but has no `subject:`. Still skipped, now for
 *   its own stated reason: that is the `subjectless` allowlist's first member, and the registrar's §1
 *   calls that *"a decision rather than a build step"*. Pinned so nobody silently registers a
 *   subjectless entry while "fixing" the guard.
 *
 * The estate has no live instance of either today, which is what makes this a latent trap rather than a
 * bug: nothing fails, and the catalog is silently wrong the day someone writes one.
 */
class ParticlePersistedCapabilityGuardTest extends TestCase
{
    private function catalogNames(): array
    {
        $registry = new EventTypeRegistry(new ResourceKeyOracle($this->app));
        $registry->attach(new ParticlePersistedEventRegistrar(app(ParticleResourceRegistry::class)));

        return array_map(fn ($t) => $t->name, $registry->all());
    }

    /**
     * ⚠️ `readOnly`/`deletable` are not decoration here. `BackingResolver::assertAffordancesWithinCapability()`
     * throws at REGISTRATION when a declaration opens an affordance its backing cannot serve — capability
     * is the ceiling. So a resource over a non-writable backing must declare itself closed, which is
     * exactly why the `model, no write` case below is reachable in the estate rather than hypothetical.
     */
    private function registerResource(string $key, string $backing, bool $writable = true): void
    {
        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: $key,
            backing: $backing,
            filterable: false,
            readOnly: ! $writable,
            deletable: $writable,
            editable: $writable,
        ));
    }

    /** The ordinary case, and the control: both capabilities ⇒ the event exists. */
    public function test_a_writable_model_backed_resource_gets_its_persisted_event(): void
    {
        $this->registerResource('guard-probe-both', BothCapabilitiesBacking::class);

        $this->assertContains('guard-probe-both.persisted', $this->catalogNames());
    }

    /**
     * ⚠️ RED against the old `modelClass() === null` guard.
     *
     * A backing that names a model but cannot write is not written through the pipeline, so
     * `{resource}.persisted` can never fire — yet the old guard saw a non-null `modelClass()` and
     * registered it anyway. The catalog gained an event with no producer.
     */
    public function test_a_model_backed_resource_that_cannot_write_gets_no_persisted_event(): void
    {
        $this->registerResource('guard-probe-readonly', ModelButReadOnlyBacking::class, writable: false);

        $this->assertNotContains('guard-probe-readonly.persisted', $this->catalogNames());
    }

    /**
     * Writable but modelless: skipped, and deliberately so. The event COULD fire, but `EventType`
     * requires a `subject:` and this backing has no single model to name. Registering it would put the
     * first member into the `subjectless` allowlist, which ships empty by decision.
     *
     * This pin is the point: it stops a future reader from "completing" the capability fix by
     * registering a subjectless entry, without that decision being taken.
     */
    public function test_a_writable_modelless_resource_is_still_skipped_pending_the_subjectless_decision(): void
    {
        $this->registerResource('guard-probe-modelless', WritesButModellessBacking::class);

        $this->assertNotContains('guard-probe-modelless.persisted', $this->catalogNames());
    }

    /** Neither capability — the live shape of `members` / `review-queue`. Unchanged by this fix. */
    public function test_a_read_only_modelless_resource_is_skipped_as_before(): void
    {
        $this->registerResource('guard-probe-neither', NeitherCapabilityBacking::class, writable: false);

        $this->assertNotContains('guard-probe-neither.persisted', $this->catalogNames());
    }
}

class GuardProbeModel extends Model {}

class BothCapabilitiesBacking implements BacksModel, QueriesRecords, WritesRecords
{
    public function modelClass(): string
    {
        return GuardProbeModel::class;
    }

    public function query(array $filters): Builder
    {
        return GuardProbeModel::query();
    }

    public function resolveForWrite(string $id, array $filters): ?WritableRecord
    {
        return null;
    }

    public function newRecord(): WritableRecord
    {
        return new WritableRecord(new GuardProbeModel);
    }
}

class ModelButReadOnlyBacking implements BacksModel, QueriesRecords
{
    public function modelClass(): string
    {
        return GuardProbeModel::class;
    }

    public function query(array $filters): Builder
    {
        return GuardProbeModel::query();
    }
}

class WritesButModellessBacking implements ResolvesRecord, WritesRecords
{
    public function resolve(string $id, array $filters): ?ResolvedRecord
    {
        return null;
    }

    public function resolveForWrite(string $id, array $filters): ?WritableRecord
    {
        return null;
    }

    /**
     * ⚠️ particle-write-surface 07: this double is the population the ticket freed, and it now says so.
     * Before 07, `newRecord(): Model` forced a MODELLESS backing to manufacture a `GuardProbeModel` it
     * does not back — the signature was unsatisfiable honestly, which is exactly why the estate's three
     * real non-Eloquent backings implement only `ResolvesRecord, StreamsRecords`. It returns a non-model
     * subject now. The write PIPELINE still cannot persist that (07 scopes it out); this test never
     * persists, it only exercises the catalog guard.
     */
    public function newRecord(): WritableRecord
    {
        return new WritableRecord(new \stdClass);
    }
}

class NeitherCapabilityBacking implements ResolvesRecord
{
    public function resolve(string $id, array $filters): ?ResolvedRecord
    {
        return null;
    }
}
