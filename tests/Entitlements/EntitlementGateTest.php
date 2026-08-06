<?php

namespace Splicewire\Beam\Tests\Entitlements;

use Illuminate\Contracts\Auth\Access\Gate;
use Splicewire\Beam\Entitlements\EntitlementGate;
use Splicewire\Beam\Tests\TestCase;

/**
 * Frame OS ticket 08 (ADR-0013 §2): the entitlement gate unifies the two authorization planes. The
 * feature plane resolves through the kernel EntitlementResolver seam; beam registers `entitlement:{key}`
 * Gate abilities (at boot) so `Gate::allows(...)` consults the resolver.
 */
class EntitlementGateTest extends TestCase
{
    /**
     * Bind the fake resolver + the feature-key config BEFORE the app boots, so the provider's
     * boot-time ability registration sees them (abilities are defined once at boot).
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.entitlements', [
            'workbench.enter' => ['label' => 'Workbench', 'default' => false],
            'own-a-song' => ['label' => 'Own a song', 'default' => false],
        ]);

        // Bind the fake through permission-cascade's config seam so the kernel singleton resolves it
        // (a container instance() here would be clobbered when the cascade provider registers its singleton).
        $app['config']->set('permission-cascade.entitlement_resolver', fn () => new FakeEntitlementResolver(['workbench.enter']));
    }

    public function test_the_gate_reports_held_keys_true_and_unheld_keys_false(): void
    {
        $gate = $this->app->make(EntitlementGate::class);

        $this->assertTrue($gate->allows('workbench.enter'));
        $this->assertFalse($gate->allows('own-a-song'));
    }

    public function test_registered_gate_abilities_delegate_to_the_resolver(): void
    {
        $gate = $this->app->make(Gate::class);

        $this->assertTrue($gate->allows('entitlement:workbench.enter'));
        $this->assertFalse($gate->allows('entitlement:own-a-song'));
    }
}
