<?php

namespace Splicewire\Beam\Tests\Config;

use Illuminate\Support\Facades\Artisan;
use ReflectionClass;
use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Title;
use Schemastud\DataSchemas\Migration\AcceptanceGate;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Config\Attributes\ConfigDraft;
use Splicewire\Beam\Config\ConfigSectionCoverage;
use Splicewire\Beam\Config\ConfigSectionReader;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Config\ConfigSectionSchema;
use Splicewire\Beam\Data\Config\ConfigKeyData;
use Splicewire\Beam\Enums\ConfigEffect;
use Splicewire\Beam\Enums\ConfigTier;
use Splicewire\Beam\Tests\TestCase;

/**
 * `splicewire:beam:make:config-section` (config-sections ticket 06) against snapshots of two real roots:
 *
 * - `beam.ux`: laravel-beam-ux b1f4368 `config/beam/ux.php` (468 lines, mostly ADR banners);
 * - `app.chat`: splicewire-app b0c4ad10e `config/app/chat.php` (`default_model` is a key of the `models` map).
 *
 * Each run writes into its own temporary directory and namespace, so a class one test loads never collides with
 * another's.
 */
class ConfigSectionGeneratorTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../Fixtures/Config/Generator';

    private string $out;

    private string $namespace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->out = sys_get_temp_dir().'/cs06-gen-'.bin2hex(random_bytes(6));
        $this->namespace = 'Splicewire\\Beam\\Tests\\Generated\\G'.bin2hex(random_bytes(4));
        config()->set('beam.ux', require self::FIXTURES.'/beam-ux.php');
        config()->set('app.chat', require self::FIXTURES.'/app-chat.php');

        $this->app->instance(ConfigSectionRegistry::class, new ConfigSectionRegistry);
        $this->app->when(ConfigSectionReader::class)->needs('$configDirectory')->give($this->emptyDirectory());
    }

    protected function tearDown(): void
    {
        $this->removeOutput();

        parent::tearDown();
    }

    private function removeOutput(): void
    {
        foreach (glob($this->out.'/*.php') ?: [] as $file) {
            unlink($file);
        }

        @rmdir($this->out);
    }

    /** @param list<string> $extra */
    private function generate(string $root, string $file, array $extra = []): int
    {
        return Artisan::call('splicewire:beam:make:config-section', [
            'package' => 'host',
            'root' => $root,
            '--config' => $file,
            '--path' => $this->out,
            '--namespace' => $this->namespace,
            '--scan' => [self::FIXTURES.'/scan'],
            ...$extra,
        ]);
    }

    private function emptyDirectory(): string
    {
        $dir = sys_get_temp_dir().'/cs06-host-'.bin2hex(random_bytes(4));
        @mkdir($dir);

        return $dir;
    }

    /** @return class-string */
    private function class(string $short): string
    {
        return $this->namespace.'\\'.$short;
    }

    public function test_the_generator_emits_a_beam_ux_section_that_02s_read_path_accepts_unedited(): void
    {
        $this->assertSame(0, $this->generate('beam.ux', self::FIXTURES.'/beam-ux.php'), Artisan::output());
        $section = $this->class('BeamUxSection');
        $this->assertTrue(class_exists($section, false), 'the check loaded what it wrote');

        app(ConfigSectionRegistry::class)->section($section, owner: 'splicewire/laravel-beam-ux', packageFile: realpath(self::FIXTURES.'/beam-ux.php'));
        $row = app(ConfigSectionReader::class)->read(app(ConfigSectionRegistry::class)->get('beam.ux'));
        $keys = array_column(array_map(fn (ConfigKeyData $key) => [$key->pointer, $key], $row->keys), 1, 0);

        $this->assertSame(ConfigSectionSchema::id('beam.ux'), $row->schema['$id']);
        $this->assertArrayHasKey('/releases/review_required', $keys);
        $this->assertArrayHasKey('/compile/highlight/themes/dark', $keys);

        // Every key comes out deploy-only and not overridable.
        foreach ($keys as $pointer => $key) {
            $this->assertSame(ConfigEffect::Deploy, $key->effect, $pointer);
            $this->assertSame(ConfigTier::Host, $key->ceiling, $pointer);
            $this->assertFalse($key->overridable, $pointer);
        }

        $this->assertSame(0, $row->counts->overridable);
        $this->assertSame('BEAM_UX_RELEASES_REVIEW_REQUIRED', $keys['/releases/review_required']->env?->name);

        // 02's acceptance: the package defaults validate against the section, and every merged key is covered.
        $this->assertTrue(app(AcceptanceGate::class)->accepts(require self::FIXTURES.'/beam-ux.php', app(ConfigSectionSchema::class)->for($section)));
        $this->assertSame([], ConfigSectionCoverage::uncovered($section, config('beam.ux')));

        // The narrowest proven types: a cast proves bool, a literal int proves int, a bare env() with a bool default does not.
        $releases = new ReflectionClass($this->class('BeamUxReleasesConfig'));
        $this->assertSame('bool', (string) $releases->getProperty('review_required')->getType());
        $this->assertSame('?string', (string) $releases->getProperty('edit_ability')->getType());
        $this->assertSame('int', (string) (new ReflectionClass($this->class('BeamUxPreviewConfig')))->getProperty('link_days')->getType());
        $seedNav = (new ReflectionClass($section))->getProperty('seed_nav')->getType();
        $this->assertInstanceOf(\ReflectionUnionType::class, $seedNav);
        $names = array_map(fn (\ReflectionNamedType $type): string => $type->getName(), $seedNav->getTypes());
        sort($names);
        $this->assertSame(['bool', 'string'], $names);
    }

    public function test_beam_ux_and_app_chat_carry_a_title_and_description_per_key_and_default_model_is_a_combobox(): void
    {
        $this->assertSame(0, $this->generate('beam.ux', self::FIXTURES.'/beam-ux.php'), Artisan::output());
        $this->assertSame(0, $this->generate('app.chat', self::FIXTURES.'/app-chat.php'), Artisan::output());

        foreach (glob($this->out.'/*.php') ?: [] as $file) {
            $class = $this->class(basename($file, '.php'));

            // Promoted parameters' attributes land on the properties too; #[Title]/#[Description] target properties only.
            foreach (array_filter((new ReflectionClass($class))->getProperties(), fn (\ReflectionProperty $p): bool => $p->isPromoted()) as $property) {
                $where = $class.'::$'.$property->getName();
                $title = $property->getAttributes(Title::class)[0] ?? null;
                $description = $property->getAttributes(Description::class)[0] ?? null;
                $this->assertNotEmpty($title?->newInstance()->value, "{$where} has a title");
                $this->assertNotEmpty($description?->newInstance()->value, "{$where} has a description");
                $this->assertNotSame([], $property->getAttributes(ConfigDraft::class), "{$where} is marked a draft for rewrite");
            }
        }

        // A harvested description comes from the comment above the key, not the placeholder.
        $chat = app(ConfigSectionSchema::class)->for($this->class('AppChatSection'));
        $this->assertStringStartsWith('The Prism provider a chat call is emitted through.', $chat['properties']['default_provider']['description']);

        $this->assertSame('combobox', $chat['properties']['default_model']['x-widget']);
        $this->assertSame(['keysOf' => '/models'], $chat['properties']['default_model']['x-widget-options']);
        $this->assertSame('object', $chat['properties']['models']['type']);

        // An enum where source compares the key against literals (the scan fixture, shaped like beam-ux's PageGraph).
        $publisher = (new ReflectionClass($this->class('BeamUxHeadConfig')))->getProperty('publisher')->getAttributes(In::class)[0] ?? null;
        $this->assertNotNull($publisher);
        $this->assertSame(['Organization', 'Person'], $publisher->getArguments()[0]);

        // The coverage audit's draft check reports every one of them until the copy is rewritten.
        $this->assertContains('/default_model', ConfigSectionCoverage::drafts($this->class('AppChatSection')));
        $this->assertContains('/models/*/token_limit', ConfigSectionCoverage::drafts($this->class('AppChatSection')));
    }

    public function test_a_planted_finding_exits_1_and_no_verify_skips_the_check(): void
    {
        config()->set('cs06.planted', ['enabled' => true, 'mode' => ['speed' => 1]]);

        $this->assertSame(1, $this->generate('cs06.planted', self::FIXTURES.'/planted.php'));
        $this->assertStringContainsString('do not validate against the emitted section', Artisan::output());

        // A fresh directory and namespace: the first run already loaded its classes.
        $this->removeOutput();
        $this->namespace .= 'B';

        $this->assertSame(0, $this->generate('cs06.planted', self::FIXTURES.'/planted.php', ['--no-verify' => true]));
        $this->assertFileExists($this->out.'/Cs06PlantedSection.php');
    }

    public function test_rerunning_on_an_existing_section_reports_the_drift_and_overwrites_nothing(): void
    {
        $this->assertSame(0, $this->generate('app.chat', self::FIXTURES.'/app-chat.php'), Artisan::output());
        $section = $this->out.'/AppChatSection.php';

        // The author opts a key in and rewrites its copy.
        $edited = str_replace(
            "#[Title('Max tool steps'), Description('The `max_tool_steps` setting.'), ConfigDraft, ConfigKey]",
            "#[Title('Tool steps per reply'), Description('How many tool calls one reply may make.'), ConfigKey(ceiling: \\Splicewire\\Beam\\Enums\\ConfigTier::Operator, effect: \\Splicewire\\Beam\\Enums\\ConfigEffect::Live)]",
            (string) file_get_contents($section),
        );
        $this->assertNotSame(file_get_contents($section), $edited, 'the fixture edit applied');
        file_put_contents($section, $edited);
        $before = hash_file('sha256', $section);

        // Unchanged config: no drift, exit 0, nothing written. (Artisan::output() empties its buffer: read it once.)
        $code = $this->generate('app.chat', self::FIXTURES.'/app-chat.php');
        $output = Artisan::output();
        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('matches the config', $output);

        // The config moves on: a key added, a key removed, a key retyped.
        $chat = config('app.chat');
        $chat['streaming'] = true;
        unset($chat['default_provider']);
        $chat['max_tool_steps'] = 'eight';
        config()->set('app.chat', $chat);

        $this->assertSame(1, $this->generate('app.chat', self::FIXTURES.'/app-chat.php'));
        $output = Artisan::output();
        $this->assertStringContainsString('/streaming: missing', $output);
        $this->assertStringContainsString('/default_provider: gone', $output);
        $this->assertStringContainsString('/max_tool_steps: retyped', $output);
        $this->assertSame($before, hash_file('sha256', $section), 'the hand-edited section is untouched');
        $this->assertStringContainsString('Tool steps per reply', (string) file_get_contents($section));
    }

    public function test_rerunning_against_05s_hand_written_beam_ux_section_reports_its_undeclared_keys(): void
    {
        @mkdir($this->out);

        foreach (glob(self::FIXTURES.'/existing-beam-ux/*.php') ?: [] as $file) {
            copy($file, $this->out.'/'.basename($file));
        }

        $before = hash_file('sha256', $this->out.'/BeamUxReleasesConfig.php');

        $this->assertSame(1, $this->generate('beam.ux', self::FIXTURES.'/beam-ux.php'));
        $output = Artisan::output();
        $this->assertStringContainsString('/api_root: missing', $output);
        $this->assertStringNotContainsString('/releases', $output, '05\'s declared releases record matches the config');
        $this->assertSame($before, hash_file('sha256', $this->out.'/BeamUxReleasesConfig.php'));
    }

    public function test_a_class_string_of_a_registry_entry_type_is_a_combobox_and_a_match_is_an_enum(): void
    {
        $file = self::FIXTURES.'/registry-backed.php';
        $inferred = (new \Splicewire\Beam\Config\Generator\ConfigSectionInferrer(
            \Splicewire\Beam\Config\Generator\ConfigFileOutline::of($file),
            \Splicewire\Beam\Config\Generator\ConfigLiteralComparisons::in('cs06.registry', [self::FIXTURES.'/scan']),
            fn (string $class): ?string => is_a($class, \Splicewire\Beam\Config\ConfigSection::class, true) ? 'beam.config.sections' : null,
        ))->infer('cs06.registry', require $file);

        $keys = array_column(array_map(fn ($key) => [$key->name, $key], $inferred->classes['Cs06RegistrySection']), 1, 0);

        $this->assertSame(['registry' => 'beam.config.sections'], $keys['section']->widget);
        $this->assertSame(['fast', 'idle', 'slow'], $keys['mode']->enum);
        $this->assertSame('BEAM_FIXTURE_REGISTRY_MODE', $keys['mode']->env);
    }

    /**
     * review-r1 (2026-11-16): enum inference accepted only string literals. An int key compared with ints, and a float key
     * with numbers, now get a typed enum (default first); literals of another type are dropped; a union or a bool gets none.
     */
    public function test_numeric_literals_give_a_typed_enum_and_a_type_never_mixes(): void
    {
        $file = self::FIXTURES.'/numeric.php';
        $literals = \Splicewire\Beam\Config\Generator\ConfigLiteralComparisons::in('cs06.numeric', [self::FIXTURES.'/scan']);

        $this->assertSame([-1, 3, 5, 'many'], $literals['retries'], 'typed and sorted, never stringified');

        $inferred = (new \Splicewire\Beam\Config\Generator\ConfigSectionInferrer(\Splicewire\Beam\Config\Generator\ConfigFileOutline::of($file), $literals))->infer('cs06.numeric', require $file);
        $keys = array_column(array_map(fn ($key) => [$key->name, $key], $inferred->classes['Cs06NumericSection']), 1, 0);

        $this->assertSame('int', $keys['retries']->type);
        $this->assertSame([3, -1, 5], $keys['retries']->enum, 'the int literals only; the string is dropped');
        $this->assertSame('float', $keys['ratio']->type);
        $this->assertSame([0.25, 0.5, 1], $keys['ratio']->enum, 'an int is a number, so it joins a float enum');
        $this->assertSame('int|string', $keys['mode']->type);
        $this->assertNull($keys['mode']->enum, 'a bare env() union may be either type at runtime');
        $this->assertNull($keys['enabled']->enum, 'a bool already has two values');

        // End to end: the emitted int enum validates its own default, and refuses another value.
        config()->set('cs06.numeric', require $file);
        $this->assertSame(0, $this->generate('cs06.numeric', $file), Artisan::output());
        $schema = app(ConfigSectionSchema::class)->for($this->class('Cs06NumericSection'));
        $this->assertTrue(app(AcceptanceGate::class)->accepts(require $file, $schema));
        $this->assertFalse(app(AcceptanceGate::class)->accepts(['retries' => 4] + require $file, $schema));
    }

    /**
     * build-qa (ConfigSections06BoundaryQaTest, 2026-11-16; credited): a map entry key some entries lack is emitted as
     * `Nested|Optional`; the coverage walker takes the Data member of that union, so the generator accepts its own output.
     */
    public function test_qa_generator_and_coverage_accept_an_optional_nested_data_class(): void
    {
        $dir = sys_get_temp_dir().'/cs06-qa-'.bin2hex(random_bytes(5));
        mkdir($dir);
        $values = ['model-one' => ['tuning' => ['rate' => 3]], 'model-two' => ['label' => 'two']];
        $file = $dir.'/optional.php';
        file_put_contents($file, '<?php return '.var_export($values, true).';');
        config(['qa06.optional' => $values]);

        try {
            $code = $this->generate('qa06.optional', $file);
            $output = Artisan::output();
            $section = $this->class('Qa06OptionalSection');

            $this->assertTrue(class_exists($section, false), $output);
            $type = (new ReflectionClass($this->class('Qa06OptionalEntry')))->getProperty('tuning')->getType();
            $this->assertInstanceOf(\ReflectionUnionType::class, $type, 'the generator emitted nested Data|Optional');
            $this->assertTrue(app(AcceptanceGate::class)->accepts($values, app(ConfigSectionSchema::class)->for($section)), 'the generated schema accepts the real defaults');
            $this->assertSame(0, $code, $output);
            $this->assertSame([], ConfigSectionCoverage::uncovered($section, $values));
        } finally {
            unlink($file);
            rmdir($dir);
        }
    }

    /** The draft and live-key walkers walk into a `Data|Optional` union too. */
    public function test_drafts_and_live_keys_walk_into_a_data_optional_union(): void
    {
        $section = \Splicewire\Beam\Tests\Fixtures\Config\OptionalNestedSection::class;

        $this->assertSame(['/inner/flag'], ConfigSectionCoverage::liveKeys($section));
        $this->assertContains('/inner/flag', ConfigSectionCoverage::drafts($section));
        $this->assertSame([], ConfigSectionCoverage::uncovered($section, ['inner' => ['flag' => true]]));
    }

    public function test_every_emitted_class_is_a_data_class_in_the_given_namespace(): void
    {
        $this->assertSame(0, $this->generate('app.chat', self::FIXTURES.'/app-chat.php'), Artisan::output());

        foreach (['AppChatSection', 'AppChatModelsEntry', 'AppChatInstructionsProvidersConfig'] as $short) {
            $this->assertTrue(is_subclass_of($this->class($short), Data::class), $short);
        }
    }
}
