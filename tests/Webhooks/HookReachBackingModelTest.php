<?php

namespace Splicewire\Beam\Tests\Webhooks;

use Illuminate\Foundation\Auth\User;
use ReflectionMethod;
use Splicewire\Beam\Particle\Backing\EloquentBacking;
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
 * No gate is installed and none is needed — these assertions are on the SUBJECT the reach hands the
 * Gate, taken by reflection, not on an allow/deny outcome. Stated explicitly because this package's own
 * standard is that a security-adjacent test which does not name its gate posture is worth nothing.
 *
 * ⚠️ The no-model case is pinned to its PRE-EXISTING behaviour deliberately. `modelClass()` returns null
 * for a `MembershipSource`-shaped backing, and the caller's null branch is `continue` — no check at all.
 * Returning null there would turn today's deny into an allow on an authorization path, so this pins the
 * current shape rather than blessing it; see the note in `backingModel()`.
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
}

class ReachProbeUser extends User {}

class ReachProbeBacking extends EloquentBacking
{
    public function __construct()
    {
        parent::__construct(ReachProbeUser::class);
    }
}
