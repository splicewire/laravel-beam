<?php

namespace Splicewire\Beam\Tests\Entitlements;

use Illuminate\Support\Facades\Gate;
use Rushing\PermissionCascade\Contracts\EntitlementResolver;
use Splicewire\Beam\Entitlements\CanMapBuilder;
use Splicewire\Beam\Tests\TestCase;

/**
 * Frame OS ticket 08 (ADR-0013 §2): the flat can-map spans FEATURE keys (from config('app.entitlements')),
 * APP/REALM keys (`app:{realm}` = reachable in the projected manifest), and caller-folded window-mode keys.
 * The frontend reads this map verbatim; it computes no authz.
 */
class CanMapBuilderTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.entitlements', [
            'workbench.enter' => ['label' => 'Workbench', 'default' => false],
            'own-a-song' => ['label' => 'Own a song', 'default' => false],
        ]);
    }

    private function withResolver(array $held): void
    {
        $this->app->instance(EntitlementResolver::class, new FakeEntitlementResolver($held));
    }

    public function test_it_emits_feature_keys_true_for_held_and_false_for_unheld(): void
    {
        $this->withResolver(['workbench.enter']);

        $can = $this->app->make(CanMapBuilder::class)->forPrincipal(null);

        $this->assertTrue($can['workbench.enter']);
        $this->assertFalse($can['own-a-song']);
    }

    public function test_it_emits_app_realm_keys_true_for_reachable_realms(): void
    {
        // No realm gates → every realm reachable, so every app:{realm} key is true.
        config(['beam.core.realm_gates' => []]);
        $this->withResolver([]);

        $can = $this->app->make(CanMapBuilder::class)->forPrincipal(null);

        $this->assertTrue($can['app:site']);
        $this->assertTrue($can['app:operator']);
    }

    public function test_a_hard_gated_unreachable_realm_maps_app_key_false(): void
    {
        config(['beam.core.realm_gates' => [
            'operator' => ['entitlement' => 'app-operator', 'mode' => 'hard'],
        ]]);
        $this->withResolver([]); // not entitled → operator omitted from manifest → app:operator false

        $can = $this->app->make(CanMapBuilder::class)->forPrincipal(null);

        $this->assertFalse($can['app:operator']);
        $this->assertTrue($can['app:site']);
    }

    public function test_a_soft_locked_realm_is_still_reachable_so_its_app_key_is_true(): void
    {
        // Soft-lock keeps the realm in the manifest (lockable), so it is REACHABLE → app key true.
        config(['beam.core.realm_gates' => [
            'tenant' => ['entitlement' => 'go-songwriter', 'mode' => 'soft', 'upsell' => ['cta' => 'x']],
        ]]);
        $this->withResolver([]);

        $can = $this->app->make(CanMapBuilder::class)->forPrincipal(null);

        $this->assertTrue($can['app:tenant']);
    }

    public function test_caller_folded_window_mode_keys_resolve_through_the_gate(): void
    {
        $this->withResolver([]);

        // Define a subject-scoped ability the caller folds in; the builder resolves it via the Laravel Gate.
        Gate::define('song:42:update', fn ($user = null) => true);

        $can = $this->app->make(CanMapBuilder::class)
            ->with(['song:42:update'])
            ->forPrincipal(null);

        $this->assertArrayHasKey('song:42:update', $can);
        $this->assertTrue($can['song:42:update']);
    }
}
