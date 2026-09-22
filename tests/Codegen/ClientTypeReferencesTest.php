<?php

namespace Splicewire\Beam\Tests\Codegen;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use LogicException;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Spatie\TypeScriptTransformer\Data\TransformationContext;
use Spatie\TypeScriptTransformer\PhpNodes\PhpClassNode;
use Spatie\TypeScriptTransformer\References\ClassStringReference;
use Spatie\TypeScriptTransformer\Transformed\Transformed;
use Spatie\TypeScriptTransformer\Transformed\Untransformable;
use Spatie\TypeScriptTransformer\Transformers\AttributedClassTransformer;
use Spatie\TypeScriptTransformer\Transformers\EnumTransformer;
use Spatie\TypeScriptTransformer\TypeScriptTransformer;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfig;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfigFactory;
use Spatie\TypeScriptTransformer\Writers\GlobalNamespaceWriter;
use Spatie\TypeScriptTransformer\Writers\ModuleWriter;
use Splicewire\Beam\Codegen\ClientTypeReferences;
use Splicewire\Beam\Codegen\DeclaredParticleTransformedProvider;
use Splicewire\Beam\Codegen\DeclaredParticleTypes;
use Splicewire\Beam\Codegen\TsClientGenerator;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Source\RouteManifestSource;
use Splicewire\Beam\Tests\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class ClientTypeReferencesTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/beam-writer-client-'.uniqid();
        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'writer-fixtures', backing: Model::class, data: WriterRootData::class,
        ));
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function config(bool $modules, bool $exported = true): TypeScriptTransformerConfig
    {
        (new Filesystem)->ensureDirectoryExists($this->directory.'/types');
        (new Filesystem)->ensureDirectoryExists($this->directory.'/empty-scan');

        return TypeScriptTransformerConfigFactory::create()
            ->outputDirectory($this->directory.'/types')
            ->transformDirectories($this->directory.'/empty-scan')
            ->provider(new DeclaredParticleTransformedProvider(app(DeclaredParticleTypes::class)))
            ->transformer($exported ? new AttributedClassTransformer : new NonExportedWriterTransformer, new EnumTransformer)
            ->writer($modules ? new ModuleWriter(path: null) : new GlobalNamespaceWriter)
            ->get();
    }

    private function model(): array
    {
        $ref = str_replace('\\', '.', WriterRootData::class);

        return ['operations' => [
            ['name' => 'examples.index', 'path' => '/examples', 'method' => 'GET', 'returns' => ['ref' => $ref], 'returnsMany' => true],
            ['name' => 'examples.save', 'path' => '/examples', 'method' => 'POST', 'returns' => ['ref' => $ref]],
            ['name' => 'examples.events', 'path' => '/examples/events', 'method' => 'GET', 'meta' => ['streams' => ['changed' => [$ref]]]],
        ]];
    }

    public function test_registry_discovery_traverses_nested_dtos_and_enums_without_directory_rosters(): void
    {
        [$types] = TypeScriptTransformer::create($this->config(true))->resolveState();
        foreach ([WriterRootData::class, WriterNestedData::class, WriterState::class] as $class) {
            $this->assertNotNull($types->get(new ClassStringReference($class)), $class);
        }
        $this->assertSame([], (new Filesystem)->allFiles($this->directory));
    }

    public function test_client_references_follow_module_locations_and_ambient_names(): void
    {
        foreach ([true, false] as $modules) {
            $refs = (new ClientTypeReferences)->resolve($this->model(), $this->directory.'/sdk', $this->config($modules));
            $files = (new TsClientGenerator)->invoke(['model' => $this->model(), 'options' => ['type_references' => $refs, 'emit_stores' => true]])['files'];
            $type = $modules ? 'import("../../types/Wire/Results").RenamedResult' : 'Wire.Results.RenamedResult';
            $this->assertStringContainsString($type.'[]', $files['hooks/examples.ts'], json_encode($refs));
            $this->assertStringContainsString($type.'[]', $files['stores/examples.ts']);
            $this->assertStringContainsString("data: {$type}", $files['hooks/examples.ts']);
            $this->assertStringContainsString($modules ? 'import("../types/Wire/Results").RenamedResult' : $type, $files['aliases.ts']);
        }
        $this->assertSame([], (new Filesystem)->allFiles($this->directory));
    }

    public function test_command_uses_the_hosts_configured_writer(): void
    {
        $this->app->instance(TypeScriptTransformerConfig::class, $this->config(true));
        $source = new class implements RouteManifestSource
        {
            public function toArray(): array
            {
                return ['examples.index' => ['path' => '/examples', 'methods' => ['GET'], 'returns' => str_replace('\\', '.', WriterRootData::class)]];
            }
        };
        $this->app->instance('writer-fixture-source', $source);
        config()->set('beam.client.sources.defaults', 'writer-fixture-source');
        config()->set('beam.client.sources.operator', null);
        config()->set('beam.client.out_dir', $this->directory.'/sdk');
        config()->set('beam.client.emit_stores', false);
        $this->assertSame(0, Artisan::call('splicewire:beam:generate:client'));
        $this->assertStringContainsString('import("../types/Wire/Results").RenamedResult', file_get_contents($this->directory.'/sdk/aliases.ts'));
    }

    public function test_missing_export_fails_before_the_client_can_emit_dangling_types(): void
    {
        $config = TypeScriptTransformerConfigFactory::create()->outputDirectory(sys_get_temp_dir())->get();
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(WriterRootData::class);
        (new ClientTypeReferences)->resolve($this->model(), $this->directory, $config);
    }

    public function test_present_but_unexported_module_type_cannot_be_imported_by_the_sdk(): void
    {
        $config = $this->config(true, exported: false);
        [$types] = TypeScriptTransformer::create($config)->resolveState();
        $this->assertFalse($types->get(new ClassStringReference(WriterRootData::class))->isExported());
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('is not exported by its TypeScript module');
        try {
            (new ClientTypeReferences)->resolve($this->model(), $this->directory.'/sdk', $config);
        } finally {
            $this->assertDirectoryDoesNotExist($this->directory.'/sdk');
        }
    }

    public function test_ambient_references_do_not_require_a_module_export(): void
    {
        $refs = (new ClientTypeReferences)->resolve($this->model(), $this->directory.'/sdk', $this->config(false, exported: false));
        $this->assertSame(['name' => 'Wire.Results.RenamedResult'], $refs[str_replace('\\', '.', WriterRootData::class)]);
    }

    public function test_generated_clients_compile_against_real_module_and_ambient_dtos(): void
    {
        $compiler = getenv('BEAM_TYPESCRIPT_BIN') ?: (new ExecutableFinder)->find('tsc');
        if (! $compiler) {
            $this->markTestSkipped('Set BEAM_TYPESCRIPT_BIN to the local consumer TypeScript compiler.');
        }
        foreach ([true, false] as $modules) {
            $config = $this->config($modules);
            TypeScriptTransformer::create($config)->execute();
            $refs = (new ClientTypeReferences)->resolve($this->model(), $this->directory.'/sdk', $config);
            $files = (new TsClientGenerator)->invoke(['model' => $this->model(), 'options' => ['type_references' => $refs, 'emit_stores' => true]])['files'];
            foreach ($files as $path => $contents) {
                (new Filesystem)->ensureDirectoryExists(dirname($this->directory.'/sdk/'.$path));
                file_put_contents($this->directory.'/sdk/'.$path, $contents);
            }
            // Only infrastructure is stubbed. DTO declarations and client files are emitted by the real producers.
            file_put_contents($this->directory.'/runtime.d.ts', <<<'TS'
declare module '@/lib/api' { export const api: any; export const operatorApi: any; }
declare module '@/lib/routes' { export const route: any; export const operatorRoute: any; }
declare module '@splicewire/beam-ux/streaming' { export function useSseStream<T>(client: any, url: any, options: any): T; }
declare module '@tanstack/react-query' { export type UseQueryOptions<T, E, D> = {}; export type UseMutationOptions<T, E, V> = {}; export function useQuery<T>(options: any): T; export function useMutation<T, E, V>(options: { mutationFn: (vars: V) => Promise<T> }): T; }
declare module 'zustand' { export function create<T>(initializer: (set: (patch: Partial<T>) => void) => T): T; }
TS);
            file_put_contents($this->directory.'/tsconfig.json', json_encode(['compilerOptions' => ['strict' => true, 'noEmit' => true, 'target' => 'ES2022', 'module' => 'ESNext', 'moduleResolution' => 'Bundler', 'types' => []], 'include' => ['**/*.ts']]));
            $result = new Process([$compiler, '--project', $this->directory.'/tsconfig.json']);
            $result->run();
            $this->assertSame(0, $result->getExitCode(), $result->getOutput().$result->getErrorOutput());
            (new Filesystem)->deleteDirectory($this->directory);
        }
    }
}

#[TypeScript(name: 'RenamedResult', location: ['Wire', 'Results'])]
class WriterRootData extends Data
{
    public function __construct(public WriterNestedData $nested) {}
}

#[TypeScript]
class WriterNestedData extends Data
{
    public function __construct(public WriterState $state) {}
}

#[TypeScript]
enum WriterState: string
{
    case Ready = 'ready';
}

class NonExportedWriterTransformer extends AttributedClassTransformer
{
    public function transform(PhpClassNode $phpClassNode, TransformationContext $context): Transformed|Untransformable
    {
        $type = parent::transform($phpClassNode, $context);
        if ($type instanceof Transformed) {
            $type->export(false);
        }

        return $type;
    }
}
