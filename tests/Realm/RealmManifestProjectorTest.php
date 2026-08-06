<?php

namespace Splicewire\Beam\Tests\Realm;

use Rushing\PermissionCascade\Contracts\EntitlementResolver;
use Splicewire\Beam\Realm\RealmManifestProjector;
use Splicewire\Beam\Realm\RealmRegistry;
use Splicewire\Beam\Tests\Entitlements\FakeEntitlementResolver;
use Splicewire\Beam\Tests\TestCase;

/**
 * Frame OS ticket 08 (ADR-0013 §4/§5): the realm-manifest projector applies per-realm gating —
 * hard → absence (protection by construction), soft → locked flag + upsell (monetization), ungated →
 * always present. Gating rides `beam.core.realm_gates`, NOT the shared RealmDefinition DTO.
 */
class RealmManifestProjectorTest extends TestCase
{
    private function withResolver(array $held): void
    {
        $this->app->instance(EntitlementResolver::class, new FakeEntitlementResolver($held));
    }

    private function keys(array $manifest): array
    {
        return array_column($manifest, 'key');
    }

    public function test_a_hard_gated_realm_is_absent_for_an_unentitled_principal(): void
    {
        config(['beam.core.realm_gates' => [
            'admin' => ['entitlement' => 'app-operator', 'mode' => 'hard'],
        ]]);
        $this->withResolver([]); // holds nothing → not entitled

        $manifest = $this->app->make(RealmManifestProjector::class)->project(null);

        $this->assertNotContains('admin', $this->keys($manifest));
        // ungated realms still project.
        $this->assertContains('site', $this->keys($manifest));
    }

    public function test_a_hard_gated_realm_is_present_and_unlocked_for_an_entitled_principal(): void
    {
        config(['beam.core.realm_gates' => [
            'admin' => ['entitlement' => 'app-operator', 'mode' => 'hard'],
        ]]);
        $this->withResolver(['app-operator']);

        $manifest = $this->app->make(RealmManifestProjector::class)->project(null);
        $admin = collect($manifest)->firstWhere('key', 'admin');

        $this->assertNotNull($admin);
        $this->assertFalse($admin['locked']);
        $this->assertNull($admin['upsell']);
        $this->assertSame('/admin', $admin['routeBase']);
    }

    public function test_a_soft_gated_realm_is_present_locked_with_upsell_when_unentitled(): void
    {
        config(['beam.core.realm_gates' => [
            'tenant' => [
                'entitlement' => 'go-songwriter',
                'mode' => 'soft',
                'title' => 'Studio',
                'upsell' => ['title' => 'Go Songwriter', 'cta' => 'Upgrade'],
            ],
        ]]);
        $this->withResolver([]); // not entitled

        $manifest = $this->app->make(RealmManifestProjector::class)->project(null);
        $tenant = collect($manifest)->firstWhere('key', 'tenant');

        $this->assertNotNull($tenant, 'a soft-gated realm survives in the manifest');
        $this->assertTrue($tenant['locked']);
        $this->assertSame('Studio', $tenant['title']);
        $this->assertSame(['title' => 'Go Songwriter', 'cta' => 'Upgrade'], $tenant['upsell']);
    }

    public function test_a_soft_gated_realm_is_unlocked_when_entitled(): void
    {
        config(['beam.core.realm_gates' => [
            'tenant' => ['entitlement' => 'go-songwriter', 'mode' => 'soft', 'upsell' => ['cta' => 'Upgrade']],
        ]]);
        $this->withResolver(['go-songwriter']);

        $tenant = collect($this->app->make(RealmManifestProjector::class)->project(null))
            ->firstWhere('key', 'tenant');

        $this->assertFalse($tenant['locked']);
        $this->assertNull($tenant['upsell']);
    }

    public function test_an_ungated_realm_is_always_present(): void
    {
        config(['beam.core.realm_gates' => []]);
        $this->withResolver([]);

        $manifest = $this->app->make(RealmManifestProjector::class)->project(null);

        // Every registered realm projects when nothing is gated.
        foreach (array_keys($this->app->make(RealmRegistry::class)->all()) as $realmKey) {
            $this->assertContains($realmKey, $this->keys($manifest));
        }
    }
}
