<?php

namespace Splicewire\Beam\Tests\Install;

use Illuminate\Console\Application;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Splicewire\Beam\Console\BeamInstallCommand;
use Splicewire\Beam\Install\BeamInstallManifest;
use Splicewire\Beam\Tests\TestCase;

abstract class SchemaProjectionInstallTestCase extends TestCase
{
    protected string $outputDirectory;

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
}

uses(SchemaProjectionInstallTestCase::class);

it('emits app data through the real schema generator during installation', function () {
    $this->artisan('splicewire:beam:install', ['--no-interaction' => true, '--no-seed' => true])->assertSuccessful();
    $path = $this->outputDirectory.'/Splicewire/Beam/Tests/Fixtures/SchemaProjectionInstall/SitemapData.schema.json';
    expect($path)->toBeFile();
    $schema = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    expect(array_keys($schema['properties']))->toBe(['label', 'href', 'order', 'externalUrl'])
        ->and($schema['properties']['order']['type'])->toBe('integer')
        ->and($schema['properties']['externalUrl']['type'])->toContain('null');
});

it('reports a thrown schema generation failure', function () {
    Artisan::command('schemas:generate', function () {
        throw new \RuntimeException('fixture generation failed');
    });
    $this->artisan('splicewire:beam:install', ['--no-interaction' => true, '--no-seed' => true])->assertFailed();
});

it('reports a nonzero schema generation failure', function () {
    Artisan::command('schemas:generate', fn () => 1);
    $this->artisan('splicewire:beam:install', ['--no-interaction' => true, '--no-seed' => true])->assertFailed();
});

it('fails explicitly when the required schema generator is unavailable', function () {
    $console = new class($this->app, $this->app['events'], 'test') extends Application
    {
        public function has(string $name): bool
        {
            return $name !== 'schemas:generate' && parent::has($name);
        }
    };
    $console->resolve(BeamInstallCommand::class);
    $this->app->make(Kernel::class)->setArtisan($console);
    $this->artisan('splicewire:beam:install', ['--no-interaction' => true, '--no-seed' => true])
        ->expectsOutputToContain('required schemas:generate command is not registered')
        ->assertFailed();
});
