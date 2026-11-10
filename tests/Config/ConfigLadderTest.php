<?php

namespace Splicewire\Beam\Tests\Config;

use PHPUnit\Framework\TestCase;
use Splicewire\Beam\Config\ConfigLadder;
use Splicewire\Beam\Config\ConfigLayerValue;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * The ladder is a pure fold, so here the fold IS the external behaviour: tiers in, effective values and per-key
 * provenance out. No container; every input is literal.
 */
class ConfigLadderTest extends TestCase
{
    private const PACKAGE = [
        'enabled' => true,
        'limits' => ['per_page' => 20, 'max' => 100],
        'driver' => 'array',
    ];

    /** What mergeConfigFrom() produces: array_merge($package, $host), shallow. */
    private function merged(array $host): array
    {
        return array_merge(self::PACKAGE, $host);
    }

    public function test_the_tiers_are_ordered_package_host_provider_operator_tenant(): void
    {
        $this->assertSame(
            ['package', 'host', 'provider', 'operator', 'tenant'],
            array_map(fn (ConfigTier $tier): string => $tier->value, ConfigTier::cases()),
        );
        $this->assertLessThan(ConfigTier::Host->rank(), ConfigTier::Package->rank());
        $this->assertLessThan(ConfigTier::Tenant->rank(), ConfigTier::Operator->rank());
        $this->assertFalse(ConfigTier::Host->isOverridable());
        $this->assertTrue(ConfigTier::Operator->isOverridable());
        $this->assertTrue(ConfigTier::Tenant->admits(ConfigTier::Operator));
        $this->assertFalse(ConfigTier::Operator->admits(ConfigTier::Tenant));
    }

    public function test_a_key_only_the_package_sets_reads_package(): void
    {
        $resolution = ConfigLadder::fold(self::PACKAGE, hostKeys: null, observed: self::PACKAGE, packageSource: 'pkg.php');

        $this->assertSame(self::PACKAGE, $resolution->values);
        $this->assertSame(['/enabled', '/limits/per_page', '/limits/max', '/driver'], array_keys($resolution->keys));

        foreach ($resolution->keys as $key) {
            $this->assertSame(ConfigTier::Package, $key->tier);
            $this->assertFalse($key->hostShadowsPackage);
            $this->assertCount(1, $key->layers);
            $this->assertSame('pkg.php', $key->layers[0]->source);
        }

        $this->assertSame([], $resolution->shadowedPackageKeys);
    }

    public function test_a_top_level_host_key_wins_and_shadows_the_package_whole(): void
    {
        $host = ['limits' => ['per_page' => 50], 'driver' => 'redis'];
        $resolution = ConfigLadder::fold(self::PACKAGE, array_keys($host), $this->merged($host), 'pkg.php', 'host.php');

        $perPage = $resolution->keys['/limits/per_page'];
        $this->assertSame(50, $perPage->value);
        $this->assertSame(ConfigTier::Host, $perPage->tier);
        $this->assertTrue($perPage->hostShadowsPackage);
        $this->assertEquals([
            new ConfigLayerValue(ConfigTier::Package, 20, 'pkg.php', shadowed: true),
            new ConfigLayerValue(ConfigTier::Host, 50, 'host.php'),
        ], $perPage->layers);

        // The package's `limits.max` never reaches config(): the host's top-level `limits` replaced the whole map.
        $this->assertArrayNotHasKey('/limits/max', $resolution->keys);
        $this->assertSame(['/limits/max'], $resolution->shadowedPackageKeys);

        $this->assertSame(ConfigTier::Host, $resolution->keys['/driver']->tier);
        $this->assertSame(ConfigTier::Package, $resolution->keys['/enabled']->tier);
        $this->assertFalse($resolution->keys['/enabled']->hostShadowsPackage);
    }

    public function test_a_host_only_root_reads_host_with_no_shadowing(): void
    {
        $host = ['google.cse.query' => ['label' => 'Google web search', 'unit_cost_usd' => 0.005]];
        $resolution = ConfigLadder::fold(null, array_keys($host), $host, hostSource: 'config/app/meters.php');

        $this->assertSame(['/google.cse.query/label', '/google.cse.query/unit_cost_usd'], array_keys($resolution->keys));
        $key = $resolution->keys['/google.cse.query/unit_cost_usd'];
        $this->assertSame(ConfigTier::Host, $key->tier);
        $this->assertFalse($key->hostShadowsPackage);
        $this->assertSame(0.005, $key->value);
    }

    public function test_a_value_neither_file_holds_is_a_provider_write(): void
    {
        $observed = [...self::PACKAGE, 'driver' => 'written-at-boot', 'registered' => ['a']];
        $resolution = ConfigLadder::fold(self::PACKAGE, hostKeys: [], observed: $observed, packageSource: 'pkg.php');

        $driver = $resolution->keys['/driver'];
        $this->assertSame(ConfigTier::Provider, $driver->tier);
        $this->assertSame('written-at-boot', $driver->value);
        $this->assertSame([ConfigTier::Package, ConfigTier::Provider], array_map(fn (ConfigLayerValue $l) => $l->tier, $driver->layers));

        $this->assertSame(ConfigTier::Provider, $resolution->keys['/registered']->tier);
        $this->assertSame($observed, $resolution->values, 'effective values are always the observed ones');
    }

    public function test_a_key_a_provider_removed_is_reported_removed(): void
    {
        $observed = self::PACKAGE;
        unset($observed['driver']);
        $resolution = ConfigLadder::fold(self::PACKAGE, [], $observed);

        $driver = $resolution->keys['/driver'];
        $this->assertSame(ConfigTier::Provider, $driver->tier);
        $this->assertNull($driver->value);
        $this->assertTrue($driver->layers[1]->removed);
    }

    public function test_an_env_bound_package_value_that_differs_is_the_envs_not_a_providers(): void
    {
        // Under config:cache the re-required package file sees no .env, so its env() default differs from config().
        $observed = [...self::PACKAGE, 'enabled' => false];
        $resolution = ConfigLadder::fold(self::PACKAGE, [], $observed, envBound: ['/enabled']);

        $this->assertSame(ConfigTier::Package, $resolution->keys['/enabled']->tier);
        $this->assertFalse($resolution->keys['/enabled']->value);
    }

    public function test_dotted_and_slashed_keys_are_one_pointer_segment(): void
    {
        $observed = ['a.b' => ['c/d' => 1, 'e~f' => 2]];
        $resolution = ConfigLadder::fold(null, ['a.b'], $observed);

        $this->assertSame(['/a.b/c~1d', '/a.b/e~0f'], array_keys($resolution->keys));
    }

    public function test_a_list_is_one_value(): void
    {
        $resolution = ConfigLadder::fold(['hosts' => ['a', 'b'], 'empty' => []], null, ['hosts' => ['a', 'b'], 'empty' => []]);

        $this->assertSame(['/hosts', '/empty'], array_keys($resolution->keys));
        $this->assertSame(['a', 'b'], $resolution->keys['/hosts']->value);
    }
}
