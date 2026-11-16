<?php

namespace Splicewire\Beam\Tests\Config;

use Splicewire\Beam\Config\ConfigSectionReader;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Data\Config\ConfigKeyData;
use Splicewire\Beam\Data\Config\ConfigSectionData;
use Splicewire\Beam\Localization\DeclaredText;
use Splicewire\Beam\Localization\TextNamespaces;
use Splicewire\Beam\Tests\Fixtures\Config\GaugesSection;
use Splicewire\Beam\Tests\Fixtures\Config\WidgetLimits;
use Splicewire\Beam\Tests\Fixtures\Config\WidgetsSection;
use Splicewire\Beam\Tests\TestCase;

/**
 * A Config Section's OWN declaration text (`ConfigRoot` title and description) resolves through ADR-0244's `config`
 * derived-key kind at the `beam-config` read surface (config-sections 14; localization 41): `<ns>::config.<root with its
 * dots as />.<slot>`, owned by the winning registration, with the declared text as the fallback. Nested Data class
 * titles keep the data rule; config VALUES never pass through the resolver (localization 27).
 */
class ConfigSectionDeclarationTextTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../Fixtures/Config';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('widgets', array_merge(require self::FIXTURES.'/package/widgets.php', require self::FIXTURES.'/host/widgets.php'));
        config()->set('fleet.gauges', require self::FIXTURES.'/host/fleet/gauges.php');
        $this->app->when(ConfigSectionReader::class)->needs('$configDirectory')->give(realpath(self::FIXTURES.'/host'));

        $this->app->instance(ConfigSectionRegistry::class, new ConfigSectionRegistry);
        app(ConfigSectionRegistry::class)
            ->section(WidgetsSection::class, owner: 'host')
            ->section(GaugesSection::class, owner: 'host');
    }

    private function read(string $root): ConfigSectionData
    {
        return app(ConfigSectionReader::class)->read(app(ConfigSectionRegistry::class)->get($root));
    }

    /**
     * Write translation lines for full derived keys (`ns::group.item…` or `group.item…`) into a fresh lang directory: a
     * host key under `<lang>/<locale>/<group>.php`, a package key under `<lang>/vendor/<ns>/<locale>/<group>.php`.
     *
     * @param  array<string, string>  $lines
     */
    private function lines(array $lines, string $locale = 'es'): void
    {
        $lang = sys_get_temp_dir().'/beam-cs14-lang-'.getmypid().'-'.bin2hex(random_bytes(4));
        $files = [];

        foreach ($lines as $key => $text) {
            [$namespace, $rest] = str_contains($key, '::') ? explode('::', $key, 2) : [null, $key];
            [$group, $item] = explode('.', $rest, 2);
            $file = $namespace === null ? "{$lang}/{$locale}/{$group}.php" : "{$lang}/vendor/{$namespace}/{$locale}/{$group}.php";
            data_set($files[$file], $item, $text);
        }

        foreach ($files as $file => $content) {
            @mkdir(dirname($file), 0777, true);
            file_put_contents($file, '<?php return '.var_export($content, true).';');
        }

        $this->app->useLangPath($lang);
        $this->app->forgetInstance('translation.loader');
        $this->app->forgetInstance('translator');
    }

    /** @return array<string, mixed> pointer => value, every key of the row */
    private function values(ConfigSectionData $row): array
    {
        return array_column(array_map(fn (ConfigKeyData $key): array => [$key->pointer, $key->value], $row->keys), 1, 0);
    }

    public function test_the_section_text_resolves_under_the_config_kind_with_the_exact_key_shape_and_falls_back(): void
    {
        $this->assertSame('config.widgets.title', DeclaredText::derive('', 'config', ['widgets'], 'title'));
        $this->assertSame('beam-ux::config.beam/ux/releases.description', DeclaredText::derive('beam-ux::', 'config', ['beam.ux.releases'], 'description'));

        // No line: the declared text, in the row and at the served schema's root.
        $row = $this->read('widgets');
        $this->assertSame('Widgets', $row->title);
        $this->assertSame('How widgets render.', $row->description);
        $this->assertSame('Widgets', $row->schema['title']);

        // A dotted root keys with its dots written `/`.
        $this->lines(['config.fleet/gauges.title' => 'Indicadores']);
        $this->app->setLocale('es');
        $this->assertSame('Indicadores', $this->read('fleet.gauges')->title);
    }

    public function test_a_locale_line_changes_the_title_and_leaves_every_value_byte_identical(): void
    {
        $before = $this->read('widgets');

        $this->lines(['config.widgets.title' => 'Artilugios', 'config.widgets.description' => 'Cómo se muestran.']);
        $this->app->setLocale('es');
        $after = $this->read('widgets');

        $this->assertSame('Artilugios', $after->title);
        $this->assertSame('Cómo se muestran.', $after->description);
        $this->assertSame('Artilugios', $after->schema['title']);
        $this->assertSame('Cómo se muestran.', $after->schema['description']);
        $this->assertSame(json_encode($before->values), json_encode($after->values));
        $this->assertSame(json_encode($this->values($before)), json_encode($this->values($after)));

        // English keeps the declared text.
        $this->app->setLocale('en');
        $this->assertSame('Widgets', $this->read('widgets')->title);
    }

    public function test_nested_data_titles_keep_the_data_rule_not_the_config_kind(): void
    {
        $dataKey = app(DeclaredText::class)->key('data', WidgetLimits::class, 'title');
        $sectionDataKey = app(DeclaredText::class)->key('data', WidgetsSection::class, 'title');
        $this->assertNotNull($dataKey);

        $this->lines([$dataKey => 'Límites', $sectionDataKey => 'NOT the section title', 'config.widgets.title' => 'Artilugios']);
        $this->app->setLocale('es');
        $row = $this->read('widgets');

        // The nested class's title resolves under the data rule (its declaring class)...
        $limits = collect($row->schema['$defs'] ?? [])->first(fn (array $def): bool => ($def['title'] ?? null) === 'Límites');
        $this->assertNotNull($limits, 'WidgetLimits resolves under data.<class>.title');
        // ...while the section's own text resolves under the config kind, never the section class's data key.
        $this->assertSame('Artilugios', $row->title);
        $this->assertSame('Artilugios', $row->schema['title']);
    }

    /**
     * review-r1 (2026-11-16): PROPERTY titles keep ADR-0244's data rule, keyed by the property's DECLARING class
     * (`data.<C>.properties.<p>.title`), an inherited property by its parent; neither resolves under the `config` kind.
     */
    public function test_property_titles_resolve_under_the_data_rule_of_their_declaring_class(): void
    {
        config()->set('qa.inherited', ['mode' => 'fast', 'item' => ['size' => 1, 'label' => 'one']]);
        app(ConfigSectionRegistry::class)->section(\Splicewire\Beam\Tests\Fixtures\Config\InheritedTitleSection::class, owner: 'host');

        $text = app(DeclaredText::class);
        $own = $text->key('data', \Splicewire\Beam\Tests\Fixtures\Config\InheritedTitleSection::class, 'properties.mode.title');
        $inherited = $text->key('data', \Splicewire\Beam\Tests\Fixtures\Config\InheritedLabelBase::class, 'properties.label.title');
        $childKey = $text->key('data', \Splicewire\Beam\Tests\Fixtures\Config\InheritedLabelRecord::class, 'properties.label.title');
        $this->assertNotNull($own);
        $this->assertNotNull($inherited);

        $this->lines([
            $own => 'Modo',
            $inherited => 'Etiqueta',
            $childKey => 'NOT the inherited title (the child does not declare label)',
            // The section's config kind, and config-kind look-alikes for the properties, must NOT reach a property.
            'config.qa/inherited.title' => 'Títulos heredados',
            'config.qa/inherited.mode.title' => 'NOT a property title',
            'config.qa/inherited.properties.mode.title' => 'NOT a property title either',
        ]);
        $this->app->setLocale('es');
        $row = $this->read('qa.inherited');

        $this->assertSame('Títulos heredados', $row->title, 'the section text is the config kind');
        $this->assertSame('Modo', $row->schema['properties']['mode']['title'], 'the section class declares mode');

        $record = collect($row->schema['$defs'] ?? [])->first(fn (array $def): bool => isset($def['properties']['size'], $def['properties']['label']));
        $this->assertNotNull($record, 'the nested record is in the served schema');
        $this->assertSame('Etiqueta', $record['properties']['label']['title'], 'an inherited property is keyed by its DECLARING class');

        // Without any line, the declared titles stand.
        $this->app->setLocale('en');
        $plain = $this->read('qa.inherited');
        $this->assertSame('Mode', $plain->schema['properties']['mode']['title']);
    }

    public function test_the_winning_registration_owns_the_key(): void
    {
        $winner = 'splicewire/laravel-beam';
        $prefix = app(TextNamespaces::class)->ownerOfPackage($winner)->keyPrefix();
        $this->assertNotNull($prefix);
        $this->assertNotSame('', $prefix, 'a package owner keys under its translator namespace');

        // Re-registered by a second owner: the later registration supersedes, and keys under ITS namespace.
        app(ConfigSectionRegistry::class)->section(WidgetsSection::class, owner: $winner);
        $this->lines([$prefix.'config.widgets.title' => 'Del paquete', 'config.widgets.title' => 'Del host']);
        $this->app->setLocale('es');

        $this->assertSame('Del paquete', $this->read('widgets')->title);
    }

    public function test_no_config_value_passes_through_the_resolver(): void
    {
        // A string value that IS a translatable key, with a line for it, is served verbatim.
        config()->set('widgets.driver', 'config.widgets.title');
        $this->lines(['config.widgets.title' => 'Artilugios']);
        $this->app->setLocale('es');
        $row = $this->read('widgets');

        $this->assertSame('Artilugios', $row->title);
        $this->assertSame('config.widgets.title', $this->values($row)['/driver']);
        $this->assertSame('config.widgets.title', $row->values['driver']);
    }
}
