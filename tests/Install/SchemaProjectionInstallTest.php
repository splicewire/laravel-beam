<?php

namespace Splicewire\Beam\Tests\Install;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Splicewire\Beam\Install\BeamInstallManifest;
use Splicewire\Beam\Tests\TestCase;

class SchemaProjectionInstallTest extends TestCase
{
    private string $outputDirectory;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $this->outputDirectory = sys_get_temp_dir().'/beam-install-projection-'.bin2hex(random_bytes(8));
        $app['config']->set('data-schemas.auto_discover_types', [dirname(__DIR__).'/Fixtures/SchemaProjectionInstall']);
        $app['config']->set('data-schemas.output_directory', $this->outputDirectory);
        $app['config']->set('data-schemas.path_structure', 'namespace');
        $app['config']->set('filesystems.disks.data-schemas', ['driver' => 'local', 'root' => $this->outputDirectory]);
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Exercise the real installer without publishing migrations or resetting any database.
        $manifest = new BeamInstallManifest;
        $manifest->register('fixture', [], order: 0);
        $this->app->instance(BeamInstallManifest::class, $manifest);
        Artisan::command('scribe:generate', fn () => 0);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->outputDirectory);
        parent::tearDown();
    }

    public function test_install_emits_app_data_through_the_real_schema_generator(): void
    {
        $this->artisan('splicewire:beam:install', ['--no-interaction' => true, '--no-seed' => true])->assertSuccessful();
        $path = $this->outputDirectory.'/Splicewire/Beam/Tests/Fixtures/SchemaProjectionInstall/SitemapData.schema.json';
        $this->assertFileExists($path);
        $schema = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['label', 'href', 'order', 'externalUrl'], array_keys($schema['properties']));
        $this->assertSame('integer', $schema['properties']['order']['type']);
        $this->assertContains('null', $schema['properties']['externalUrl']['type']);
    }

    public function test_install_reports_a_thrown_schema_generation_failure(): void
    {
        Artisan::command('schemas:generate', function () {
            throw new \RuntimeException('fixture generation failed');
        });
        $this->artisan('splicewire:beam:install', ['--no-interaction' => true, '--no-seed' => true])->assertFailed();
    }

    public function test_install_reports_a_schema_generation_failure(): void
    {
        Artisan::command('schemas:generate', fn () => 1);
        $this->artisan('splicewire:beam:install', ['--no-interaction' => true, '--no-seed' => true])->assertFailed();
    }
}
