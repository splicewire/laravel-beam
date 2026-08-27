<?php

namespace Splicewire\Beam\Scribe\Strategies;

use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Extracting\Strategies\Strategy;
use ReflectionClass;
use Rushing\LaravelDataSchemasScribe\Attributes\RequestFromData;
use Rushing\LaravelDataSchemasScribe\Support\ScribeBodyParameters;
use Schemastud\DataSchemas\Generators\Generator;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Particle\ParticleOperation;
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
 * host-configured {@see Generator} chain and stashes it under the SAME `custom['dataRequestSchema']` key — so the
 * package's `DataSchemaGenerator` assembly hook needs zero changes.
 *
 * Both legal declaration sites are read, not just the resource's: an OPERATION route (`…/op/{name}`) carries
 * its own stamp and its own `input:`, so it documents through the same path (api-surface-coherence ticket 30).
 * The one asymmetry is the axis — an op's mount chooses the HTTP method, so a GET op's declared input is a
 * query contract and belongs to {@see ParticleOperationParameterStrategy} instead.
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

        // Operation routes (…/op/{name}) declare their payload the same way a resource does — the second
        // legal declaration site — so they document the same way (api-surface-coherence ticket 30).
        if (isset($defaults[ParticleOperationController::RESOURCE], $defaults[ParticleOperationController::NAME])) {
            // ASK, don't demand (api-surface-coherence 102): a route mounted for an operation that is not
            // registered on THIS host is a reportable absence — `ParticleRouteResourceAudit` names it —
            // not a reason to drop the endpoint from the whole spec.
            $operation = app(ParticleOperationRegistry::class)->find(
                $defaults[ParticleOperationController::RESOURCE],
                $defaults[ParticleOperationController::NAME],
            );

            if ($operation === null) {
                return null;
            }

            // A GET op's declared input is a QUERY contract, not a body: the mount picks the method, so the
            // same declaration lands on a different axis. {@see ParticleOperationParameterStrategy} owns it.
            if (in_array('GET', $endpointData->httpMethods, true)) {
                return [];
            }

            return $this->fromDataClass($operation->input, $endpointData);
        }

        $key = $defaults[ParticleController::RESOURCE] ?? null;
        if ($key === null) {
            return null; // Not a particle route — defer to the other strategies.
        }

        // An EXPLICIT `#[RequestFromData]` on the action is the author's statement and wins over this
        // route-derived inference — the same precedence the host's `urlParameters` chain states for
        // `#[UrlParam]`. Without it, a route that carries the `_particle` stamp for GROUPING but declares
        // its own body publishes the resource's input DTO instead: measured on the per-resource filter
        // sub-surface (api-surface-coherence 35), whose `store`/`update` happen to be spelled the same as
        // the generic controller's and so documented `POST /agents/filters` with AgentInputData's fields.
        // Method NAME is this strategy's only trigger, which is exactly why the opt-out has to exist.
        if ($this->declaresItsOwnRequestBody($endpointData)) {
            return null;
        }

        $method = $endpointData->method?->getName();
        if (! in_array($method, self::WRITE_METHODS, true)) {
            return [];
        }

        // ASK, don't demand — see `ParticleResponseStrategy` and api-surface-coherence 102.
        $resource = app(ParticleResourceRegistry::class)->find($key);

        return $resource === null ? null : $this->fromDataClass($resource->input, $endpointData);
    }

    /**
     * Does the action carry an explicit `#[RequestFromData]`?
     */
    protected function declaresItsOwnRequestBody(ExtractedEndpointData $endpointData): bool
    {
        $method = $endpointData->method;

        if ($method === null) {
            return false;
        }

        return $method->getAttributes(RequestFromData::class) !== [];
    }

    /**
     * Generate a declared input class's request-body schema, stashing it on the custom key the package's
     * `DataSchemaGenerator` assembly hook already reads.
     *
     * Shared by both declaration sites — a resource's `input:` and an operation's — because they are the
     * same declaration answering the same question, and reading them differently is how the two axes drift.
     *
     * An undeclared (`null`) or deliberately-empty (`false`) input yields no body. Those two are identical
     * in the artifact and different in meaning; the distinction is enforced at the controller and audited by
     * the sweep, not published (see {@see ParticleOperation}).
     */
    protected function fromDataClass(string|false|null $input, ExtractedEndpointData $endpointData): array
    {
        if (! is_string($input) || ! is_subclass_of($input, Data::class)) {
            return [];
        }

        // Container-resolved, not `new JsonSchemaGenerator(config('data-schemas', []))`. That
        // construction was already correct on CONFIG; what it could not do is DISPATCH.
        // `data-schemas.generators` is a LIST, and the rule "the first member whose `canGenerate()`
        // accepts this class" lives only inside `ChainedGenerator` — so at a multi-generator host
        // (`~/Herd/thingsontv`: `[BlockJsonSchemaGenerator, JsonSchemaGenerator]`) hand-building the
        // default member runs the PLAIN generator over a class the narrow one owns and silently
        // emits a downgraded body behind a successful extraction.
        //
        // GUARDED, and the guard is load-bearing rather than defensive: the chain THROWS where the
        // hand-built generator generated regardless, and a throw inside a Scribe strategy is not
        // loud — Scribe catches per-route, prints only under `-v`, and carries on, so the endpoint
        // VANISHES from the spec (measured twice: `a6989da` in data-schemas-scribe, and this
        // package's own 30-endpoint amputation recorded in `ParticleListParameterStrategy`).
        // Refusal therefore takes the same "no declared body" branch above, which leaves the
        // endpoint documented without a body instead of deleting it.
        $reflection = new ReflectionClass($input);
        $generator = app(Generator::class)->forRequest();

        if (! $generator->canGenerate($reflection)) {
            return [];
        }

        $schema = $generator->generate($reflection);

        $endpointData->custom['dataRequestSchema'] = $schema;

        return ScribeBodyParameters::fromSchema($schema);
    }
}
