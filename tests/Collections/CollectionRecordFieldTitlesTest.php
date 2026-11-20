<?php

namespace Splicewire\Beam\Tests\Collections;

use Illuminate\Filesystem\Filesystem;
use ReflectionClass;
use Schemastud\DataSchemas\Generators\Generator;
use Splicewire\Beam\Collections\CollectionRecordData;
use Splicewire\Beam\Data\RecordData;
use Splicewire\Beam\Localization\DeclaredText;
use Splicewire\Beam\Tests\TestCase;

/**
 * localization 72: a Collection record's envelope fields carry declared titles. Before, the properties had none, so the
 * record form humanized their names (Id, Collection, Schema ref…) in English no locale could reach. Each title is keyed by
 * the DECLARING class (`beam::data.<CollectionRecordData>.properties.<p>.title`), so RecordData's edit schema reads it too.
 */
class CollectionRecordFieldTitlesTest extends TestCase
{
    private const TITLES = [
        'id' => 'Id',
        'collection' => 'Collection',
        'schemaRef' => 'Schema ref',
        'migrationStatus' => 'Migration status',
        'fields' => 'Fields',
        'schema' => 'Schema',
        'relations' => 'Relations',
    ];

    private ?string $lang = null;

    protected function tearDown(): void
    {
        if ($this->lang !== null) {
            (new Filesystem)->deleteDirectory($this->lang);
        }

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function properties(string $class): array
    {
        return app(Generator::class)->generate(new ReflectionClass($class))['properties'];
    }

    public function test_every_envelope_property_declares_its_title_in_the_english_the_form_showed(): void
    {
        foreach ([CollectionRecordData::class, RecordData::class] as $class) {
            $properties = $this->properties($class);

            foreach (self::TITLES as $property => $title) {
                $this->assertSame($title, $properties[$property]['title'] ?? null, "{$class}::\${$property}");
            }
        }
    }

    public function test_a_locale_line_for_the_declaring_class_key_relabels_the_field(): void
    {
        $key = app(DeclaredText::class)->key('data', CollectionRecordData::class, 'properties.schemaRef.title');
        $this->assertNotNull($key);
        $this->assertStringStartsWith('beam::data.', $key);

        $lang = $this->lang = sys_get_temp_dir().'/beam-l72-lang-'.getmypid().'-'.bin2hex(random_bytes(4));
        [$namespace, $rest] = explode('::', $key, 2);
        [$group, $item] = explode('.', $rest, 2);
        $file = "{$lang}/vendor/{$namespace}/es/{$group}.php";
        $content = [];
        data_set($content, $item, 'Referencia de esquema');
        @mkdir(dirname($file), 0777, true);
        file_put_contents($file, '<?php return '.var_export($content, true).';');

        $this->app->useLangPath($lang);
        $this->app->forgetInstance('translation.loader');
        $this->app->forgetInstance('translator');
        $this->app->setLocale('es');

        $this->assertSame('Referencia de esquema', $this->properties(RecordData::class)['schemaRef']['title'], 'the subclass reads the declaring class key');
        $this->assertSame('Migration status', $this->properties(RecordData::class)['migrationStatus']['title'], 'an untranslated title keeps its English');
    }
}
