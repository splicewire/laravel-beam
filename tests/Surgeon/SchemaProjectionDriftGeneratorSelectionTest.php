<?php

namespace Splicewire\Beam\Tests\Surgeon;

use Illuminate\Support\Facades\File;
use Rushing\Doctor\DoctorStatus;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Schemastud\DataSchemas\PathGenerators\DefaultPathGenerator;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Surgeon\SchemaProjectionDriftAudit;
use Splicewire\Beam\Tests\Fixtures\NarrowFixtureGenerator;
use Splicewire\Beam\Tests\Fixtures\RefusingFixtureGenerator;
use Splicewire\Beam\Tests\Fixtures\WidgetGateData;
use Splicewire\Beam\Tests\TestCase;

/**
 * The `~/Herd/thingsontv` divergence, under test.
 *
 * `forApp()` used to instantiate `Schemastud\DataSchemas\Generators\JsonSchemaGenerator` by
 * hardcoded class-name, while `schemas:generate` picks the FIRST generator in
 * `data-schemas.generators` whose `canGenerate()` accepts the class. At a host that configures
 * two generators, the audit therefore compared disk against a document the real command never
 * writes — a permanent phantom "stale" finding no regeneration could clear.
 *
 * Both tests here are about GENERATOR SELECTION, so neither asserts a JsonSchemaGenerator
 * document: the fixture generator emits a marker schema precisely so "which generator ran" is
 * readable off the finding.
 */
class SchemaProjectionDriftGeneratorSelectionTest extends TestCase
{
    private string $outputDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'widgets',
            backing: 'App\\Models\\Widget',
            data: WidgetGateData::class,
        ));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->outputDirectory);

        parent::tearDown();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The audit's scope decision (14b) is "classes whose source file lives under the configured
        // discovery paths", so the fixture directory IS this host's app data tree for these tests.
        $this->outputDirectory = sys_get_temp_dir().'/beam-projection-drift-'.getmypid().'-'.spl_object_id($this);

        $app['config']->set('data-schemas.auto_discover_types', [dirname(__DIR__).'/Fixtures']);
        $app['config']->set('data-schemas.output_directory', $this->outputDirectory);
        $app['config']->set('data-schemas.path_structure', 'namespace');
        $app['config']->set('filesystems.disks.data-schemas', [
            'driver' => 'local',
            'root' => $this->outputDirectory,
            'throw' => false,
        ]);
    }

    private function pathFor(string $class): string
    {
        return (new DefaultPathGenerator(['output_directory' => $this->outputDirectory, 'path_structure' => 'namespace']))
            ->getSchemaPath(new \ReflectionClass($class));
    }

    private function writeDisk(string $class, array $document): void
    {
        $path = $this->pathFor($class);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function test_a_narrow_generator_first_in_the_list_is_the_one_the_audit_compares_against(): void
    {
        // Exactly thingsontv's config shape.
        config()->set('data-schemas.generators', [NarrowFixtureGenerator::class, JsonSchemaGenerator::class]);

        // On disk is what `schemas:generate` would have written here — the NARROW generator's document.
        $this->writeDisk(WidgetGateData::class, NarrowFixtureGenerator::SCHEMA);

        $findings = SchemaProjectionDriftAudit::forApp()->run();

        $this->assertCount(1, $findings);
        $this->assertSame(
            DoctorStatus::Pass,
            $findings[0]->status,
            'The audit reported drift against a document `schemas:generate` never writes — it is still '.
            'hardcoding JsonSchemaGenerator instead of dispatching on canGenerate().',
        );
    }

    public function test_a_class_no_configured_generator_accepts_is_skipped_not_crashed(): void
    {
        // ChainedGenerator::generate() throws here; canGenerate() must be asked first.
        config()->set('data-schemas.generators', [RefusingFixtureGenerator::class]);

        $findings = SchemaProjectionDriftAudit::forApp()->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertStringContainsString('nothing to project', $findings[0]->detail);
    }
}
