<?php

namespace Splicewire\Beam\Tests\Codegen;

use PHPUnit\Framework\TestCase;
use Rushing\Codegen\Model\CodegenModel;
use Rushing\Codegen\Model\Field;
use Rushing\Codegen\Model\Primitive;
use Rushing\Codegen\Model\Type;
use Splicewire\Beam\Codegen\SplicewireClientGenerator;

/**
 * The client-sdk-regen #06 (spine-DTO mapping) + #09 (residue deny-list) proof for the from-spec
 * `Splicewire\Client\*` SDK emitter. Pure unit test (no Laravel boot): the generator is a plain
 * model-in → files-out invocable.
 *
 *  - #06: an op returning a MAPPED component (`options['data']`) emits a thin `Data/<X>.php` spine
 *    adapter (`extends <spineFqn>` + the `{data:…}` `fromResponse` unwrap) and a typed dual Resource
 *    method alongside the raw one.
 *  - #09: a path matching a `options['deny']` glob is dropped from the file map — regeneration never
 *    stomps hand residue (the connector subclass, the webhook, the non-trivial DTO adapters).
 */
class SplicewireClientGeneratorTest extends TestCase
{
    private function model(): CodegenModel
    {
        return (new CodegenModel)
            ->record('CompositionData', fn ($r) => $r->field('id', Type::primitive(Primitive::String)))
            ->operation(
                name: 'getComposition',
                method: 'GET',
                path: '/api/v1/splice/compositions/{id}',
                returns: Type::ref('CompositionData'),
                methods: ['GET'],
                meta: ['tags' => ['Compositions']],
                params: [new Field('id', Type::primitive(Primitive::String), 'The Composition id.')],
            );
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, string>
     */
    private function generate(array $options = []): array
    {
        return (new SplicewireClientGenerator)->invoke([
            'model' => $this->model()->toArray(),
            'options' => array_merge([
                'namespace' => 'Splicewire\\Client',
                'base_url' => 'https://app.splicewire.test',
                'domains' => ['Compositions'],
                'data' => ['CompositionData' => 'Splicewire\\Composition\\Wire\\Composition'],
            ], $options),
        ])['files'];
    }

    public function test_a_mapped_return_emits_a_thin_spine_data_adapter(): void
    {
        $files = $this->generate();

        $this->assertArrayHasKey('Data/Composition.php', $files);

        $adapter = $files['Data/Composition.php'];
        $this->assertStringContainsString('use Splicewire\Composition\Wire\Composition as SpineComposition;', $adapter);
        $this->assertStringContainsString('class Composition extends SpineComposition', $adapter);
        $this->assertStringContainsString('public static function fromResponse(Response $response): self', $adapter);
        $this->assertStringContainsString("return static::fromArray(\$response->json('data') ?? []);", $adapter);
    }

    public function test_a_mapped_return_emits_a_typed_dual_resource_method(): void
    {
        $resource = $this->generate()['Resource/Compositions.php'];

        // The raw send + the typed unwrap alongside it.
        $this->assertStringContainsString('public function getComposition(string $id): Response', $resource);
        $this->assertStringContainsString('public function get(string $id): Composition', $resource);
        $this->assertStringContainsString('return Composition::fromResponse($this->getComposition($id));', $resource);
    }

    public function test_an_unmapped_return_stays_untyped(): void
    {
        // Drop the data map — the same op now resolves to no spine DTO, so only the raw method is emitted.
        $resource = $this->generate(['data' => []])['Resource/Compositions.php'];

        $this->assertStringContainsString('public function getComposition(string $id): Response', $resource);
        $this->assertStringNotContainsString(': Composition', $resource);
        $this->assertArrayNotHasKey('Data/Composition.php', $this->generate(['data' => []]));
    }

    public function test_the_deny_list_drops_matching_paths(): void
    {
        $files = $this->generate([
            'deny' => [
                'SplicewireConnector.php',
                'Webhook/*',
                'Data/Composition.php',
            ],
        ]);

        // The generated base is still emitted; the denied hand-residue paths are not.
        $this->assertArrayHasKey('GeneratedConnector.php', $files);
        $this->assertArrayNotHasKey('Data/Composition.php', $files);
        $this->assertArrayNotHasKey('SplicewireConnector.php', $files);
        foreach (array_keys($files) as $path) {
            $this->assertStringStartsNotWith('Webhook/', $path);
        }
    }
}
