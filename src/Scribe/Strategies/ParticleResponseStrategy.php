<?php

namespace Splicewire\Beam\Scribe\Strategies;

use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Extracting\Strategies\Strategy;
use ReflectionClass;
use Rushing\LaravelDataSchemasScribe\Support\StreamSchemas;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * Drive a DISSOLVED particle route's response from the resource's declared read Data class.
 *
 * Mirrors the package's `UseDataResponse`, but keys off the ROUTE (the `_particle` default) rather than a
 * `#[ResponseFromData]` attribute — the generic {@see ParticleController} carries none. It resolves the
 * {@see \Splicewire\Beam\Particle\ParticleResource}, then shapes the response by the route's verb:
 *   - index → the paginated `ResponseBody` list envelope wrapping `data: [ …$resource->data ]`;
 *   - show/store/update → the single-item envelope `data: $resource->data`.
 *
 * Schema generation is delegated to the SAME {@see JsonSchemaGenerator}; the result is stashed under the
 * SAME `custom['dataResponseSchemas']` key the package uses, so `DataSchemaGenerator` needs zero changes.
 *
 * Returns `null` (defer) for any non-particle route so it composes with the existing attribute strategies.
 */
class ParticleResponseStrategy extends Strategy
{
    use ModelsResponseEnvelope;

    /** Route method names whose response is a single-item envelope. */
    private const ITEM_METHODS = ['show', 'store', 'update'];

    public function __invoke(ExtractedEndpointData $endpointData, array $settings = []): ?array
    {
        $defaults = $endpointData->route?->defaults ?? [];

        // Operation routes (…/op/{name}): document the operation's DECLARED `output:` slot — the same
        // declaration the TypeScript route manifest resolves its hook type from, so the spec and the client
        // cannot disagree about what an operation returns (particle-doctrine-convergence ticket 04).
        if (isset($defaults[ParticleOperationController::RESOURCE], $defaults[ParticleOperationController::NAME])) {
            $output = app(ParticleOperationRegistry::class)->find(
                $defaults[ParticleOperationController::RESOURCE],
                $defaults[ParticleOperationController::NAME],
            )?->output;

            // A Stream operation's `output:` is an event-name map, not a single response body — until
            // particle-doctrine-followups 14 it fell to the generic-object fallback and the endpoint was
            // a silent hole in the spec. The shared StreamSchemas stash gives it the same
            // `text/event-stream` + `x-sse-events` projection an attribute-declared stream gets.
            if (is_array($output)) {
                return StreamSchemas::stash($endpointData, $output);
            }

            return $this->stash($endpointData, $this->operationSchema(
                $defaults[ParticleOperationController::RESOURCE],
                $defaults[ParticleOperationController::NAME],
            ));
        }

        $key = $defaults[ParticleController::RESOURCE] ?? null;
        if ($key === null) {
            return null; // Not a particle route — defer.
        }

        $resource = app(ParticleResourceRegistry::class)->get($key);
        $data = $resource->data;

        if ($data === null || ! is_subclass_of($data, Data::class)) {
            // The Frame `#[ParticleResource]` tier resolves its Data at runtime via the bound resolver; without
            // a declared class there is nothing static to document, so defer.
            return $data === null ? null : [];
        }

        $itemSchema = (new JsonSchemaGenerator)->forResponse()->generate(new ReflectionClass($data));

        $method = $endpointData->method?->getName();
        $envelope = in_array($method, self::ITEM_METHODS, true)
            ? $this->itemEnvelope($itemSchema, new ReflectionClass($data))
            : $this->listEnvelope($itemSchema, new ReflectionClass($data));

        return $this->stash($endpointData, $envelope);
    }

    /**
     * The response schema for one particle operation, from its declared `output:` slot.
     *
     * Two cases fall back to a generic object envelope rather than guessing:
     *   - no slot declared — nothing static to document (and the negative-space detector will say so);
     *   - a route mounted for an operation that was never registered — a reportable absence, not a reason to
     *     fail an entire spec build, which is why this ASKS the registry rather than demanding.
     *
     * A Stream never reaches here — `__invoke` routes its event-name map through the shared
     * {@see StreamSchemas} stash (particle-doctrine-followups 14), so an SSE operation documents its
     * event union instead of a generic object.
     */
    private function operationSchema(string $resource, string $name): array
    {
        $output = app(ParticleOperationRegistry::class)->find($resource, $name)?->output;

        if (! is_string($output) || ! is_subclass_of($output, Data::class)) {
            return ['type' => 'object'];
        }

        $class = new ReflectionClass($output);

        return $this->itemEnvelope(
            (new JsonSchemaGenerator)->forResponse()->generate($class),
            $class,
        );
    }
}
