<?php

namespace Splicewire\Beam\Tests\Codegen;

use Illuminate\Filesystem\Filesystem;
use Spatie\TypeScriptTransformer\References\ClassStringReference;
use Spatie\TypeScriptTransformer\Transformers\AttributedClassTransformer;
use Spatie\TypeScriptTransformer\Transformers\EnumTransformer;
use Spatie\TypeScriptTransformer\TypeScriptTransformer;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfigFactory;
use Spatie\TypeScriptTransformer\Writers\GlobalNamespaceWriter;
use Splicewire\Beam\Codegen\ClientTypeReferences;
use Splicewire\Beam\Codegen\DeclaredParticleTypes;
use Splicewire\Beam\Collections\CollectionInputData;
use Splicewire\Beam\Data\BeamSchemaInputData;
use Splicewire\Beam\Data\GitRepoData;
use Splicewire\Beam\Data\HookData;
use Splicewire\Beam\Data\HookInputData;
use Splicewire\Beam\Data\RecordData;
use Splicewire\Beam\Filters\Data\SavedFilterData;
use Splicewire\Beam\Filters\Data\SavedFilterInputData;
use Splicewire\Beam\Surgeon\UnseatedNavSectionAudit;
use Splicewire\Beam\Tests\TestCase;

class CoreClientDtoScopeTest extends TestCase
{
    public function test_nav_doctor_audit_is_neither_a_declared_client_reference_nor_an_emitted_type(): void
    {
        $directory = sys_get_temp_dir().'/beam-core-client-scope-'.bin2hex(random_bytes(8));
        $files = new Filesystem;
        $files->ensureDirectoryExists($directory);

        try {
            $declared = app(DeclaredParticleTypes::class)->declared();
            $this->assertArrayHasKey(HookData::class, $declared);
            $this->assertArrayNotHasKey(UnseatedNavSectionAudit::class, $declared);
            $classes = array_values(array_filter(array_keys($declared),
                fn (string $class): bool => str_starts_with($class, 'Splicewire\\Beam\\')
                    && str_starts_with((new \ReflectionClass($class))->getFileName(), dirname(__DIR__, 2).'/src/')));
            $this->assertNotEmpty($classes);

            $config = TypeScriptTransformerConfigFactory::create()
                ->transformer(AttributedClassTransformer::class, EnumTransformer::class)
                ->transformDirectories(dirname(__DIR__, 2).'/src')
                ->outputDirectory($directory)
                ->writer(new GlobalNamespaceWriter('generated.d.ts'))
                ->get();
            $runner = TypeScriptTransformer::create($config);
            [$types] = $runner->resolveState();
            foreach ([CollectionInputData::class, BeamSchemaInputData::class, HookInputData::class,
                SavedFilterInputData::class, GitRepoData::class, HookData::class, RecordData::class,
                SavedFilterData::class] as $class) {
                $this->assertNotNull($types->get(new ClassStringReference($class)), $class);
            }
            $this->assertNull($types->get(new ClassStringReference(UnseatedNavSectionAudit::class)));
            $runner->outputTransformed($types);
            $this->assertStringNotContainsString('UnseatedNavSectionAudit', file_get_contents($directory.'/generated.d.ts'));

            $references = (new ClientTypeReferences)->resolveClasses([...$classes, UnseatedNavSectionAudit::class], $directory.'/sdk', $config);
            $this->assertArrayHasKey(HookData::class, $references['resolved']);
            $this->assertArrayNotHasKey(UnseatedNavSectionAudit::class, $references['resolved']);
            $this->assertSame('is not emitted by the configured TypeScript transformer', $references['unresolved'][UnseatedNavSectionAudit::class]);

        } finally {
            $files->deleteDirectory($directory);
        }
    }
}
