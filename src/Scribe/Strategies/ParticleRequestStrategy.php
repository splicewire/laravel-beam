<?php

namespace Splicewire\Beam\Scribe\Strategies;

use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Extracting\Strategies\Strategy;
use ReflectionClass;
use Rushing\LaravelDataSchemasScribe\Support\ScribeBodyParameters;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * Drive a DISSOLVED particle route's request body from the resource's declared input Data class.
 *
 * The generic {@see ParticleController} carries no `#[RequestFromData]` attribute — the schema signal is
 * the ROUTE (its `_particle` default names a {@see ParticleResource} in the registry), not a method
 * attribute. So this mirrors the package's `UseDataRequest` but keys off the route: it resolves the
 * resource, picks its `->input` DTO for the write verbs (store/update), generates the schema with the SAME
 * {@see JsonSchemaGenerator} and stashes it under the SAME `custom['dataRequestSchema']` key — so the
 * package's `DataSchemaGenerator` assembly hook needs zero changes.
 *
 * Returns `null` (defer) for any non-particle route, so it composes transparently alongside the existing
 * attribute strategies.
 */
class ParticleRequestStrategy extends Strategy
{
    /** The route method names whose request body is the resource's input DTO. */
    private const WRITE_METHODS = ['store', 'update'];

    public function __invoke(ExtractedEndpointData $endpointData, array $settings = []): ?array
    {
        $defaults = $endpointData->route?->defaults ?? [];

        // Operation routes (…/op/{name}): a ParticleOperation has no request-params DTO today, so emit a
        // generic (empty) request body.
        // TODO: when ParticleOperation gains a `params` field, generate its schema here like a resource input.
        if (isset($defaults[ParticleOperationController::RESOURCE], $defaults[ParticleOperationController::NAME])) {
            app(ParticleOperationRegistry::class)->get(
                $defaults[ParticleOperationController::RESOURCE],
                $defaults[ParticleOperationController::NAME],
            );

            return [];
        }

        $key = $defaults[ParticleController::RESOURCE] ?? null;
        if ($key === null) {
            return null; // Not a particle route — defer to the other strategies.
        }

        $method = $endpointData->method?->getName();
        if (! in_array($method, self::WRITE_METHODS, true)) {
            return [];
        }

        $resource = app(ParticleResourceRegistry::class)->get($key);
        $input = $resource->input;

        if ($input === null || ! is_subclass_of($input, Data::class)) {
            return [];
        }

        $schema = (new JsonSchemaGenerator)->forRequest()->generate(new ReflectionClass($input));

        $endpointData->custom['dataRequestSchema'] = $schema;

        return ScribeBodyParameters::fromSchema($schema);
    }
}
