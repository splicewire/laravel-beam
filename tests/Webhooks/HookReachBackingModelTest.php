<?php

namespace Splicewire\Beam\Tests\Webhooks;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\User;
use ReflectionMethod;
use Splicewire\Beam\Events\EventTypeRegistry;
use Splicewire\Beam\Events\ParticlePersistedEventRegistrar;
use Splicewire\Beam\Particle\Backing\EloquentBacking;
use Splicewire\Beam\Particle\Backing\ResourceBacking;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\TestCase;
use Splicewire\Beam\Webhooks\HookSubscriptionReach;

/**
 * `HookSubscriptionReach::backingModel()` feeds `Gate::authorize('viewAny', $class)` directly
 * (`HookSubscriptionReach:86-92`), so whatever it returns IS an authorization subject.
 *
 * It used to read the raw `backing:` slot and return it whenever `class_exists()`. That was safe only
 * while every declaration named a model — and since particle-contribution-seam 11 the slot is
 * polymorphic (`ResourceBacking|class-string`). `laravel-beam-accounts` moving `users` to
 * `backing: ConfiguredUserBacking::class` on 2026-08-28, so the model follows host config, is the first
 * live case: `class_exists()` is true for a backing, so the old guard passed it straight through and
 * `users.*` hook subscriptions began gating on a class with no policy.
 *
 * ## Gate posture
 *
 * **No gate is installed anywhere in this class — no `Gate::before`, no policy.** For the reflection
 * tests that is merely irrelevant (they assert on the SUBJECT the reach hands the Gate, never on an
 * outcome). For the three no-model tests below it is load-bearing: they assert deny and allow directly,
 * and the neighbouring `HookHttpSurfaceTest:52` installs `Gate::before(fn () => true)`, under which
 * every one of them would pass for the wrong reason. Stated explicitly because this package's own
 * standard is that a security-adjacent test which does not name its gate posture is worth nothing.
 *
 * ⚠️ The no-model case is pinned to its PRE-EXISTING behaviour deliberately. `modelClass()` returns null
 * for a `MembershipSource`-shaped backing, and the caller's null branch is `continue` — no check at all.
 * Returning null there would turn today's deny into an allow on an authorization path, so this pins the
 * current shape rather than blessing it; see the note in `backingModel()` and particle-write-surface
 * ticket 06, which frames the open question and measured why the deny is unreachable today.
 */
class HookReachBackingModelTest extends TestCase
{
    protected function reachBackingModel(string $resourceKey): ?string
    {
        $method = new ReflectionMethod(HookSubscriptionReach::class, 'backingModel');
        $method->setAccessible(true);

        return $method->invoke($this->app->make(HookSubscriptionReach::class), $resourceKey);
    }

    protected function registerResource(string $key, string $backing): void
    {
        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: $key,
            backing: $backing,
        ));
    }

    /**
     * The `MembershipSource` / `ReviewQueueUnionSource` shape. `readOnly: true` alone closes all three
     * write affordances (`ParticleResource` derives `editable`/`deletable` from it), which registration
     * requires of a backing that does not implement `WritesRecords`.
     */
    protected function registerNoModelResource(string $key): void
    {
        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: $key,
            backing: ReachUnionBacking::class,
            readOnly: true,
        ));
    }

    /** @return list<string> */
    protected function reachResourceKeys(array $events): array
    {
        $method = new ReflectionMethod(HookSubscriptionReach::class, 'resourceKeysOf');
        $method->setAccessible(true);

        return $method->invoke($this->app->make(HookSubscriptionReach::class), $events);
    }

    public function test_it_returns_the_model_a_backing_resolves_to_never_the_backing_class_string(): void
    {
        $this->registerResource('reach-probe', ReachProbeBacking::class);

        $this->assertSame(ReachProbeUser::class, $this->reachBackingModel('reach-probe'));
        $this->assertNotSame(ReachProbeBacking::class, $this->reachBackingModel('reach-probe'));
    }

    public function test_it_still_returns_a_plain_model_class_string_declared_directly(): void
    {
        $this->registerResource('reach-plain', ReachProbeUser::class);

        $this->assertSame(ReachProbeUser::class, $this->reachBackingModel('reach-plain'));
    }

    public function test_it_returns_null_for_a_resource_key_the_registry_does_not_hold(): void
    {
        $this->assertNull($this->reachBackingModel('no-such-resource'));
    }

    // ── The no-model branch, pinned rather than blessed (particle-write-surface ticket 06) ──────────

    /**
     * The fallback returns the BACKING class-string, and returning null instead would be an
     * allow — so the shape is pinned, not merely the outcome.
     *
     * Population estate-wide is three declarations across two packages: tower's `members` and
     * `review-queue`, beam-accounts' `members`. All three back a `ResolvesRecord`+`StreamsRecords`
     * source that legally declines `BacksModel` (see that interface's docblock), so
     * `BackingResolver::modelFor()` answers null for each.
     */
    public function test_a_backing_that_declares_no_single_model_falls_back_to_the_backing_class_string(): void
    {
        $this->registerNoModelResource('reach-union');

        $this->assertSame(ReachUnionBacking::class, $this->reachBackingModel('reach-union'));
        $this->assertNotNull($this->reachBackingModel('reach-union'));
    }

    /**
     * ⚠️ **The load-bearing pin.** `authorize()` feeds `backingModel()` straight to
     * `Gate::authorize('viewAny', …)`, so the fallback above is an authorization SUBJECT — and a
     * backing class has no policy, which means today's answer is a DENY.
     *
     * That deny is undefended by construction: nobody chose it, it falls out of "no policy is
     * registered for this class". Changing the fallback to null would route through the caller's
     * `continue` and turn it into an ALLOW, silently. This test is what makes that flip visible.
     *
     * **Gate posture — no `Gate::before`, no policy of any kind.** The neighbouring
     * `HookHttpSurfaceTest:52` installs `Gate::before(fn () => true)`; under that harness this
     * assertion would pass for the wrong reason and prove nothing. The deny asserted here is the real
     * gate's deny-by-default, taken with the gate closed.
     */
    public function test_the_no_model_fallback_denies_with_the_gate_closed(): void
    {
        $this->registerNoModelResource('reach-union');
        $this->be(new ReachProbeUser);

        $this->expectException(AuthorizationException::class);

        $this->app->make(HookSubscriptionReach::class)->authorize(['reach-union.created'], null);
    }

    /**
     * The counterpart, and the reason the test above is worth having: the caller's null branch is
     * `continue` (`HookSubscriptionReach:88-90`), i.e. **no authorization check at all**.
     *
     * So `backingModel()` returning null is not "deny" — it is "skip". Pinned as a fact about the
     * seam, deliberately NOT as an endorsement.
     *
     * Reaching it requires a catalog name whose first segment is not a live resource key, which is
     * possible because that prefix check is advisory by decision (`EventCatalogPrefixAudit`, demoted
     * from a boot-fatal throw after it took `~/Herd/tower` off the air). Measured 2026-08-30, the
     * advisory reads **zero dead prefixes** at `~/Herd/tower` (47 event types) and at
     * `~/Herd/splicewire-app` (58), so the population is empty today and the audit is its meter.
     *
     * **Gate posture: none installed.** No `Gate::before`, no policies — the same closed gate the
     * test above uses, which is what makes the contrast between the two meaningful.
     *
     * ⚠️ The first assertion is not decoration. "Nothing threw" is satisfied just as well by a loop that
     * never ran, and this is the one test whose whole subject is a branch being *skipped* — so on its
     * own it would pin loop absence and loop reachability alike, and pass either way. That is the
     * estate's standing defect class: an instrument that reports success by not running.
     *
     * Precisely what it does and does not cover, mutation-tested rather than asserted: it kills a
     * regressed `resourceKeysOf()` (the key derivation this test depends on), and it does **not** kill
     * `foreach ([] as $key)` inside `authorize()`, because it asks `resourceKeysOf()` directly. That
     * second mutation is caught by `test_the_no_model_fallback_denies_with_the_gate_closed`, which stops
     * denying the moment the loop stops running. The pair covers it; neither half does alone.
     */
    public function test_a_resource_key_the_registry_cannot_resolve_is_not_authorized_at_all(): void
    {
        $this->be(new ReachProbeUser);

        $this->assertSame(
            ['no-such-resource'],
            $this->reachResourceKeys(['no-such-resource.created']),
            'the loop must actually reach the null branch for the next line to mean anything',
        );

        $this->app->make(HookSubscriptionReach::class)->authorize(['no-such-resource.created'], null);

        $this->addToAssertionCount(1);
    }

    /**
     * Why the deny above is unreachable through the catalog, pinned at its actual cause.
     *
     * `ParticlePersistedEventRegistrar` skips a resource whose `modelClass()` is null, on the stated
     * ground that `{resource}.persisted` for it would be an event that cannot fire. That skip — not
     * `backingModel()` — is what stops a `members`/`review-queue` subscription: `requireCatalogNames()`
     * 422s the name one step before `authorize()` ever runs. There are no `#[BeamEvent]` declarations
     * anywhere in the family, so the fan-out is the only route to a resource-derived event name.
     *
     * If someone deletes that skip, the deny stops being dead code and starts being policy. This test
     * fails at that moment, which is the point.
     *
     * ⚠️ The registrar is driven against a FRESH registry rather than read off the container's, and
     * the modelled control is why. The container's catalog is filled once and memoized, so a resource
     * registered inside a test arrives too late to fan out — reading it there returns `[]` for the
     * no-model case and for the control alike, i.e. the assertion passes by not running.
     */
    public function test_no_catalog_name_is_derived_for_a_resource_with_no_model(): void
    {
        $this->registerNoModelResource('reach-union');
        $this->registerResource('reach-modelled', ReachProbeUser::class);

        $catalog = new EventTypeRegistry;
        (new ParticlePersistedEventRegistrar($this->app->make(ParticleResourceRegistry::class)))->fill($catalog);

        $this->assertSame([], $catalog->withPrefix('reach-union'));
        $this->assertNotSame([], $catalog->withPrefix('reach-modelled'), 'control: a modelled resource DOES fan out');
    }
}

class ReachProbeUser extends User {}

/**
 * A backing that declares no single model — the `MembershipSource` / `ReviewQueueUnionSource` shape,
 * reduced to the one property this test is about: it does not implement `BacksModel`.
 */
class ReachUnionBacking implements ResourceBacking {}

class ReachProbeBacking extends EloquentBacking
{
    public function __construct()
    {
        parent::__construct(ReachProbeUser::class);
    }
}
