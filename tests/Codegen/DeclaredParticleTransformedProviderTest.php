<?php

namespace Splicewire\Beam\Tests\Codegen;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\Router;
use Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData;
use Rushing\LaravelDataSchemasScribe\Attributes\StreamsFromData;
use Spatie\LaravelData\Data;
use Spatie\LaravelTypeScriptTransformer\LaravelData\LaravelDataTypeScriptTransformerExtension;
use Spatie\TypeScriptTransformer\Actions\DiscoverTypesAction;
use Spatie\TypeScriptTransformer\Transformers\EnumTransformer;
use Spatie\TypeScriptTransformer\TypeScriptTransformer;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfig;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfigFactory;
use Spatie\TypeScriptTransformer\Writers\GlobalNamespaceWriter;
use Splicewire\Beam\Codegen\DeclaredParticleTransformedProvider;
use Splicewire\Beam\Codegen\DeclaredParticleTypes;
use Splicewire\Beam\Codegen\DeclaredRouteTypes;
use Splicewire\Beam\Particle\Contribution\ResourceContributionRegistry;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Routing\RouteReturnType;
use Splicewire\Beam\Tests\TestCase;

/**
 * The declared-particle provider's two seeds (ts-transformer-convergence B2): particle declarations, then route
 * declarations. Each seed is walked through the host's configured transformers, so a seeded DTO's nested
 * `BaseData` and enum references emit too, wherever they live.
 *
 * Registries and router are built per test, so beam's own built-in declarations never enter the assertions.
 */
class DeclaredParticleTransformedProviderTest extends TestCase
{
    private string $directory;

    private Router $router;

    private ParticleResourceRegistry $particles;

    private ParticleOperationRegistry $operations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/beam-ts-provider-'.uniqid();
        (new Filesystem)->ensureDirectoryExists($this->directory.'/types');
        (new Filesystem)->ensureDirectoryExists($this->directory.'/scanned');
        (new Filesystem)->ensureDirectoryExists($this->directory.'/unscanned');
        $this->router = new Router(new Dispatcher($this->app), $this->app);
        $this->particles = new ParticleResourceRegistry;
        $this->operations = new ParticleOperationRegistry;
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function particleTypes(): DeclaredParticleTypes
    {
        return new DeclaredParticleTypes($this->particles, $this->operations, new ResourceContributionRegistry);
    }

    private function routeTypes(): DeclaredRouteTypes
    {
        return new DeclaredRouteTypes($this->router, new RouteReturnType($this->particles, $this->operations));
    }

    private function factory(): TypeScriptTransformerConfigFactory
    {
        return TypeScriptTransformerConfigFactory::create()
            ->extension(new LaravelDataTypeScriptTransformerExtension)
            ->transformer(EnumTransformer::class)
            ->transformDirectories($this->directory.'/unscanned')   // an empty root: nothing here is scanned in
            ->outputDirectory($this->directory.'/types')
            ->writer(new GlobalNamespaceWriter('generated.d.ts'));
    }

    /** @return list<class-string> the provided classes, in provided order */
    private function provided(DeclaredParticleTransformedProvider $provider, ?TypeScriptTransformerConfig $config = null): array
    {
        $provider->setConfig($config ?? $this->factory()->get());

        return array_map(fn ($type) => $type->getReference()->classString, $provider->provide());
    }

    public function test_a_route_declared_dto_outside_every_scanned_directory_emits_with_its_nested_references(): void
    {
        $this->router->get('/one', [ProviderSeedController::class, 'show']);
        $this->router->get('/alias', [ProviderSeedController::class, 'show']);
        $this->router->get('/events', [ProviderSeedController::class, 'stream']);

        $provided = $this->provided(new DeclaredParticleTransformedProvider($this->particleTypes(), $this->routeTypes()));

        $this->assertEqualsCanonicalizing([
            ProviderSeedRootData::class, ProviderSeedNestedData::class, ProviderSeedNodeData::class,
            ProviderSeedDocblockLeafData::class, ProviderSeedState::class,
            ProviderSeedEventData::class, ProviderSeedEventDetailData::class,
        ], $provided);
        $this->assertSame(array_values(array_unique($provided)), $provided, 'each reached class is provided once');

        // And the whole run writes them: nothing scans the test directory, so these come from the route seed.
        TypeScriptTransformer::create(
            $this->factory()->provider(new DeclaredParticleTransformedProvider($this->particleTypes(), $this->routeTypes()))->get()
        )->execute();
        $generated = (string) file_get_contents($this->directory.'/types/generated.d.ts');
        foreach (['ProviderSeedRootData', 'ProviderSeedDocblockLeafData', 'ProviderSeedState', 'ProviderSeedEventDetailData'] as $name) {
            $this->assertMatchesRegularExpression('/\btype '.$name.' =/', $generated, $generated);
        }
    }

    public function test_a_class_reached_by_the_route_seed_and_a_directory_scan_emits_once(): void
    {
        file_put_contents($this->directory.'/scanned/ProviderSeedScannedData.php', <<<'PHP'
            <?php

            namespace Splicewire\Beam\Tests\Codegen\ProviderSeedScanned;

            class ProviderSeedScannedData extends \Spatie\LaravelData\Data
            {
                public function __construct(public \Splicewire\Beam\Tests\Codegen\ProviderSeedNestedData $nested) {}
            }
            PHP);
        require_once $this->directory.'/scanned/ProviderSeedScannedData.php';

        $this->router->get('/scanned', [ProviderSeedController::class, 'scanned']);

        // Both reach it: the route seed provides it, and so does the scan of its directory.
        $scanned = 'Splicewire\\Beam\\Tests\\Codegen\\ProviderSeedScanned\\ProviderSeedScannedData';
        $this->assertContains($scanned, $this->provided(new DeclaredParticleTransformedProvider($this->particleTypes(), $this->routeTypes())));
        $this->assertSame([$scanned], array_map(
            fn ($node) => $node->getName(),
            (new DiscoverTypesAction)->execute([$this->directory.'/scanned']),
        ));

        TypeScriptTransformer::create(
            $this->factory()
                ->provider(new DeclaredParticleTransformedProvider($this->particleTypes(), $this->routeTypes()))
                ->transformDirectories($this->directory.'/scanned')
                ->get()
        )->execute();

        $generated = (string) file_get_contents($this->directory.'/types/generated.d.ts');
        $this->assertSame(1, preg_match_all('/\btype ProviderSeedScannedData =/', $generated), $generated);
        // The scanned root's nested DTO lives outside the scan; the seed's walk still reaches it.
        $this->assertSame(1, preg_match_all('/\btype ProviderSeedNestedData =/', $generated), $generated);
    }

    public function test_an_absent_route_declared_class_is_skipped_without_failing(): void
    {
        $this->router->get('/missing', fn () => null)
            ->beam()->returns('Splicewire\\Beam\\Tests\\Codegen\\ProviderSeedMissingData');
        $this->router->get('/one', [ProviderSeedController::class, 'nested']);

        $this->assertSame(
            [ProviderSeedNestedData::class],
            $this->provided(new DeclaredParticleTransformedProvider($this->particleTypes(), $this->routeTypes())),
        );
    }

    public function test_one_constructor_argument_gives_todays_output(): void
    {
        // The particle seed's three shapes: a resource's data, a stream operation's variants, a write's input.
        $this->particles->register(new ParticleResource(
            key: 'provider-seed', backing: Model::class, data: ProviderSeedNestedData::class,
        ));
        $this->operations->register(new ParticleOperation(
            resource: 'provider-seed', name: 'events', kind: OperationKind::Stream,
            handle: fn () => null, ability: false, input: false,
            output: ['changed' => [ProviderSeedEventData::class]],
        ));
        $this->operations->register(new ParticleOperation(
            resource: 'provider-seed', name: 'schedule', kind: OperationKind::Write,
            handle: fn () => null, ability: false, input: ProviderSeedRootData::class, output: null,
        ));
        // A route declaration the one-argument provider must not read.
        $this->router->get('/scanned', [ProviderSeedController::class, 'scanned']);

        // Today's order (the provider at ttc/b01 gives exactly this list for these registries).
        $today = [
            ProviderSeedRootData::class,
            ProviderSeedState::class,
            ProviderSeedDocblockLeafData::class,
            ProviderSeedNodeData::class,
            ProviderSeedNestedData::class,
            ProviderSeedEventData::class,
            ProviderSeedEventDetailData::class,
        ];

        $this->assertSame($today, $this->provided(new DeclaredParticleTransformedProvider($this->particleTypes())));

        // With the route seed present the particle seed's output is unchanged and comes first.
        $both = $this->provided(new DeclaredParticleTransformedProvider($this->particleTypes(), $this->routeTypes()));
        $this->assertSame($today, array_slice($both, 0, count($today)));
    }
}

enum ProviderSeedState: string
{
    case Open = 'open';
    case Closed = 'closed';
}

class ProviderSeedNestedData extends Data
{
    public function __construct(public string $label) {}
}

class ProviderSeedNodeData extends Data
{
    /** @param array<ProviderSeedNodeData> $children */
    public function __construct(public string $id, public array $children = []) {}
}

class ProviderSeedDocblockLeafData extends Data
{
    public function __construct(public string $value) {}
}

class ProviderSeedRootData extends Data
{
    /** @param array<ProviderSeedDocblockLeafData> $leaves */
    public function __construct(
        public ProviderSeedNestedData $nested,
        public ProviderSeedNodeData $tree,
        public array $leaves,
        public ProviderSeedState $state,
    ) {}
}

class ProviderSeedEventDetailData extends Data
{
    public function __construct(public string $detail) {}
}

class ProviderSeedEventData extends Data
{
    public function __construct(public ProviderSeedEventDetailData $detail) {}
}

class ProviderSeedController
{
    #[ResponseFromData(ProviderSeedRootData::class)]
    public function show() {}

    #[ResponseFromData(ProviderSeedNestedData::class)]
    public function nested() {}

    #[StreamsFromData('changed', ProviderSeedEventData::class)]
    public function stream() {}

    #[ResponseFromData('Splicewire\\Beam\\Tests\\Codegen\\ProviderSeedScanned\\ProviderSeedScannedData')]
    public function scanned() {}
}
