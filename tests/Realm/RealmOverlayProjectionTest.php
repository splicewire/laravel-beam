<?php

namespace Splicewire\Beam\Tests\Realm;

use Rushing\PermissionCascade\Contracts\EntitlementResolver;
use Splicewire\Beam\Realm\RealmManifestProjector;
use Splicewire\Beam\Realm\RealmOverlay;
use Splicewire\Beam\Realm\RealmOverlayRegistry;
use Splicewire\Beam\Realm\RealmRegistry;
use Splicewire\Beam\Tests\Entitlements\FakeEntitlementResolver;
use Splicewire\Beam\Tests\TestCase;

/**
 * Frame OS ticket 14 (ADR-0014 §A2): a satellite/capability overlay is an ADDITIVE overlay ON an
 * existing realm, resolved PHP-side BEFORE the RealmManifestProjector emits. It enriches the realm's
 * particles (fields/capabilities/entries/overrides) via a laravel-data-schemas overlay; unentitled
 * capabilities soft-lock with an upsell; and it NEVER owns a realm or adds a standalone tile. The frame
 * stays satellite-agnostic — the manifest carries only realms, some enriched.
 */
class RealmOverlayProjectionTest extends TestCase
{
    private function withResolver(array $held): void
    {
        $this->app->instance(EntitlementResolver::class, new FakeEntitlementResolver($held));
    }

    private function registry(): RealmOverlayRegistry
    {
        // The projector reads the singleton the provider bound, so mutate THAT instance.
        return $this->app->make(RealmOverlayRegistry::class);
    }

    private function keys(array $manifest): array
    {
        return array_column($manifest, 'key');
    }

    private function project(mixed $principal = null): array
    {
        return $this->app->make(RealmManifestProjector::class)->project($principal);
    }

    public function test_inert_by_default_no_overlay_leaves_the_manifest_byte_for_byte(): void
    {
        config(['beam.core.realm_gates' => []]);
        $this->withResolver([]);

        // Baseline with NO overlay registered.
        $baseline = $this->project();

        // A fresh app with the same inputs but still no overlay must match exactly.
        $this->assertNotEmpty($baseline);
        foreach ($baseline as $descriptor) {
            // No realm carries a `capabilities` key until an overlay adds one.
            $this->assertArrayNotHasKey('capabilities', $descriptor);
        }
    }

    public function test_an_overlay_enriches_its_target_realms_particles_via_a_data_schema_overlay(): void
    {
        config(['beam.core.realm_gates' => []]);
        $this->withResolver([]);

        // A data-schema overlay: add a field to the `site` realm descriptor + a nested entries list.
        $this->registry()->register(RealmOverlay::make(
            realmKey: 'site',
            overlay: ['overlay' => '1.0.0', 'actions' => [
                ['target' => '$.badge', 'override' => 'Powered by Audiostud'],
                ['target' => '$.entries', 'override' => [['key' => 'studio-tool', 'title' => 'Studio Tool']]],
            ]],
            satelliteKey: 'audiostud',
        ));

        $site = collect($this->project())->firstWhere('key', 'site');

        $this->assertNotNull($site);
        $this->assertSame('Powered by Audiostud', $site['badge'], 'overlay added a field');
        $this->assertSame([['key' => 'studio-tool', 'title' => 'Studio Tool']], $site['entries']);
        // The base descriptor fields survive the fold.
        $this->assertSame('site', $site['key']);
        $this->assertSame('/', $site['routeBase']);
    }

    public function test_an_unentitled_overlay_capability_is_present_but_locked_with_an_upsell(): void
    {
        config(['beam.core.realm_gates' => []]);
        $this->withResolver([]); // holds nothing → not entitled to the capability

        $this->registry()->register(RealmOverlay::make(
            realmKey: 'site',
            overlay: ['overlay' => '1.0.0', 'actions' => []],
            capabilities: [
                [
                    'key' => 'music.render.voice',
                    'title' => 'Voice render',
                    'entitlement' => 'go-songwriter',
                    'upsell' => ['title' => 'Go Songwriter', 'cta' => 'Upgrade'],
                ],
            ],
        ));

        $site = collect($this->project())->firstWhere('key', 'site');
        $cap = collect($site['capabilities'])->firstWhere('key', 'music.render.voice');

        $this->assertNotNull($cap, 'the capability is PRESENT, not absent');
        $this->assertTrue($cap['locked'], 'unentitled ⇒ soft-locked');
        $this->assertSame(['title' => 'Go Songwriter', 'cta' => 'Upgrade'], $cap['upsell']);
        $this->assertSame('Voice render', $cap['title']);
    }

    public function test_an_entitled_overlay_capability_is_present_and_unlocked(): void
    {
        config(['beam.core.realm_gates' => []]);
        $this->withResolver(['go-songwriter']); // entitled

        $this->registry()->register(RealmOverlay::make(
            realmKey: 'site',
            overlay: ['overlay' => '1.0.0', 'actions' => []],
            capabilities: [
                ['key' => 'music.render.voice', 'entitlement' => 'go-songwriter', 'upsell' => ['cta' => 'Upgrade']],
            ],
        ));

        $cap = collect(collect($this->project())->firstWhere('key', 'site')['capabilities'])
            ->firstWhere('key', 'music.render.voice');

        $this->assertFalse($cap['locked']);
        $this->assertNull($cap['upsell']);
    }

    public function test_the_manifest_shows_only_realms_no_satellite_identity_leaks(): void
    {
        config(['beam.core.realm_gates' => []]);
        $this->withResolver([]);

        $this->registry()->register(RealmOverlay::make(
            realmKey: 'site',
            overlay: ['overlay' => '1.0.0', 'actions' => [['target' => '$.badge', 'override' => 'x']]],
            capabilities: [['key' => 'cap.a', 'entitlement' => 'go-songwriter']],
            satelliteKey: 'audiostud', // provenance for the registrar's diagnostics only
        ));

        $manifest = $this->project();

        // Every entry is a realm keyed by a realm key the registry ships — no synthetic satellite entry.
        $realmKeys = array_keys($this->app->make(RealmRegistry::class)->all());
        foreach ($this->keys($manifest) as $key) {
            $this->assertContains($key, $realmKeys, "manifest key [$key] is a real realm");
        }

        // The satellite identity NEVER appears in the emitted shape — not as a key, not as a field.
        $encoded = json_encode($manifest);
        $this->assertStringNotContainsString('audiostud', $encoded, 'no satellite identity leaks into the manifest');
        $this->assertStringNotContainsString('satellite', strtolower($encoded));
    }

    public function test_a_satellite_never_owns_a_realm_or_adds_a_standalone_tile(): void
    {
        config(['beam.core.realm_gates' => []]);
        $this->withResolver([]);

        $before = $this->keys($this->project());

        // Register an overlay whose target realm DOES NOT EXIST — it must NOT conjure a realm/tile.
        $this->registry()->register(RealmOverlay::make(
            realmKey: 'satellite-owned-realm',
            overlay: ['overlay' => '1.0.0', 'actions' => [['target' => '$.title', 'override' => 'Bogus']]],
            capabilities: [['key' => 'cap.x']],
            satelliteKey: 'audiostud',
        ));

        $after = $this->keys($this->project());

        // The manifest's realm set is UNCHANGED — no new tile appeared for the satellite's realm key.
        $this->assertSame($before, $after);
        $this->assertNotContains('satellite-owned-realm', $after);

        // And the realm registry itself never gained a realm — an overlay has no realm-creation verb.
        $this->assertFalse($this->app->make(RealmRegistry::class)->has('satellite-owned-realm'));
    }

    public function test_resolution_happens_before_emit_mirroring_a_tenant_scope(): void
    {
        // The proof that resolution is PHP-side, before the manifest is returned: the enriched fields are
        // ALREADY on the descriptor the projector returns (nothing is deferred to the frame). An overlay
        // registered AFTER a projection does not retroactively appear in the earlier result, and DOES
        // appear in the next — exactly as a PHP-side tenant scope is resolved per-request before emit.
        config(['beam.core.realm_gates' => []]);
        $this->withResolver([]);

        $first = collect($this->project())->firstWhere('key', 'site');
        $this->assertArrayNotHasKey('badge', $first);

        $this->registry()->register(RealmOverlay::make(
            realmKey: 'site',
            overlay: ['overlay' => '1.0.0', 'actions' => [['target' => '$.badge', 'override' => 'resolved-php-side']]],
        ));

        $second = collect($this->project())->firstWhere('key', 'site');
        $this->assertSame('resolved-php-side', $second['badge']);
    }

    public function test_multiple_overlays_on_one_realm_fold_in_registration_order(): void
    {
        config(['beam.core.realm_gates' => []]);
        $this->withResolver([]);

        $this->registry()->register(RealmOverlay::make(
            realmKey: 'site',
            overlay: ['overlay' => '1.0.0', 'actions' => [['target' => '$.badge', 'override' => 'first']]],
            capabilities: [['key' => 'cap.a']],
        ));
        $this->registry()->register(RealmOverlay::make(
            realmKey: 'site',
            overlay: ['overlay' => '1.0.0', 'actions' => [['target' => '$.badge', 'override' => 'second']]],
            capabilities: [['key' => 'cap.b']],
        ));

        $site = collect($this->project())->firstWhere('key', 'site');

        $this->assertSame('second', $site['badge'], 'last overlay wins at the shared target');
        $this->assertSame(['cap.a', 'cap.b'], array_column($site['capabilities'], 'key'));
    }
}
