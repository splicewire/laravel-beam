<?php

namespace Splicewire\Beam\Tests\Intake;

use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\Router;
use ReflectionClass;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Spatie\TypeScriptTransformer\TypeScriptTransformer;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfigFactory;
use Spatie\TypeScriptTransformer\Writers\GlobalNamespaceWriter;
use Splicewire\Beam\Codegen\BeamTypeScriptTransformerExtension;
use Splicewire\Beam\Codegen\DeclaredParticleTypes;
use Splicewire\Beam\Codegen\DeclaredRouteTypes;
use Splicewire\Beam\Codegen\PackageTypeRoots;
use Splicewire\Beam\Intake\Data\EntityIntakeFormSectionData;
use Splicewire\Beam\Intake\Data\EntityIntakeSectionSubmittedData;
use Splicewire\Beam\Particle\Contribution\ResourceContributionRegistry;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Routing\RouteReturnType;
use Splicewire\Beam\Tests\TestCase;

class EntityIntakeProtocolTest extends TestCase
{
    public function test_the_public_section_protocol_has_no_tower_dependency(): void
    {
        $this->assertFalse(class_exists('Splicewire\\Tower\\Data\\EntityIntakeFormSectionData'));
        $section = new EntityIntakeFormSectionData('identity', ['type' => 'object'], ['name' => 'Ada']);

        $this->assertSame([
            'key' => 'identity',
            'schema' => ['type' => 'object'],
            'fields' => ['name' => 'Ada'],
        ], $section->toArray());
        $this->assertSame('EntityIntakeFormSectionData', $section::jsonSchema()['$id']);
    }

    public function test_the_submitted_result_declares_its_attribute_and_wire_contract(): void
    {
        $this->assertNotEmpty((new ReflectionClass(EntityIntakeSectionSubmittedData::class))->getAttributes(TypeScript::class));
        $this->assertSame(['submissionId' => 'submission-1'], (new EntityIntakeSectionSubmittedData('submission-1'))->toArray());
        $this->assertSame(['submissionId'], EntityIntakeSectionSubmittedData::jsonSchema()['required']);
    }

    public function test_an_explicit_contract_host_selection_emits_intake_types_without_tower_routes(): void
    {
        $this->assertFalse(class_exists('Splicewire\\Tower\\Api\\V1\\EntityIntakeController'));
        $directory = sys_get_temp_dir().'/public-intake-types-'.bin2hex(random_bytes(8));
        $files = new Filesystem;
        $files->ensureDirectoryExists($directory);
        try {
            $router = new Router(new Dispatcher($this->app), $this->app);
            $config = TypeScriptTransformerConfigFactory::create()
                ->outputDirectory($directory)
                ->extension(new BeamTypeScriptTransformerExtension(
                    new DeclaredParticleTypes(new ParticleResourceRegistry, new ParticleOperationRegistry, new ResourceContributionRegistry),
                    new PackageTypeRoots([]),
                    new DeclaredRouteTypes($router, app(RouteReturnType::class)),
                ))
                // This contract host selects intake; no global package type root is introduced.
                ->transformDirectories(dirname((new ReflectionClass(EntityIntakeFormSectionData::class))->getFileName()))
                ->writer(new GlobalNamespaceWriter('public-intake.d.ts'))
                ->get();
            TypeScriptTransformer::create($config)->execute();
            $typescript = file_get_contents($directory.'/public-intake.d.ts');
            $this->assertStringContainsString('export type EntityIntakeFormSectionData =', $typescript);
            $this->assertStringContainsString('export type EntityIntakeSectionSubmittedData =', $typescript);
            $this->assertStringContainsString('submissionId: string,', $typescript);
            $this->assertStringNotContainsString('Splicewire.Tower', $typescript);
            $this->assertStringNotContainsString('undefined', $typescript);
        } finally {
            $files->deleteDirectory($directory);
        }
    }
}
