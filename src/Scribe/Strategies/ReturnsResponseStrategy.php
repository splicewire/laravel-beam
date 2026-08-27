<?php

namespace Splicewire\Beam\Scribe\Strategies;

use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Extracting\Strategies\Strategy;
use ReflectionClass;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Routing\RouteReturnType;

/**
 * Drive an endpoint's response from the route's `->returns()` annotation — the SAME signal the TS route
 * manifest reads ({@see RouteReturnType}), now bridged to the OpenAPI spec.
 *
 * The `returns` macro — beam's own, since api-surface-coherence 15 moved it under `->beam()` — stashes the
 * response DTO FQN in the route action, with `returnsMany` flagging a list endpoint. A
 * route declaring `->returns(FooData::class)` therefore documents a `{ data: FooData }` envelope response;
 * `->returns(FooData::class, many: true)` documents the paginated list envelope `{ data: [FooData], … }`.
 *
 * Keys off the ROUTE, mirroring {@see ParticleResponseStrategy} (which keys off a particle resource's
 * declared `data:`), and shares the same envelope modeling via {@see ModelsResponseEnvelope} — the result
 * is stashed under the SAME `custom['dataResponseSchemas']` key, so `DataSchemaGenerator` hoists the DTO
 * into `components/schemas` and rewrites the operation response to a `$ref` with zero changes.
 *
 * Without a `->returns()` annotation this is a response-blindness no-op: it returns `null` (defer) so it
 * composes with `UseDataResponse` (attribute-driven) and `ParticleResponseStrategy` (particle-derived).
 * Explicit `->returns()` is the override-winning source, matching `RouteReturnType`'s precedence, so this
 * strategy is registered AHEAD of the particle strategy.
 */
class ReturnsResponseStrategy extends Strategy
{
    use ModelsResponseEnvelope;

    public function __invoke(ExtractedEndpointData $endpointData, array $settings = []): ?array
    {
        $returns = $endpointData->route?->getAction('returns');

        if (! is_string($returns) || ! is_subclass_of($returns, Data::class)) {
            return null; // No `->returns()` annotation (or a non-Data target) — defer.
        }

        $class = new ReflectionClass($returns);
        $itemSchema = (new JsonSchemaGenerator((array) config('data-schemas', [])))->forResponse()->generate($class);

        $envelope = $endpointData->route->getAction('returnsMany')
            ? $this->listEnvelope($itemSchema, $class)
            : $this->itemEnvelope($itemSchema, $class);

        return $this->stash($endpointData, $envelope);
    }
}
