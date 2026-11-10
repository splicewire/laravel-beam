<?php

namespace Splicewire\Beam\Tests\Codegen;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Spatie\TypeScriptTransformer\Transformers\AttributedClassTransformer;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfig;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfigFactory;
use Spatie\TypeScriptTransformer\Writers\GlobalNamespaceWriter;
use Spatie\TypeScriptTransformer\Writers\ModuleWriter;
use Splicewire\Beam\Codegen\ClientTypeReferences;
use Splicewire\Beam\Codegen\DeclaredParticleTransformedProvider;
use Splicewire\Beam\Codegen\DeclaredParticleTypes;
use Splicewire\Beam\Codegen\FrameResourceTypesGenerator;
use Splicewire\Beam\Particle\Contribution\ResourceContribution;
use Splicewire\Beam\Particle\Contribution\ResourceContributionRegistry;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\TestCase;

class FrameResourceTypesGeneratorTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/beam-frame-map-'.uniqid();
        (new Filesystem)->ensureDirectoryExists($this->directory.'/empty');
        config(['beam.client.out_dir' => $this->directory.'/client', 'beam.client.sources.defaults' => null, 'beam.client.sources.operator' => null]);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function fixtures(): void
    {
        $resources = app(ParticleResourceRegistry::class);
        $resources->register(new ParticleResource(key: 'zeta', backing: Model::class, data: MapRow::class, publicRead: MapRow::class));
        $resources->register(new ParticleResource(key: 'alpha', backing: Model::class, data: MapRow::class, editData: MapEdit::class, createResultData: MapCreated::class, publicRead: MapPublic::class));
        // Public and slice DTOs must be discovered from their own declarations, not this unframed resource.
        $resources->register(new ParticleResource(key: 'rest-only', backing: Model::class, data: MapRow::class, frame: false));
        $resources->register(new ParticleResource(key: 'subject-only', backing: Model::class));
        app(ResourceContributionRegistry::class)->register(new ResourceContribution(
            key: 'alpha', as: 'extra', data: MapSlice::class, value: fn (): ?MapSlice => null,
        ));
        foreach (['publish' => MapEdit::class, 'archive' => false, 'api-only' => MapEdit::class] as $name => $input) {
            app(ParticleOperationRegistry::class)->register(new ParticleOperation(
                resource: 'alpha', name: $name, kind: OperationKind::Write, handle: fn () => null,
                input: $input, affordance: $name === 'api-only' ? false : 'action',
            ));
        }
    }

    private function transformer(bool $modules): TypeScriptTransformerConfig
    {
        (new Filesystem)->ensureDirectoryExists($this->directory.'/types');

        return TypeScriptTransformerConfigFactory::create()
            ->outputDirectory($this->directory.'/types')
            ->transformDirectories($this->directory.'/empty')
            ->provider(new DeclaredParticleTransformedProvider(app(DeclaredParticleTypes::class)))
            ->transformer(new AttributedClassTransformer)
            ->writer($modules ? new ModuleWriter(path: null) : new GlobalNamespaceWriter)
            ->get();
    }

    public function test_the_command_emits_the_addressable_map_and_all_four_slots_under_both_writers(): void
    {
        $this->fixtures();
        foreach ([false, true] as $modules) {
            $this->app->instance(TypeScriptTransformerConfig::class, $this->transformer($modules));
            $this->artisan('splicewire:beam:generate:client')
                ->doesntExpectOutputToContain('Frame resource [alpha]')
                ->assertSuccessful();
            $ts = file_get_contents($this->directory.'/client/frame-resources.ts');
            $ref = fn (string $name): string => $modules ? "import('../types/FrameMap').{$name}" : "FrameMap.{$name}";
            $this->assertStringContainsString('export interface FrameResources {', $ts);
            $this->assertStringContainsString("row: ({$ref('Row')} & { 'extra': {$ref('Slice')} | null }) | {$ref('PublicRow')};", $ts);
            $this->assertStringContainsString("edit: {$ref('Edit')};", $ts);
            $this->assertStringContainsString("createResult: {$ref('Created')};", $ts);
            $this->assertStringContainsString("actions: { 'archive': null; 'publish': {$ref('Edit')} };", $ts);
            $this->assertStringNotContainsString("'rest-only':", $ts);
            $this->assertStringNotContainsString("'subject-only':", $ts);
            $this->assertStringNotContainsString("'api-only':", $ts);
            $this->assertStringNotContainsString('Particle.Read', $ts);
            $this->assertLessThan(strpos($ts, "'zeta':"), strpos($ts, "'alpha':"));
            $zeta = substr($ts, strpos($ts, "'zeta':"));
            $this->assertStringContainsString("row: {$ref('Row')};", $zeta);
            $this->assertStringContainsString("edit: {$ref('Row')};", $zeta);
            $this->assertStringContainsString("createResult: {$ref('Row')};", $zeta);
            $this->assertStringContainsString('actions: {};', $zeta);
            $this->assertStringNotContainsString(' | ', $zeta);
        }
    }

    public function test_missing_classes_widen_with_one_warning_per_resource_and_class(): void
    {
        app(ParticleResourceRegistry::class)->register(new ParticleResource(key: 'wide', backing: Model::class, data: MapMissing::class));
        $config = $this->transformer(false);
        $generator = app(FrameResourceTypesGenerator::class);
        $shape = ['wide' => $generator->derive()['wide']];
        $references = (new ClientTypeReferences)->resolveClasses($generator->classes($shape), $this->directory.'/client', $config);
        $result = $generator->render($shape, $references['resolved'], $references['unresolved']);
        $this->assertCount(1, $result['warnings']);
        $this->assertSame('Frame resource [wide]: ['.MapMissing::class.'] is not emitted by the configured TypeScript transformer; typed as Record<string, unknown>.', $result['warnings'][0]);
        $this->assertSame(3, substr_count($result['contents'], 'Record<string, unknown>'));
    }

    public function test_without_a_transformer_the_command_writes_a_wide_map_and_warns(): void
    {
        app(ParticleResourceRegistry::class)->register(new ParticleResource(key: 'wide', backing: Model::class, data: MapRow::class));
        $this->artisan('splicewire:beam:generate:client')
            ->expectsOutputToContain('Frame resource [wide]: ['.MapRow::class.'] has no type because no TypeScript transformer is configured; typed as Record<string, unknown>.')
            ->assertSuccessful();
        $this->assertStringContainsString('row: Record<string, unknown>;', file_get_contents($this->directory.'/client/frame-resources.ts'));
    }
}

#[TypeScript(name: 'Row', location: ['FrameMap'])]
class MapRow extends Data
{
    public function __construct(public int $id) {}
}

#[TypeScript(name: 'Edit', location: ['FrameMap'])]
class MapEdit extends Data
{
    public function __construct(public string $title) {}
}

#[TypeScript(name: 'Created', location: ['FrameMap'])]
class MapCreated extends Data
{
    public function __construct(public string $receipt) {}
}

#[TypeScript(name: 'PublicRow', location: ['FrameMap'])]
class MapPublic extends Data
{
    public function __construct(public string $title) {}
}

#[TypeScript(name: 'Slice', location: ['FrameMap'])]
class MapSlice extends Data
{
    public function __construct(public string $note) {}
}

class MapMissing extends Data
{
    public function __construct(public string $secret) {}
}
