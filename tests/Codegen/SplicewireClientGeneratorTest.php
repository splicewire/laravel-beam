<?php

namespace Splicewire\Beam\Tests\Codegen;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Rushing\Codegen\Model\CodegenModel;
use Rushing\Codegen\Model\Field;
use Rushing\Codegen\Model\Primitive;
use Rushing\Codegen\Model\RecordType;
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
                'domains' => ['Compositions' => ['GET /api/v1/splice/compositions/{id}']],
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

    public function test_the_registry_trims_a_domain_to_its_registered_ops(): void
    {
        // A model with two Compositions ops; the registry names only one of them (#08's superset trim, now
        // expressed in the one `domains` registry rather than a second `include` map).
        $files = (new SplicewireClientGenerator)->invoke([
            'model' => $this->twoOpModel()->toArray(),
            'options' => [
                'namespace' => 'Splicewire\\Client',
                'base_url' => 'https://app.splicewire.test',
                'domains' => ['Compositions' => ['GET /api/v1/splice/compositions/{id}']],
            ],
        ])['files'];

        $this->assertArrayHasKey('Requests/Compositions/GetComposition.php', $files);
        $this->assertArrayNotHasKey('Requests/Compositions/ListCell.php', $files);
    }

    public function test_the_registry_names_the_sdk_domain_not_the_spec_tag(): void
    {
        // The op is tagged `Compositions`; the SDK ships it under `Studio`. api-surface-coherence 88: the
        // published namespace is registry data and does not move when a docs group is renamed.
        $files = (new SplicewireClientGenerator)->invoke([
            'model' => $this->model()->toArray(),
            'options' => [
                'namespace' => 'Splicewire\\Client',
                'base_url' => 'https://app.splicewire.test',
                'domains' => ['Studio' => ['GET /api/v1/splice/compositions/{id}']],
            ],
        ])['files'];

        $this->assertArrayHasKey('Requests/Studio/GetComposition.php', $files);
        $this->assertArrayHasKey('Resource/Studio.php', $files);
        $this->assertArrayNotHasKey('Requests/Compositions/GetComposition.php', $files);
    }

    public function test_a_registered_op_the_spec_does_not_carry_fails_loud(): void
    {
        // 88's core defect: a registry entry matching nothing used to be indistinguishable from a domain
        // with no operations, so nine SDK domains regenerated nothing in silence.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('GET /api/v1/gone');

        $this->generate(['domains' => [
            'Compositions' => ['GET /api/v1/splice/compositions/{id}', 'GET /api/v1/gone'],
        ]]);
    }

    public function test_one_op_may_not_be_registered_by_two_domains(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exactly one SDK domain');

        $this->generate(['domains' => [
            'Compositions' => ['GET /api/v1/splice/compositions/{id}'],
            'Studio' => ['GET /api/v1/splice/compositions/{id}'],
        ]]);
    }

    public function test_a_legacy_tag_allowlist_is_refused_rather_than_silently_matching_nothing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('REGISTRY');

        $this->generate(['domains' => ['Compositions']]);
    }

    public function test_query_parameters_are_sent_and_a_bracketed_family_collapses_to_one_array(): void
    {
        // 88 §1: `filter[silos]` is not a PHP identifier, and feeding it to the printer threw
        // `Value 'filter[silos]' is not valid name`, taking the whole stack down. The family collapses to a
        // single `array $filter` whose keys are re-bracketed on the wire; identifier-named query params ride
        // as their own nullable args. Every query arg is defaulted, so an index keeps a no-arg constructor.
        $model = (new CodegenModel)->operation(
            name: 'listFragments',
            method: 'GET',
            path: '/api/v1/fragments',
            methods: ['GET'],
            meta: ['tags' => ['Fragments']],
            params: [
                new Field('filter[silos]', Type::optional(Type::primitive(Primitive::String)), 'Silo filter.'),
                new Field('filter[tags:all]', Type::optional(Type::primitive(Primitive::String)), 'Tag filter.'),
                new Field('per_page', Type::optional(Type::primitive(Primitive::Int)), 'Records per page.'),
            ],
        );

        $files = (new SplicewireClientGenerator)->invoke([
            'model' => $model->toArray(),
            'options' => [
                'namespace' => 'Splicewire\\Client',
                'base_url' => 'https://app.splicewire.test',
                'domains' => ['Fragments' => ['GET /api/v1/fragments']],
            ],
        ])['files'];

        $request = $files['Requests/Fragments/ListFragment.php'];

        $this->assertStringContainsString('protected ?int $perPage = null,', $request);
        $this->assertStringContainsString('protected array $filter = [],', $request);
        $this->assertStringContainsString('Keyed by facet: silos, tags:all.', $request);
        $this->assertStringNotContainsString('filter[silos]$', $request);
        // The wire serialization: scalars by their own key, the family re-bracketed, nulls filtered out.
        $this->assertStringContainsString("'per_page' => \$this->perPage,", $request);
        $this->assertStringContainsString('$query["filter[{$facet}]"] = $value;', $request);
        $this->assertStringContainsString('return array_filter($query, fn ($value) => $value !== null);', $request);
    }

    private function twoOpModel(): CodegenModel
    {
        return (new CodegenModel)
            ->record('CompositionData', fn ($r) => $r->field('id', Type::primitive(Primitive::String)))
            ->operation(
                name: 'getComposition', method: 'GET', path: '/api/v1/splice/compositions/{id}',
                returns: Type::ref('CompositionData'), methods: ['GET'], meta: ['tags' => ['Compositions']],
                params: [new Field('id', Type::primitive(Primitive::String), 'The Composition id.')],
            )
            ->operation(
                name: 'listCells', method: 'GET', path: '/api/v1/splice/compositions/{id}/cells',
                methods: ['GET'], meta: ['tags' => ['Compositions']],
                params: [new Field('id', Type::primitive(Primitive::String), 'The Composition id.')],
            );
    }

    public function test_a_multipart_op_emits_a_hasmultipartbody_request_with_file_part(): void
    {
        // A file-upload op: `meta['multipart']` truthy, `meta['multipartFile']` names the binary field. The
        // generator must switch off `HasJsonBody` onto Saloon's `HasMultipartBody` and emit `MultipartValue`
        // parts — a `filename`-bearing file part plus a synthetic `$fileName` ctor param. (client-sdk-regen
        // Phase 1.)
        $model = (new CodegenModel)->operation(
            name: 'attachFragment',
            method: 'POST',
            path: '/api/v1/fragments/attach',
            methods: ['POST'],
            meta: ['tags' => ['Fragments'], 'multipart' => true, 'multipartFile' => 'file'],
            body: (function () {
                $b = new RecordType('AttachBody');
                $b->field('file', Type::primitive(Primitive::String));
                $b->field('tags', Type::listOf(Type::primitive(Primitive::String)));

                return $b;
            })(),
        );

        $files = (new SplicewireClientGenerator)->invoke([
            'model' => $model->toArray(),
            'options' => [
                'namespace' => 'Splicewire\\Client',
                'base_url' => 'https://app.splicewire.test',
                'domains' => ['Fragments' => ['POST /api/v1/fragments/attach']],
            ],
        ])['files'];

        $request = $files['Requests/Fragments/CreateAttach.php'];

        $this->assertStringContainsString('use Saloon\Traits\Body\HasMultipartBody;', $request);
        $this->assertStringContainsString('use Saloon\Data\MultipartValue;', $request);
        $this->assertStringNotContainsString('HasJsonBody', $request);
        $this->assertStringContainsString('use HasMultipartBody;', $request);
        $this->assertStringContainsString('implements HasBody', $request);
        // The binary field is typed `mixed`, followed by a synthetic `$fileName`.
        $this->assertStringContainsString('protected mixed $file', $request);
        $this->assertStringContainsString('protected string $fileName', $request);
        // The file part carries the filename; the other field is a plain part.
        $this->assertStringContainsString(
            "new MultipartValue(name: 'file', value: \$this->file, filename: \$this->fileName)",
            $request,
        );
        // An array multipart field spreads to one part per element (Saloon rejects a raw array value).
        $this->assertStringContainsString(
            "...array_map(fn (\$value) => new MultipartValue(name: 'tags', value: \$value), \$this->tags)",
            $request,
        );
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
