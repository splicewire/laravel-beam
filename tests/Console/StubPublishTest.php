<?php

namespace Splicewire\Beam\Tests\Console;

use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Splicewire\Beam\BeamServiceProvider;
use Splicewire\Beam\Console\ParticleGeneratorCommand;
use Splicewire\Beam\Tests\TestCase;

/**
 * The `beam-stubs` publish tag, which had no coverage at all — a rename would have broken every host that
 * had published its scaffolder stubs, silently, because nothing asserted the tag's name or its payload.
 *
 * Three things are asserted, and the last is the one the tag exists for:
 *
 * 1. **What the tag writes.** It publishes the package's whole `stubs/` DIRECTORY into `base_path('stubs')`,
 *    not a file list — so the payload is the six generator stubs *plus* `client-runtime/` and `scribe/`,
 *    which have their own narrower tags (`beam-client-runtime`, `beam-scribe`) pointing at real host
 *    destinations. `beam-stubs` deposits INERT copies of those two under `stubs/`; nothing reads them there,
 *    and it is not a substitute for either tag.
 * 2. **Where it writes.** `base_path()` in the `publishes()` call is evaluated at BOOT, so the destination is
 *    frozen when the provider registers — a later `setBasePath()` does not move it. That is why the publish
 *    assertions below run against the real base path rather than the throwaway host the round-trip uses.
 * 3. **That a published stub is preferred.** `ParticleGeneratorCommand::resolveStubPath()` looks for the host
 *    copy at the same relative path before falling back to the package's — Laravel's own `stub:publish`
 *    convention — so publish-once-edit-in-place needs no flag.
 *
 * @see ParticleGeneratorCommand::resolveStubPath()
 * @see docs/agents/stub-publishing.convention.md
 */
class StubPublishTest extends TestCase
{
    /** The generator stubs the tag is for — every one of these is read by a `make:particle-*` command. */
    private const GENERATOR_STUBS = [
        'particle-resource.stub',
        'particle-resource-input.stub',
        'particle-op.stub',
        'particle-op-task.stub',
        'particle-op-stream.stub',
        'particle-data.stub',
    ];

    /** The boot-time `base_path('stubs')` the tag is bound to — cleaned up after each test that publishes. */
    private string $publishRoot;

    private string $host;

    protected function setUp(): void
    {
        parent::setUp();

        $this->publishRoot = base_path('stubs');

        $this->host = sys_get_temp_dir().'/beam-stub-publish-'.Str::random(10);

        File::ensureDirectoryExists($this->host.'/app/Models');
        File::put($this->host.'/composer.json', json_encode(
            ['autoload' => ['psr-4' => ['App\\' => 'app/']]],
            JSON_PRETTY_PRINT,
        ));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->publishRoot);
        File::deleteDirectory($this->host);

        parent::tearDown();
    }

    public function test_the_beam_stubs_tag_publishes_the_six_generator_stubs_under_the_base_path(): void
    {
        $this->assertDirectoryDoesNotExist($this->publishRoot, 'A leftover publish would make this vacuous.');

        $this->artisan('vendor:publish', ['--tag' => 'beam-stubs'])->assertExitCode(0);

        foreach (self::GENERATOR_STUBS as $stub) {
            $this->assertFileExists(
                $this->publishRoot.'/'.$stub,
                "The `beam-stubs` tag did not write [{$stub}] — a host editing it would see no effect.",
            );
        }
    }

    /**
     * The tag maps a DIRECTORY, so it carries two subdirectories that are not generator stubs and have their
     * own tags. Asserted rather than left implicit: it is the difference between "six stubs" and what a host's
     * `stubs/` actually looks like after the publish, and the copies landing here are inert.
     */
    public function test_the_directory_mapping_also_deposits_the_client_runtime_and_scribe_sources(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'beam-stubs'])->assertExitCode(0);

        $this->assertFileExists($this->publishRoot.'/client-runtime/api.ts');
        $this->assertFileExists($this->publishRoot.'/client-runtime/routes.ts');
        $this->assertFileExists($this->publishRoot.'/scribe/scribe.php');

        // …and nowhere else. `beam-stubs` is not a substitute for `beam-client-runtime` or `beam-scribe`:
        // its ONLY destination is `base_path('stubs')`, so a host that publishes just this tag has an
        // unwired copy of both. Asserted off the provider's own path map rather than by looking for absent
        // files at `resource_path()`/`config_path()`, which a sibling test's publish pollutes.
        $this->assertSame(
            [base_path('stubs')],
            array_values(ServiceProvider::pathsToPublish(BeamServiceProvider::class, 'beam-stubs')),
        );
    }

    /**
     * The reason the tag exists. A stub sitting at `base_path('stubs/particle-resource.stub')` — exactly where
     * the assertion above proves the tag writes it — wins over the package copy, with no flag on the generate.
     */
    public function test_a_stub_at_the_published_path_is_preferred_over_the_packages_copy(): void
    {
        $this->app->setBasePath($this->host);

        $marker = '// HOUSE HEADER — proves the host copy won.';
        File::ensureDirectoryExists($this->host.'/stubs');
        File::put(
            $this->host.'/stubs/particle-resource.stub',
            $marker."\n".File::get(dirname(__DIR__, 2).'/stubs/particle-resource.stub'),
        );

        $this->artisan('splicewire:beam:make:particle-resource', ['name' => 'Lyric'])->assertSuccessful();

        $this->assertStringContainsString($marker, File::get($this->host.'/app/Data/LyricData.php'));
    }

    /**
     * The converse, and the half that makes the convention's "not publishing is the default, not a missing
     * file" sentence true: with nothing published, the generator emits from the package copy and succeeds.
     */
    public function test_an_unpublished_stub_is_the_default_not_a_missing_file(): void
    {
        $this->app->setBasePath($this->host);

        $this->assertDirectoryDoesNotExist($this->host.'/stubs');

        $this->artisan('splicewire:beam:make:particle-resource', ['name' => 'Lyric'])->assertSuccessful();

        $this->assertFileExists($this->host.'/app/Data/LyricData.php');
    }
}
