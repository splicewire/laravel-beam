<?php

namespace Splicewire\Beam\Scribe\Strategies;

use Knuckles\Camel\Extraction\ExtractedEndpointData;
use ReflectionClass;
use Rushing\LaravelDataSchemasScribe\Support\SchemaExample;
use Splicewire\Beam\Data\ResponseBody;

/**
 * Shared response-envelope modeling for the route-driven Scribe strategies
 * ({@see ParticleResponseStrategy}, {@see ReturnsResponseStrategy}).
 *
 * Both strategies resolve a DTO off the ROUTE (a particle resource's `data:`, or an explicit
 * `->returns()` annotation) and must model the Splicewire `{ data: <DTO> }` response envelope
 * ({@see ResponseBody}) — a single-item object or a paginated list. The
 * generated item schema's inline root is promoted into `$defs` under its class short-name (the
 * component name the package emits elsewhere) and referenced, so the document de-duplicates and
 * `DataSchemaGenerator` hoists it into `components/schemas`. The result is stashed on the SAME
 * `custom['dataResponseSchemas']` key the package's `UseDataResponse` uses, so the document
 * assembly hook needs zero changes.
 */
trait ModelsResponseEnvelope
{
    /**
     * Single-item envelope: `{ data: <item> }`.
     *
     * @param  array<string, mixed>  $itemSchema
     * @return array<string, mixed>
     */
    protected function itemEnvelope(array $itemSchema, ReflectionClass $class): array
    {
        [$ref, $defs] = $this->promoteToDef($itemSchema, $class);

        return [
            'type' => 'object',
            'properties' => ['data' => ['$ref' => $ref]],
            '$defs' => $defs,
        ];
    }

    /**
     * Paginated list envelope: `{ data: [<item>], limit, offset, total }` — mirrors
     * {@see ResponseBody::paginated()}.
     *
     * @param  array<string, mixed>  $itemSchema
     * @return array<string, mixed>
     */
    protected function listEnvelope(array $itemSchema, ReflectionClass $class): array
    {
        [$ref, $defs] = $this->promoteToDef($itemSchema, $class);

        return [
            'type' => 'object',
            'properties' => [
                'data' => ['type' => 'array', 'items' => ['$ref' => $ref]],
                'limit' => ['type' => 'integer'],
                'offset' => ['type' => 'integer'],
                'total' => ['type' => 'integer'],
            ],
            '$defs' => $defs,
        ];
    }

    /**
     * Move a generated item schema's inline root into `$defs` under its class short-name and return
     * `[$ref, $defs]` — merging any `$defs` the item itself carried (nested Data classes).
     *
     * @param  array<string, mixed>  $itemSchema
     * @return array{0: string, 1: array<string, mixed>}
     */
    protected function promoteToDef(array $itemSchema, ReflectionClass $class): array
    {
        $name = $class->getShortName();
        $nested = $itemSchema['$defs'] ?? [];
        unset($itemSchema['$defs']);

        $defs = array_merge($nested, [$name => $itemSchema]);

        return ['#/$defs/'.$name, $defs];
    }

    /**
     * Stash the 200 schema on the endpoint (for `DataSchemaGenerator`) and return the flat Scribe response
     * (an example payload for HTML/Postman).
     *
     * @param  array<string, mixed>  $schema
     * @return array<int, array<string, mixed>>
     */
    protected function stash(ExtractedEndpointData $endpointData, array $schema): array
    {
        $endpointData->custom['dataResponseSchemas'] = [[
            'status' => 200,
            'schema' => $schema,
            'description' => null,
        ]];

        return [[
            'status' => 200,
            'content' => json_encode(SchemaExample::build($schema), JSON_PRETTY_PRINT),
            'description' => '',
        ]];
    }
}
