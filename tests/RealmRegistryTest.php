<?php

namespace Splicewire\Beam\Tests;

use Splicewire\Beam\Realm\Attributes\SiteRealm;
use Splicewire\Beam\Realm\RealmRegistry;

/**
 * The beam realm kit (ADR-0156, ADR-0165). Asserts the four base realms — admin·tenant·user·site — and
 * the S3 `site` realm's public, unguarded, root-mounted shape (beamux-entry-charter S3): public content
 * is not an authenticated surface, so it is a distinct realm from `tenant`.
 */
class RealmRegistryTest extends TestCase
{
    public function test_the_public_site_realm_is_registered_root_mounted_and_unguarded(): void
    {
        $registry = new RealmRegistry;

        $this->assertTrue($registry->has('site'));

        $site = $registry->resolve('site');

        // Public content realm: rooted at the domain `/`, unguarded by default, not the central console.
        $this->assertSame('site', $site->key);
        $this->assertSame('/', $site->routeBase);
        $this->assertNull($site->guard, 'the site realm is unguarded by default (public content)');
        $this->assertFalse($site->central);
    }

    public function test_site_coexists_with_the_three_base_realms_distinct_from_tenant(): void
    {
        $registry = new RealmRegistry;

        // All four base realms present.
        foreach (['admin', 'tenant', 'user', 'site'] as $key) {
            $this->assertArrayHasKey($key, $registry->all());
        }

        // site and tenant share the `/` route base but are DISTINCT realms (public vs authenticated).
        $this->assertNotSame($registry->resolve('site')->guard, 'root');
        $this->assertNull($registry->resolve('tenant')->guard);
        $this->assertNull($registry->resolve('site')->guard);
        $this->assertNotSame('tenant', $registry->resolve('site')->key);
    }

    public function test_a_site_realm_marker_class_registers_via_the_preset_attribute(): void
    {
        $registry = new RealmRegistry;
        $registry->registerClass(PreviewSiteRealmMarker::class);

        // last-wins by key: the host preset overrides the base `site` realm's route base.
        $this->assertSame('/preview', $registry->resolve('site')->routeBase);
        $this->assertNull($registry->resolve('site')->guard);
    }
}

#[SiteRealm(routeBase: '/preview')]
class PreviewSiteRealmMarker {}
