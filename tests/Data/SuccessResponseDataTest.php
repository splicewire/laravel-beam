<?php

namespace Splicewire\Beam\Tests\Data;

use ReflectionClass;
use Schemastud\DataSchemas\Generators\Generator;
use Spatie\TypeScriptTransformer\Actions\ConnectReferencesAction;
use Spatie\TypeScriptTransformer\Actions\TransformTypesAction;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Spatie\TypeScriptTransformer\Collections\TransformedCollection;
use Spatie\TypeScriptTransformer\Data\WritingContext;
use Spatie\TypeScriptTransformer\PhpNodes\PhpClassNode;
use Spatie\TypeScriptTransformer\References\ClassStringReference;
use Spatie\TypeScriptTransformer\Support\Loggers\ArrayLogger;
use Spatie\TypeScriptTransformer\Transformers\AttributedClassTransformer;
use Splicewire\Beam\Data\ResponseBody;
use Splicewire\Beam\Data\SuccessResponseData;
use Splicewire\Beam\Tests\TestCase;
use Splicewire\Beam\Webhooks\Data\CreatedHookData;
use Splicewire\Beam\Webhooks\Data\CreatedHookResponseData;
use Splicewire\Beam\Webhooks\Data\EventCatalogData;
use Splicewire\Beam\Webhooks\Data\EventCatalogResponseData;
use Splicewire\Beam\Webhooks\Data\HookDeliveriesResponseData;
use Splicewire\Beam\Webhooks\Data\HookDeliveryData;

uses(TestCase::class);

#[TypeScript]
class SuccessResponseFixture extends SuccessResponseData
{
    public function __construct(public string $data) {}
}

it('hydrates inherited metadata and flattens it into the response schema and TypeScript', function () {
    $body = ResponseBody::from(['data' => 'payload', 'message' => 'saved', 'limit' => 12, 'offset' => 2, 'total' => 17])->toResponseArray();
    expect(SuccessResponseFixture::from($body)->toArray())->toEqual($body);

    $schema = app(Generator::class)->forResponse()->generate(new ReflectionClass(SuccessResponseFixture::class));
    expect($schema['properties'])->toHaveKeys(['success', 'message', 'limit', 'offset', 'total', 'data']);

    $transformed = (new TransformTypesAction)->execute([new AttributedClassTransformer], [PhpClassNode::fromReflection(new ReflectionClass(SuccessResponseFixture::class))]);
    $typescript = $transformed[0]->getNode()->write(new WritingContext([]));
    expect($typescript)->toContain('success: boolean', 'message: string | null', 'limit: number | null', 'offset: number | null', 'total: number | null', 'data: string');
});

it('generates typed webhook payloads inside the inherited metadata without losing collection cardinality', function (string $response, string $payload, bool $many) {
    $transformed = (new TransformTypesAction)->execute([new AttributedClassTransformer], [
        PhpClassNode::fromReflection(new ReflectionClass($response)),
        PhpClassNode::fromReflection(new ReflectionClass($payload)),
    ]);
    (new ConnectReferencesAction(new ArrayLogger))->execute(new TransformedCollection($transformed));
    $name = str_replace('\\', '.', $payload);
    $typescript = $transformed[0]->getNode()->write(new WritingContext([
        (new ClassStringReference($payload))->getKey() => $name,
    ]));

    expect($typescript)->toContain('success: boolean', 'message: string | null', 'limit: number | null', 'offset: number | null', 'total: number | null')
        ->toContain('data: '.$name.($many ? '[]' : ''))
        ->not->toContain('undefined');
})->with([
    'catalog' => [EventCatalogResponseData::class, EventCatalogData::class, false],
    'deliveries' => [HookDeliveriesResponseData::class, HookDeliveryData::class, true],
    'created hook' => [CreatedHookResponseData::class, CreatedHookData::class, false],
]);
