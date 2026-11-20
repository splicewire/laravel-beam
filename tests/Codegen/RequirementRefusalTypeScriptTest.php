<?php

namespace Splicewire\Beam\Tests\Codegen;

use Illuminate\Filesystem\Filesystem;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\TypeScriptTransformer;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfigFactory;
use Spatie\TypeScriptTransformer\Writers\GlobalNamespaceWriter;
use Splicewire\Beam\Agreements\Data\AgreementMemberData;
use Splicewire\Beam\Agreements\Data\OutstandingRequirementData;
use Splicewire\Beam\Agreements\Data\RequirementRefusalData;
use Splicewire\Beam\Codegen\BeamTypeScriptTransformerExtension;
use Splicewire\Beam\Codegen\DeclaredParticleTypes;
use Splicewire\Beam\Codegen\PackageTypeRoots;
use Splicewire\Beam\Particle\Contribution\ResourceContributionRegistry;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\TestCase;

class RequirementRefusalTypeScriptTest extends TestCase
{
    public function test_the_declared_428_body_and_nested_member_types_are_emitted(): void
    {
        $directory = sys_get_temp_dir().'/beam-requirement-types-'.uniqid();
        (new Filesystem)->ensureDirectoryExists($directory);

        try {
            $operations = new ParticleOperationRegistry;
            $operations->register(new ParticleOperation(
                resource: 'requirement-fixtures',
                name: 'act',
                kind: OperationKind::Write,
                handle: fn () => null,
                input: RequirementTypeScriptInputData::class,
                errors: [428 => RequirementRefusalData::class],
            ));

            $config = TypeScriptTransformerConfigFactory::create()
                ->outputDirectory($directory)
                ->extension(new BeamTypeScriptTransformerExtension(
                    new DeclaredParticleTypes(new ParticleResourceRegistry, $operations, new ResourceContributionRegistry),
                    new PackageTypeRoots([]),
                ))
                ->writer(new GlobalNamespaceWriter('generated.d.ts'))
                ->get();

            TypeScriptTransformer::create($config)->execute();
            $generated = (string) file_get_contents($directory.'/generated.d.ts');

            $proofPath = getenv('BEAM_REQUIREMENT_TYPESCRIPT_PROOF');
            if (is_string($proofPath) && $proofPath !== '') {
                (new Filesystem)->ensureDirectoryExists(dirname($proofPath));
                $this->assertNotFalse(file_put_contents($proofPath, $generated));
            }

            foreach ([
                RequirementRefusalData::class,
                OutstandingRequirementData::class,
                AgreementMemberData::class,
                RequirementTypeScriptInputData::class,
            ] as $class) {
                $this->assertStringContainsString(class_basename($class), $generated, $generated);
            }
        } finally {
            (new Filesystem)->deleteDirectory($directory);
        }
    }
}

class RequirementTypeScriptInputData extends Data
{
    public function __construct(public AgreementMemberData $member) {}
}
