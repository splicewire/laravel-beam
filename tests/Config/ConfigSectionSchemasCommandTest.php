<?php

namespace Splicewire\Beam\Tests\Config;

use Illuminate\Filesystem\Filesystem;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Config\ConfigSectionSchema;
use Splicewire\Beam\Tests\Fixtures\Config\GaugesSection;
use Splicewire\Beam\Tests\Fixtures\Config\WidgetsSection;
use Splicewire\Beam\Tests\TestCase;

/**
 * Regeneration writes; `--check` and {@see ConfigSectionSchema::drift()} only read. The check must find a missing
 * and a stale file, name them, exit 1, and leave the tree exactly as it was.
 */
class ConfigSectionSchemasCommandTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/beam-config-schemas-'.bin2hex(random_bytes(4));
        app(ConfigSectionRegistry::class)
            ->section(WidgetsSection::class, owner: 'acme/widgets')
            ->section(GaugesSection::class, owner: 'host');
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);

        parent::tearDown();
    }

    /** @return array<string, string> */
    private function tree(): array
    {
        $files = [];

        foreach (glob($this->directory.'/*') ?: [] as $path) {
            $files[basename($path)] = hash_file('sha256', $path);
        }

        return $files;
    }

    public function test_it_writes_each_owners_sections_and_the_check_then_passes(): void
    {
        $this->artisan('splicewire:beam:config:schemas', ['--owner' => 'host', '--path' => $this->directory])->assertSuccessful();

        $this->assertSame(['fleet.gauges.schema.json'], array_keys($this->tree()));
        $this->assertSame(
            ConfigSectionSchema::encode(app(ConfigSectionSchema::class)->for(GaugesSection::class)),
            file_get_contents($this->directory.'/fleet.gauges.schema.json'),
        );

        $this->artisan('splicewire:beam:config:schemas', ['--owner' => 'host', '--path' => $this->directory, '--check' => true])->assertSuccessful();
    }

    public function test_the_check_names_missing_and_stale_files_and_writes_nothing(): void
    {
        $this->artisan('splicewire:beam:config:schemas', ['--path' => $this->directory])->assertSuccessful();
        file_put_contents($this->directory.'/widgets.schema.json', "{}\n");
        unlink($this->directory.'/fleet.gauges.schema.json');
        $before = $this->tree();

        $this->artisan('splicewire:beam:config:schemas', ['--path' => $this->directory, '--check' => true])
            ->expectsOutputToContain('fleet.gauges')
            ->expectsOutputToContain('widgets')
            ->assertFailed();

        $this->assertSame($before, $this->tree(), '--check never writes');
        $this->assertSame(
            ['fleet.gauges', 'widgets'],
            array_keys(app(ConfigSectionSchema::class)->drift(app(ConfigSectionRegistry::class)->all(), $this->directory)),
        );
    }
}
