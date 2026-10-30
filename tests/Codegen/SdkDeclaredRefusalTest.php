<?php

namespace Splicewire\Beam\Tests\Codegen;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Rushing\Codegen\Model\CodegenModel;
use Rushing\Codegen\Model\Field;
use Rushing\Codegen\Model\Primitive;
use Rushing\Codegen\Model\Type;
use Splicewire\Beam\Codegen\SplicewireClientGenerator;

/**
 * A typed SDK facade dispatches an op's DECLARED refusal (music-capability-lift 34/35 prerequisite): the op's `errors:` slot
 * reaches the spec as a response marked `x-particle-error`, the host's model records it in `meta['errors']`, and the typed
 * facade throws a generated `{ErrorDto}Exception` (a Saloon `RequestException`, so every existing catch still catches it)
 * BEFORE the success adapter's generic `throw()`. Anything else keeps the generic path. The runtime proof lives at a host
 * that carries Saloon; these pin the emitted source.
 */
class SdkDeclaredRefusalTest extends TestCase
{
    /** @param  array<int|string, array<string, mixed>>|null  $errors */
    private function model(?array $errors): CodegenModel
    {
        $meta = ['tags' => ['Augmentations']] + ($errors === null ? [] : ['errors' => $errors]);

        return (new CodegenModel)
            ->record('AugmentationDispatchData', fn ($r) => $r->field('runId', Type::primitive(Primitive::String)))
            ->record('AugmentationErrorData', fn ($r) => $r->field('code', Type::primitive(Primitive::String)))
            ->operation(
                name: 'augmentComposition',
                method: 'POST',
                path: '/api/v1/compositions/{id}/augment',
                returns: Type::ref('AugmentationDispatchData'),
                methods: ['POST'],
                meta: $meta,
                params: [new Field('id', Type::primitive(Primitive::String), 'The Composition id.')],
            );
    }

    /**
     * @param  array<int|string, array<string, mixed>>|null  $errors
     * @param  array<string, mixed>  $options
     * @return array<string, string>
     */
    private function generate(?array $errors, array $options = []): array
    {
        return (new SplicewireClientGenerator)->invoke([
            'model' => $this->model($errors)->toArray(),
            'options' => array_merge([
                'namespace' => 'Splicewire\\Client',
                'base_url' => 'https://app.splicewire.test',
                'domains' => ['Augmentations' => ['POST /api/v1/compositions/{id}/augment']],
                'data' => [
                    'AugmentationDispatchData' => 'Splicewire\\Music\\AugmentationDispatchData',
                    'AugmentationErrorData' => 'Splicewire\\Music\\AugmentationErrorData',
                ],
            ], $options),
        ])['files'];
    }

    private function declared(array $required = ['code', 'detail']): array
    {
        return ['422' => ['ref' => 'AugmentationErrorData', 'required' => $required]];
    }

    public function test_the_typed_facade_throws_the_declared_refusal_before_the_generic_throw(): void
    {
        $resource = $this->generate($this->declared())['Resource/Augmentations.php'];

        $this->assertStringContainsString('$response = $this->augmentComposition($id);', $resource);
        $this->assertStringContainsString('if ($response->status() === 422) {', $resource);
        $this->assertStringContainsString("array_key_exists('code', \$body) && array_key_exists('detail', \$body)", $resource);
        $this->assertStringContainsString('$error = SpineAugmentationErrorData::fromArray($body);', $resource);
        $this->assertStringContainsString('throw new AugmentationErrorDataException($response, $error, 422);', $resource);
        $this->assertLessThan(
            strpos($resource, 'return AugmentationDispatchData::fromResponse($response);'),
            strpos($resource, 'throw new AugmentationErrorDataException('),
            'the declared refusal must be dispatched before the success adapter, whose throw() would pre-empt it.',
        );
    }

    public function test_the_generated_refusal_is_a_request_exception_carrying_the_typed_error_and_status(): void
    {
        $exception = $this->generate($this->declared())['Exceptions/AugmentationErrorDataException.php'];

        $this->assertStringContainsString('namespace Splicewire\Client\Exceptions;', $exception);
        $this->assertStringContainsString('class AugmentationErrorDataException extends RequestException', $exception);
        $this->assertStringContainsString('use Saloon\Exceptions\Request\RequestException;', $exception);
        $this->assertStringContainsString('public readonly SpineAugmentationErrorData $error', $exception);
        $this->assertStringContainsString('public readonly int $status', $exception);
    }

    public function test_an_undeclared_op_keeps_the_typed_facade_it_had(): void
    {
        $files = $this->generate(null);

        $this->assertStringContainsString(
            'return AugmentationDispatchData::fromResponse($this->augmentComposition($id));',
            $files['Resource/Augmentations.php'],
        );
        $this->assertArrayNotHasKey('Exceptions/AugmentationErrorDataException.php', $files);
    }

    public function test_an_unmapped_declared_error_keeps_the_generic_path_and_does_not_fail_generation(): void
    {
        $files = $this->generate($this->declared(), ['data' => ['AugmentationDispatchData' => 'Splicewire\\Music\\AugmentationDispatchData']]);

        $this->assertStringContainsString(
            'return AugmentationDispatchData::fromResponse($this->augmentComposition($id));',
            $files['Resource/Augmentations.php'],
        );
        $this->assertArrayNotHasKey('Exceptions/AugmentationErrorDataException.php', $files);
    }

    public function test_an_error_that_could_not_be_told_from_a_generic_error_body_is_refused(): void
    {
        foreach ([[], ['message'], ['message', 'errors']] as $required) {
            try {
                $this->generate($this->declared($required));
                $this->fail('required '.json_encode($required).' was accepted');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('AugmentationErrorData', $e->getMessage());
            }
        }
    }

    public function test_a_host_names_the_spine_factory_the_error_hydrates_through(): void
    {
        $resource = $this->generate($this->declared(), ['errors' => ['AugmentationErrorData' => ['factory' => 'from']]])['Resource/Augmentations.php'];

        $this->assertStringContainsString('$error = SpineAugmentationErrorData::from($body);', $resource);
    }
}
