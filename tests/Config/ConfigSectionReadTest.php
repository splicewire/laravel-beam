<?php

namespace Splicewire\Beam\Tests\Config;

use Schemastud\DataSchemas\Migration\AcceptanceGate;
use Splicewire\Beam\Config\ConfigEffect;
use Splicewire\Beam\Config\ConfigFileScanner;
use Splicewire\Beam\Config\ConfigSectionReader;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Config\ConfigSectionSchema;
use Splicewire\Beam\Config\ConfigTier;
use Splicewire\Beam\Config\RegisteredConfigSection;
use Splicewire\Beam\Data\Config\ConfigKeyData;
use Splicewire\Beam\Data\Config\ConfigSectionData;
use Splicewire\Beam\Tests\Fixtures\Config\GaugesSection;
use Splicewire\Beam\Tests\Fixtures\Config\WidgetsSection;
use Splicewire\Beam\Tests\TestCase;

/**
 * A registered Config Section read end to end, the way `beam-config` serves it: `config()` as a host's merged
 * config holds it, the package file, and the host file's syntax tree, folded into one row with provenance.
 *
 * The fixtures mirror the two shapes the flagship needs: a record-shaped package root a host partially overrides
 * (`widgets`), and a host-only map keyed by dotted names (`fleet.gauges`, shaped like `app.meters`).
 */
class ConfigSectionReadTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../Fixtures/Config';

    protected function setUp(): void
    {
        parent::setUp();

        // What mergeConfigFrom() leaves in config(): the host's top-level keys over the package's, shallow.
        config()->set('widgets', array_merge(require self::FIXTURES.'/package/widgets.php', require self::FIXTURES.'/host/widgets.php'));
        config()->set('fleet.gauges', require self::FIXTURES.'/host/fleet/gauges.php');

        $this->app->when(ConfigSectionReader::class)->needs('$configDirectory')->give(realpath(self::FIXTURES.'/host'));

        app(ConfigSectionRegistry::class)
            ->section(WidgetsSection::class, owner: 'acme/widgets', packageFile: realpath(self::FIXTURES.'/package/widgets.php'))
            ->section(GaugesSection::class, owner: 'host');
    }

    private function read(string $root): ConfigSectionData
    {
        return app(ConfigSectionReader::class)->read(app(ConfigSectionRegistry::class)->get($root));
    }

    /** @return array<string, ConfigKeyData> */
    private function keys(ConfigSectionData $row): array
    {
        return array_column(array_map(fn (ConfigKeyData $key) => [$key->pointer, $key], $row->keys), 1, 0);
    }

    public function test_the_registry_holds_one_section_per_root_ordered_by_root(): void
    {
        $registry = app(ConfigSectionRegistry::class);

        $this->assertSame(['fleet.gauges', 'widgets'], array_map(fn (RegisteredConfigSection $s) => $s->root(), $registry->all()));
        $this->assertNull($registry->get('no-such-root'));
        $this->assertNull($registry->get('not a key'), 'a root the key grammar refuses is absence, not an error');

        // A second registration for a root supersedes: a host replaces a package's section without forking it.
        $registry->section(WidgetsSection::class, owner: 'host');
        $this->assertSame('host', $registry->get('widgets')->owner);
        $this->assertCount(2, $registry->all());
    }

    public function test_a_host_key_shadowing_the_package_is_distinguished_from_package_and_host(): void
    {
        $row = $this->read('widgets');
        $keys = $this->keys($row);

        $this->assertSame(ConfigTier::Package, $keys['/enabled']->tier);
        $this->assertFalse($keys['/enabled']->hostShadowsPackage);

        $this->assertSame(ConfigTier::Host, $keys['/limits/per_page']->tier);
        $this->assertTrue($keys['/limits/per_page']->hostShadowsPackage);
        $this->assertSame(50, $keys['/limits/per_page']->value);

        $this->assertSame(['/limits/max'], $row->shadowedPackageKeys);
        $this->assertArrayNotHasKey('/limits/max', $keys);

        $this->assertSame('acme/widgets', $row->owner);
        $this->assertStringEndsWith('package/widgets.php', $row->files->package);
        $this->assertStringEndsWith('host/widgets.php', $row->files->host);
    }

    public function test_each_key_names_the_env_binding_of_the_tier_that_set_it(): void
    {
        $keys = $this->keys($this->read('widgets'));

        // `enabled` is the package's, fed by the package file's env() call.
        $this->assertSame('BEAM_FIXTURE_WIDGETS_ENABLED', $keys['/enabled']->env?->name);
        $this->assertStringEndsWith('package/widgets.php', $keys['/enabled']->env->file);
        $this->assertSame(5, $keys['/enabled']->env->line);
        $this->assertFalse($keys['/enabled']->env->set);

        // `driver` is the host's, and its binding is the host file's — not the package's literal.
        $this->assertSame('BEAM_FIXTURE_WIDGETS_DRIVER', $keys['/driver']->env?->name);
        $this->assertStringEndsWith('host/widgets.php', $keys['/driver']->env->file);

        $this->assertNull($keys['/limits/per_page']->env);
    }

    public function test_an_env_var_that_is_set_reads_set(): void
    {
        putenv('BEAM_FIXTURE_WIDGETS_DRIVER=redis');

        try {
            $this->assertTrue($this->keys($this->read('widgets'))['/driver']->env->set);
        } finally {
            putenv('BEAM_FIXTURE_WIDGETS_DRIVER');
        }
    }

    public function test_under_config_cache_an_unseen_variable_is_unknown_never_file_default(): void
    {
        // Cached config means .env was never loaded, so absence from the process proves nothing.
        $this->app->when(ConfigSectionReader::class)->needs('$configCached')->give(true);

        $this->assertNull($this->keys($this->read('widgets'))['/driver']->env->set);

        putenv('BEAM_FIXTURE_WIDGETS_DRIVER=redis');

        try {
            $this->assertTrue($this->keys($this->read('widgets'))['/driver']->env->set, 'a variable the process sees is set, cached or not');
        } finally {
            putenv('BEAM_FIXTURE_WIDGETS_DRIVER');
        }
    }

    public function test_a_map_root_with_dotted_names_reads_host_provenance_per_leaf(): void
    {
        $row = $this->read('fleet.gauges');
        $keys = $this->keys($row);

        $this->assertSame([
            '/openai.tokens/label', '/openai.tokens/unit',
            '/google.cse.query/label', '/google.cse.query/unit', '/google.cse.query/unit_cost_usd',
        ], array_keys($keys));

        foreach ($keys as $key) {
            $this->assertSame(ConfigTier::Host, $key->tier);
            $this->assertFalse($key->hostShadowsPackage);
            $this->assertSame(ConfigEffect::Deploy, $key->effect);
            $this->assertFalse($key->overridable);
        }

        $this->assertSame('BEAM_FIXTURE_CSE_COST_USD', $keys['/google.cse.query/unit_cost_usd']->env?->name);
        $this->assertSame(0.005, $keys['/google.cse.query/unit_cost_usd']->value);
        $this->assertNull($row->files->package);
        $this->assertSame('host', $row->owner);
        $this->assertSame(5, $row->counts->keys);
        $this->assertSame(1, $row->counts->envBound);
        $this->assertSame(5, $row->counts->deploy);
        $this->assertSame(0, $row->counts->overridable);
    }

    public function test_declarations_reach_each_key_and_default_conservatively(): void
    {
        $keys = $this->keys($this->read('widgets'));

        $this->assertSame(ConfigTier::Operator, $keys['/enabled']->ceiling);
        $this->assertSame(ConfigEffect::Live, $keys['/enabled']->effect);
        $this->assertTrue($keys['/enabled']->overridable);

        // Undeclared: not overridable, deploy-only, not secret.
        $this->assertSame(ConfigTier::Host, $keys['/driver']->ceiling);
        $this->assertSame(ConfigEffect::Deploy, $keys['/driver']->effect);
        $this->assertFalse($keys['/driver']->overridable);
        $this->assertFalse($keys['/driver']->secret);
    }

    public function test_a_secret_is_set_or_not_set_and_its_value_never_leaves(): void
    {
        config()->set('widgets.api_key', 'sk-fixture-not-a-real-key');
        $row = $this->read('widgets');
        $key = $this->keys($row)['/api_key'];

        $this->assertTrue($key->secret);
        $this->assertTrue($key->set);
        $this->assertNull($key->value);
        $this->assertNull($row->values['api_key']);
        $this->assertStringNotContainsString('sk-fixture', json_encode($row->toArray()));
    }

    public function test_a_root_living_as_a_key_of_a_parent_file_is_scanned_inside_it(): void
    {
        $scan = (new ConfigFileScanner)->scan(self::FIXTURES.'/host/fleet.php', ['retrieval']);

        $this->assertSame(['weight', 'mode'], $scan->keys);
        $this->assertSame(['/weight'], array_keys($scan->bindings));
        $this->assertSame('BEAM_FIXTURE_RETRIEVAL_WEIGHT', $scan->bindings['/weight']->name);
    }

    public function test_a_file_the_scan_cannot_read_reports_absence(): void
    {
        $this->assertNull((new ConfigFileScanner)->scan(self::FIXTURES.'/host/missing.php')->keys);
    }

    public function test_the_row_serves_its_section_schema_beside_its_values(): void
    {
        $row = $this->read('fleet.gauges');

        $this->assertSame(ConfigSectionSchema::id('fleet.gauges'), $row->schema['$id']);
        $this->assertSame('object', $row->schema['type']);
        $entry = $row->schema['additionalProperties'];
        $this->assertSame('Label', $entry['properties']['label']['title']);
        $this->assertSame(['ceiling' => 'host', 'effect' => 'deploy', 'secret' => false, 'merge' => 'replace'], $entry['properties']['label'][ConfigSectionSchema::KEYWORD]);
        $this->assertNotContains('unit_cost_usd', $entry['required'] ?? [], 'an Optional key is not required');
    }

    public function test_a_record_section_schema_types_its_own_properties(): void
    {
        $schema = app(ConfigSectionSchema::class)->for(WidgetsSection::class);

        $this->assertSame('Widgets', $schema['title']);
        $this->assertSame('How widgets render.', $schema['description']);
        $this->assertSame(['ceiling' => 'operator', 'effect' => 'live', 'secret' => false, 'merge' => 'replace'], $schema['properties']['enabled'][ConfigSectionSchema::KEYWORD]);
        $this->assertTrue($schema['properties']['api_key'][ConfigSectionSchema::KEYWORD]['secret']);
    }

    public function test_the_package_defaults_validate_against_their_section(): void
    {
        $gate = app(AcceptanceGate::class);
        $schemas = app(ConfigSectionSchema::class);

        $this->assertTrue($gate->accepts(require self::FIXTURES.'/package/widgets.php', $schemas->for(WidgetsSection::class)));
        $this->assertTrue($gate->accepts(require self::FIXTURES.'/host/fleet/gauges.php', $schemas->for(GaugesSection::class)));

        // …and the check can fail: a default of the wrong type is refused.
        $this->assertFalse($gate->accepts(['openai.tokens' => ['label' => 3, 'unit' => 'token']], $schemas->for(GaugesSection::class)));
    }
}
