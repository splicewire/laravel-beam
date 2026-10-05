<?php

namespace Splicewire\Beam\Tests\Ia;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Rushing\PermissionCascade\Contracts\EntitlementResolver;
use Splicewire\Beam\Ia\HostIa;
use Splicewire\Beam\Ia\HostRealmsData;
use Splicewire\Beam\Realm\RealmManifestProjector;
use Splicewire\Beam\Tests\Entitlements\FakeEntitlementResolver;
use Splicewire\Beam\Tests\TestCase;

/**
 * ux-walkthrough UX-05 (M1, M2): each host declares its IA once, as a realm profile in `beam.core.realms.{key}`
 * (`{label, home, surface, gate}`), and `HostIa` projects it per principal as `HostRealmsData`. The profile subsumes
 * `beam.core.realm_gates`, which stays readable as a deprecated alias.
 */
class HostIaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['beam.core.realm_gates' => []]);
        $this->app->instance(EntitlementResolver::class, new FakeEntitlementResolver([]));
    }

    /** The kit homes, mounted as a starter mounts them, optionally under a host prefix. */
    private function mountHomes(string $prefix = ''): void
    {
        Route::prefix($prefix)->group(function () {
            Route::get('/', fn () => 'site')->name('home');
            Route::get('dashboard', fn () => 'app')->name('dashboard');
            Route::get('settings/profile', fn () => 'settings')->name('profile.edit');
            Route::get('operator', fn () => 'operator')->name('operator.home');
        });
        app('router')->getRoutes()->refreshNameLookups();
    }

    private function ia(): HostIa
    {
        return $this->app->make(HostIa::class);
    }

    private function row(HostRealmsData $data, string $key): ?array
    {
        return collect($data->toArray()['realms'])->firstWhere('key', $key);
    }

    public function test_the_kit_profile_names_each_realm_once(): void
    {
        $this->mountHomes();

        $realms = collect($this->ia()->realms(null)->toArray()['realms'])->mapWithKeys(fn ($r) => [$r['key'] => [$r['label'], $r['surface'], $r['href']]]);

        $this->assertSame([
            'operator' => ['Operator', 'app', '/operator'],
            'tenant' => ['App', 'app', '/dashboard'],
            'site' => ['Site', 'site', '/'],
            'user' => ['Settings', 'app', '/settings/profile'],
        ], $realms->all());
    }

    public function test_a_host_declares_a_label_and_a_home_by_route_name(): void
    {
        $this->mountHomes();
        Route::get('studio', fn () => 'studio')->name('studio.home');
        app('router')->getRoutes()->refreshNameLookups();
        config(['beam.core.realms.tenant' => ['label' => 'Studio', 'home' => 'studio.home']]);

        $this->assertSame('/studio', $this->ia()->home('tenant'));
        $this->assertSame(['key' => 'tenant', 'label' => 'Studio', 'href' => '/studio', 'surface' => 'app', 'locked' => false, 'upsell' => null], $this->row($this->ia()->realms(null), 'tenant'));
    }

    public function test_home_is_minted_from_the_route_with_the_host_prefix(): void
    {
        $this->mountHomes('acme');

        $this->assertSame('/acme/dashboard', $this->ia()->home('tenant'));
        $this->assertSame('/acme/operator', $this->ia()->home('operator'));
    }

    public function test_home_refuses_a_realm_the_kit_does_not_have(): void
    {
        $this->expectExceptionMessage('[account] is not a registered realm.');
        $this->ia()->home('account');
    }

    public function test_home_refuses_a_home_route_this_host_has_not_mounted(): void
    {
        $this->expectExceptionMessage("The [operator] realm's home route [operator.home] is not mounted at this host.");
        $this->ia()->home('operator');
    }

    public function test_a_realm_whose_home_is_not_mounted_offers_no_door(): void
    {
        Route::get('dashboard', fn () => 'app')->name('dashboard');
        app('router')->getRoutes()->refreshNameLookups();

        $keys = array_column($this->ia()->realms(null)->toArray()['realms'], 'key');

        $this->assertSame(['tenant'], $keys);
    }

    /** @return iterable<string, array{array, list<string>}> */
    public static function gates(): iterable
    {
        yield 'hard, unheld' => [['operator' => ['entitlement' => 'os.operate', 'mode' => 'hard']], []];
        yield 'hard, held' => [['operator' => ['entitlement' => 'os.operate', 'mode' => 'hard']], ['os.operate']];
        yield 'soft, unheld' => [['tenant' => ['entitlement' => 'go', 'mode' => 'soft', 'upsell' => ['cta' => 'Upgrade']]], []];
        yield 'soft, held' => [['tenant' => ['entitlement' => 'go', 'mode' => 'soft', 'upsell' => ['cta' => 'Upgrade']]], ['go']];
    }

    #[DataProvider('gates')]
    public function test_gating_is_the_projectors_whether_declared_on_the_profile_or_the_deprecated_alias(array $gates, array $held): void
    {
        $this->mountHomes();
        $this->app->instance(EntitlementResolver::class, new FakeEntitlementResolver($held));

        foreach (['profile', 'alias'] as $where) {
            config(['beam.core.realm_gates' => [], 'beam.core.realms' => ['classes' => []]]);
            foreach ($gates as $key => $gate) {
                $where === 'profile'
                    ? config(["beam.core.realms.{$key}.gate" => $gate])
                    : config(["beam.core.realm_gates.{$key}" => $gate]);
            }

            $projected = collect($this->app->make(RealmManifestProjector::class)->project(null))
                ->map(fn ($r) => [$r['key'], $r['locked'], $r['upsell']])->values()->all();
            $ia = collect($this->ia()->realms(null)->toArray()['realms'])
                ->map(fn ($r) => [$r['key'], $r['locked'], $r['upsell']])->values()->all();

            $this->assertSame($projected, $ia, "{$where}: HostIa and the projector disagree");
        }
    }

    public function test_the_profile_gate_wins_over_the_deprecated_alias(): void
    {
        $this->mountHomes();
        config([
            'beam.core.realm_gates.operator' => ['entitlement' => 'os.operate', 'mode' => 'hard'],
            'beam.core.realms.operator.gate' => ['entitlement' => 'os.operate', 'mode' => 'soft'],
        ]);

        $this->assertTrue($this->row($this->ia()->realms(null), 'operator')['locked']);
    }

    public function test_current_is_the_longest_route_base_then_the_longest_home_prefix(): void
    {
        $this->mountHomes();
        $ia = $this->ia();

        $this->assertSame('operator', $ia->realms(null, '/operator/tenants')->current);
        $this->assertSame('user', $ia->realms(null, '/settings/profile')->current);
        // tenant and site share routeBase '/': the longest home-route prefix decides (lead default 04:42Z).
        $this->assertSame('tenant', $ia->realms(null, '/dashboard')->current);
        $this->assertSame('site', $ia->realms(null, '/about')->current);
        $this->assertSame('site', $ia->realms(null, '/')->current);
        $this->assertNull($ia->realms(null)->current);
    }

    public function test_an_unresolvable_tie_is_null_not_a_guess(): void
    {
        $this->mountHomes();
        config(['beam.core.realms.tenant.home' => 'home']); // both realms at '/', both homes '/'

        $this->assertNull($this->ia()->realms(null, '/about')->current);
    }

    public function test_back_leads_from_a_non_default_app_realm_to_the_workspace(): void
    {
        $this->mountHomes();
        $ia = $this->ia();

        $this->assertSame(['label' => 'App', 'href' => '/dashboard'], $ia->realms(null, '/operator')->toArray()['back']);
        $this->assertSame(['label' => 'App', 'href' => '/dashboard'], $ia->realms(null, '/settings/profile')->toArray()['back']);
        $this->assertNull($ia->realms(null, '/dashboard')->back);
        $this->assertNull($ia->realms(null, '/about')->back);
    }

    public function test_classes_is_reserved_for_realm_markers_and_never_read_as_a_realm(): void
    {
        $this->mountHomes();
        config(['beam.core.realms.classes' => []]);

        $this->assertNotContains('classes', array_column($this->ia()->realms(null)->toArray()['realms'], 'key'));
    }
}
