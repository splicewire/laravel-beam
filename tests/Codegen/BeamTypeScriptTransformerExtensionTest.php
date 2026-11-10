<?php

namespace Splicewire\Beam\Tests\Codegen;

use Carbon\CarbonInterface;
use DateTime;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\Router;
use LogicException;
use ReflectionProperty;
use Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData;
use Rushing\LaravelDataSchemasScribe\Attributes\StreamsFromData;
use Spatie\LaravelData\Data;
use Spatie\LaravelTypeScriptTransformer\LaravelData\LaravelDataTransformedProvider;
use Spatie\LaravelTypeScriptTransformer\LaravelData\LaravelDataTypeScriptTransformerExtension;
use Spatie\LaravelTypeScriptTransformer\LaravelData\Transformers\DataClassTransformer;
use Spatie\LaravelTypeScriptTransformer\TransformedProviders\LaravelTypesTransformedProvider;
use Spatie\TypeScriptTransformer\TransformedProviders\TransformerProvider;
use Spatie\TypeScriptTransformer\Transformers\EnumTransformer;
use Spatie\TypeScriptTransformer\TypeScriptTransformer;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfig;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfigFactory;
use Spatie\TypeScriptTransformer\Visitor\Common\ReplaceTypesVisitorClosure;
use Spatie\TypeScriptTransformer\Writers\GlobalNamespaceWriter;
use Spatie\TypeScriptTransformer\Writers\ModuleWriter;
use Spatie\TypeScriptTransformer\Writers\Writer;
use Splicewire\Beam\Codegen\BeamTypeScriptTransformerExtension;
use Splicewire\Beam\Codegen\ClientTypeReferences;
use Splicewire\Beam\Codegen\DeclaredParticleTransformedProvider;
use Splicewire\Beam\Codegen\DeclaredParticleTypes;
use Splicewire\Beam\Codegen\DeclaredRouteTypes;
use Splicewire\Beam\Codegen\PackageTypeRoots;
use Splicewire\Beam\Particle\Contribution\ResourceContributionRegistry;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Routing\RouteReturnType;
use Splicewire\Beam\Tests\TestCase;

class BeamTypeScriptTransformerExtensionTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/beam-ts-extension-'.uniqid();
        (new Filesystem)->ensureDirectoryExists($this->directory.'/types');
        (new Filesystem)->ensureDirectoryExists($this->directory.'/roots/first');
        (new Filesystem)->ensureDirectoryExists($this->directory.'/roots/second');
        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'extension-fixtures', backing: Model::class, data: ExtensionRootData::class,
        ));
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function extension(): BeamTypeScriptTransformerExtension
    {
        return new BeamTypeScriptTransformerExtension(app(DeclaredParticleTypes::class), $this->roots());
    }

    private function roots(): PackageTypeRoots
    {
        return new PackageTypeRoots(['acme/pkg' => [
            $this->directory.'/roots/first' => 'first reason',
            $this->directory.'/roots/second' => 'second reason',
        ]]);
    }

    private function factory(): TypeScriptTransformerConfigFactory
    {
        return TypeScriptTransformerConfigFactory::create()
            ->outputDirectory($this->directory.'/types')
            ->configPath('/host/app/Providers/TypeScriptTransformerServiceProvider.php');
    }

    private function config(Writer $writer): TypeScriptTransformerConfig
    {
        return $this->factory()->extension($this->extension())->writer($writer)->get();
    }

    /** @return list<class-string> */
    private function classes(array $objects): array
    {
        return array_values(array_map(fn (object $o) => $o::class, $objects));
    }

    private function last(array $items): object
    {
        return $items[array_key_last($items)];
    }

    private function property(object $object, string $name): mixed
    {
        return (new ReflectionProperty($object, $name))->getValue($object);
    }

    public function test_the_fixed_parts_apply_in_order(): void
    {
        $config = $this->config(new ModuleWriter(path: null));

        $this->assertSame([
            DeclaredParticleTransformedProvider::class,
            LaravelTypesTransformedProvider::class,
            LaravelDataTransformedProvider::class,
            TransformerProvider::class,
        ], $this->classes($config->transformedProviders));
        $this->assertSame([DataClassTransformer::class, EnumTransformer::class], $this->classes($config->transformers));
        $this->assertSame(
            [app_path(), $this->directory.'/roots/first', $this->directory.'/roots/second'],
            $this->property($this->last($config->transformedProviders), 'directories'),
        );

        $replace = $config->providedVisitorClosures[0];
        $this->assertInstanceOf(ReplaceTypesVisitorClosure::class, $replace);
        $this->assertSame([CarbonInterface::class, DateTimeInterface::class], array_keys($this->property($replace, 'typeReplacements')));

        // configPath() replaces the list; the extension must leave the host provider's path in place.
        $this->assertSame(['/host/app/Providers/TypeScriptTransformerServiceProvider.php'], $config->configPaths);
    }

    public function test_the_default_roots_are_read_from_the_host(): void
    {
        $factory = $this->factory()->extension(new BeamTypeScriptTransformerExtension)->writer(new GlobalNamespaceWriter);
        $directories = $this->property($factory, 'directoriesToTransform');

        $this->assertSame([app_path(), ...PackageTypeRoots::forHost(base_path())->directories()], $directories);
    }

    public function test_a_datetime_property_emits_string(): void
    {
        TypeScriptTransformer::create($this->config(new GlobalNamespaceWriter('generated.d.ts')))->execute();

        $generated = (string) file_get_contents($this->directory.'/types/generated.d.ts');
        $this->assertMatchesRegularExpression('/\bat: string,/', $generated, $generated);
        $this->assertMatchesRegularExpression('/\bseen: string,/', $generated, $generated);
    }

    public function test_adding_the_extension_twice_is_idempotent(): void
    {
        $once = $this->factory()->extension($this->extension())->writer(new GlobalNamespaceWriter)->get();
        $twice = $this->factory()->extension($this->extension())->extension($this->extension())->writer(new GlobalNamespaceWriter)->get();

        $this->assertSame($this->classes($once->transformedProviders), $this->classes($twice->transformedProviders));
        $this->assertSame($this->classes($once->transformers), $this->classes($twice->transformers));
        $this->assertSame(
            $this->property($this->last($once->transformedProviders), 'directories'),
            $this->property($this->last($twice->transformedProviders), 'directories'),
        );
    }

    public function test_a_pre_registered_laravel_data_extension_keeps_its_arguments(): void
    {
        $config = $this->factory()
            ->extension(new LaravelDataTypeScriptTransformerExtension(customLazyTypes: [ExtensionLazy::class]))
            ->extension($this->extension())
            ->writer(new GlobalNamespaceWriter)
            ->get();

        $data = array_values(array_filter($config->transformers, fn (object $t) => $t instanceof DataClassTransformer));
        $this->assertCount(1, $data);
        $this->assertSame([ExtensionLazy::class], $this->property($data[0], 'customLazyTypes'));
        $this->assertSame([
            LaravelTypesTransformedProvider::class,
            LaravelDataTransformedProvider::class,
            DeclaredParticleTransformedProvider::class,
            TransformerProvider::class,
        ], $this->classes($config->transformedProviders));
    }

    public function test_client_references_resolve_under_each_writer(): void
    {
        $ref = str_replace('\\', '.', ExtensionRootData::class);
        $model = ['operations' => [['name' => 'extension.show', 'path' => '/x', 'method' => 'GET', 'returns' => ['ref' => $ref]]]];

        $module = (new ClientTypeReferences)->resolve($model, $this->directory.'/sdk', $this->config(new ModuleWriter(path: null)));
        $this->assertSame('ExtensionRootData', $module[$ref]['name']);
        $this->assertArrayHasKey('module', $module[$ref]);

        $ambient = (new ClientTypeReferences)->resolve($model, $this->directory.'/sdk', $this->config(new GlobalNamespaceWriter('generated.d.ts')));
        $this->assertSame(['name' => $ref], $ambient[$ref]);
    }

    /**
     * The cross-seam invariant (ts-transformer-convergence B2): every type a route's client references is in the
     * generated tree, even when the host's only declaration is a route and the DTO lives outside `app_path()` and
     * every declared root. The model is built the way Tower's route manifest builds it, from
     * {@see RouteReturnType::for()} and {@see RouteReturnType::streamsFor()}.
     */
    public function test_route_declared_client_references_resolve_under_each_writer(): void
    {
        $particles = new ParticleResourceRegistry;
        $operations = new ParticleOperationRegistry;
        $returns = new RouteReturnType($particles, $operations);
        $router = new Router(new Dispatcher($this->app), $this->app);
        $router->get('/route-only', [ExtensionRouteController::class, 'show'])->name('route-only.show');
        $router->get('/route-only/events', [ExtensionRouteController::class, 'events'])->name('route-only.events');

        $operationsModel = [];
        foreach ($router->getRoutes()->getRoutes() as $route) {
            $operation = ['name' => $route->getName(), 'path' => '/'.$route->uri(), 'method' => 'GET'];
            if ($type = $returns->for($route)) {
                $operation['returns'] = ['ref' => $type['type']];
            }
            if ($streams = $returns->streamsFor($route)) {
                $operation['meta']['streams'] = $streams;
            }
            $operationsModel[] = $operation;
        }
        $model = ['operations' => $operationsModel];
        $this->assertSame(
            [str_replace('\\', '.', ExtensionRouteBodyData::class)],
            array_values(array_filter(array_map(fn ($o) => $o['returns']['ref'] ?? null, $operationsModel))),
        );

        // Nothing else declares anything: empty particle registries, empty roots outside the fixture's directory.
        $config = fn (Writer $writer, Router $router) => $this->factory()
            ->extension(new BeamTypeScriptTransformerExtension(
                new DeclaredParticleTypes($particles, $operations, new ResourceContributionRegistry),
                new PackageTypeRoots([]),
                new DeclaredRouteTypes($router, $returns),
            ))
            ->writer($writer)
            ->get();

        $module = (new ClientTypeReferences)->resolve($model, $this->directory.'/sdk', $config(new ModuleWriter(path: null), $router));
        $this->assertSame('ExtensionRouteBodyData', $module[str_replace('\\', '.', ExtensionRouteBodyData::class)]['name']);
        $this->assertSame('ExtensionRouteEventData', $module[str_replace('\\', '.', ExtensionRouteEventData::class)]['name']);

        $ambient = (new ClientTypeReferences)->resolve($model, $this->directory.'/sdk', $config(new GlobalNamespaceWriter('generated.d.ts'), $router));
        $ref = str_replace('\\', '.', ExtensionRouteBodyData::class);
        $this->assertSame(['name' => $ref], $ambient[$ref]);

        // Control: without the route seed the same model cannot resolve, so the seed is what carries it.
        $this->expectException(LogicException::class);
        (new ClientTypeReferences)->resolve($model, $this->directory.'/sdk', $config(new ModuleWriter(path: null), new Router(new Dispatcher($this->app), $this->app)));
    }
}

class ExtensionRootData extends Data
{
    public function __construct(
        public DateTime $at,
        public \DateTimeImmutable $seen,
        public ExtensionNestedData $nested,
    ) {}
}

class ExtensionNestedData extends Data
{
    public function __construct(public string $label) {}
}

class ExtensionLazy {}

class ExtensionRouteBodyData extends Data
{
    public function __construct(public ExtensionRouteNestedData $nested) {}
}

class ExtensionRouteNestedData extends Data
{
    public function __construct(public string $label) {}
}

class ExtensionRouteEventData extends Data
{
    public function __construct(public string $status) {}
}

class ExtensionRouteController
{
    #[ResponseFromData(ExtensionRouteBodyData::class)]
    public function show() {}

    #[StreamsFromData('status', ExtensionRouteEventData::class)]
    public function events() {}
}
