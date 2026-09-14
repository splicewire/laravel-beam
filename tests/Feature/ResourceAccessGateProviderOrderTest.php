<?php

namespace Splicewire\Beam\Tests\Feature;

use Illuminate\Support\ServiceProvider;
use Schemastud\Frame\Contracts\ResourceAccessGate;
use Schemastud\Frame\FrameServiceProvider;
use Schemastud\Frame\Registry\ResourceDefinition;
use Splicewire\Beam\Realm\RealmEntitlementResourceGate;
use Splicewire\Beam\Tests\TestCase;

/**
 * The provider-order trap (realm-dashboards 04 review): frame binds its permit-everything
 * `OpenResourceAccessGate` in register(), beam binds the realm gate in register(), and the one
 * registered LAST used to win — so a host whose discovery ran frame after beam served every realm
 * resource open. Frame's provider is deliberately listed AFTER beam's here, and a host provider after both.
 */
class ResourceAccessGateProviderOrderTest extends TestCase
{
    public static bool $hostOverrides = false;

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), FrameServiceProvider::class, HostGateProvider::class];
    }

    protected function tearDown(): void
    {
        self::$hostOverrides = false;

        parent::tearDown();
    }

    public function test_the_realm_gate_wins_whichever_provider_registered_last(): void
    {
        $this->assertInstanceOf(RealmEntitlementResourceGate::class, $this->app->make(ResourceAccessGate::class));
    }

    public function test_a_hosts_own_gate_is_not_frames_default_and_is_left_alone(): void
    {
        self::$hostOverrides = true;
        $this->refreshApplication();

        $this->assertInstanceOf(HostGate::class, $this->app->make(ResourceAccessGate::class));
    }
}

class HostGateProvider extends ServiceProvider
{
    public function register(): void
    {
        if (ResourceAccessGateProviderOrderTest::$hostOverrides) {
            $this->app->bind(ResourceAccessGate::class, HostGate::class);
        }
    }
}

class HostGate implements ResourceAccessGate
{
    public function allowsResource(ResourceDefinition $definition): bool
    {
        return false;
    }
}
