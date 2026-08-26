<?php

namespace Splicewire\Beam\Scribe\Strategies;

use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Extracting\Strategies\Strategy;
use ReflectionClass;
use Rushing\LaravelDataSchemasScribe\Support\ScribeBodyParameters;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;

/**
 * Document a particle OPERATION's query contract — the sibling of {@see ParticleListParameterStrategy} one
 * declaration site over, and of {@see ParticleRequestStrategy} one axis over (api-surface-coherence 30).
 *
 * Two sources, and the second is the reason this strategy has to exist at all:
 *
 *  - **The host's declaration.** An op's `input:` is its payload contract, and the MOUNT decides where that
 *    payload arrives: `Route::particleOp()` chooses the HTTP method, so a GET op accepts its declared class
 *    as a query string while every other op accepts it as a body. One declaration, two axes, and neither the
 *    declarant nor the registry knows which one applies — only the route does. (Ticket 10's *registration is
 *    one, exposure is many*, in the first place where the exposure changes what the declaration MEANS on the
 *    wire rather than merely where it sits.)
 *  - **The framework's own parameters.** A Task honours `?async`, and no host `input:` can declare it because
 *    it is not the host's — it is beam's, supplied by {@see ParticleOperationController::runTask()}. Before
 *    this strategy it existed only as PROSE in the endpoint description, which meant the reference described
 *    a real, enforced parameter that a generated client had no way to send. The names come from
 *    {@see ParticleOperation::frameworkParameters()} — the operation's, not the kind's, so a `signed:` op's
 *    `expires`/`signature` publish too (ticket 95) — and the branch that reads them and the reference
 *    that publishes them cannot disagree.
 *
 * Returns `null` (defer) for any non-operation route, so it composes transparently alongside Scribe's stock
 * query-parameter strategies. It can never contend with {@see ParticleListParameterStrategy}: that one keys
 * off `_particle` and this one off `_particle_op_resource`, and no route carries both.
 */
class ParticleOperationParameterStrategy extends Strategy
{
    public function __invoke(ExtractedEndpointData $endpointData, array $settings = []): ?array
    {
        $defaults = $endpointData->route?->defaults ?? [];

        $resource = $defaults[ParticleOperationController::RESOURCE] ?? null;
        $name = $defaults[ParticleOperationController::NAME] ?? null;

        if ($resource === null || $name === null) {
            return null; // Not an operation route — defer to the other strategies.
        }

        $operation = app(ParticleOperationRegistry::class)->get($resource, $name);

        return [
            ...$this->declared($operation, $endpointData),
            ...$this->framework($operation),
        ];
    }

    /**
     * The op's declared `input:`, projected as query parameters — but ONLY on a GET mount, where the query
     * string is the axis the payload actually arrives on. On any other method the same declaration is a
     * request body and {@see ParticleRequestStrategy} owns it.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function declared(ParticleOperation $operation, ExtractedEndpointData $endpointData): array
    {
        if (! in_array('GET', $endpointData->httpMethods, true)) {
            return [];
        }

        $input = $operation->input;

        if (! is_string($input) || ! is_subclass_of($input, Data::class)) {
            return [];
        }

        return ScribeBodyParameters::fromSchema(
            (new JsonSchemaGenerator)->forRequest()->generate(new ReflectionClass($input)),
        );
    }

    /**
     * Beam's own parameters for this operation — a Task's `?async`, plus the URL signer's
     * `expires`/`signature` on a `signed:` op. The list comes from
     * {@see ParticleOperation::frameworkParameters()}, the same call {@see rejectInput()} enforces
     * against, so a new framework parameter is published by declaring it there rather than by editing
     * this file, and the reference can never describe a different set than the branch forgives.
     *
     * Every one of them carries a real description, not a placeholder: the parameter guard
     * (api-surface-coherence ticket 29) forgives NOTHING on the query axis, so a framework parameter
     * that reached the spec undescribed would fail the host's own coverage test — which is the
     * intended coupling, not a hazard.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function framework(ParticleOperation $operation): array
    {
        $parameters = [];

        foreach ($operation->frameworkParameters() as $parameter) {
            $parameters[$parameter] = match ($parameter) {
                'expires' => [
                    'type' => 'integer',
                    'description' => 'Unix timestamp at which the signed link stops being accepted. Part of '
                        .'the signature, so it cannot be extended without re-minting the URL — supplied by '
                        .'`URL::temporarySignedRoute()`, never composed by hand.',
                    'required' => false,
                    'example' => null,
                ],
                'signature' => [
                    'type' => 'string',
                    'description' => 'The URL signature that admits this request without an authenticated '
                        .'actor. Supplied by `URL::temporarySignedRoute()`; a request that carries a valid '
                        .'one skips the operation\'s ability check.',
                    'required' => false,
                    'example' => null,
                ],
                ParticleOperationController::ASYNC => [
                    'type' => 'boolean',
                    'description' => 'Run the operation queued (the default) or inline. Pass `false` to '
                        .'dispatch synchronously and receive the outcome in the same request.',
                    'required' => false,
                    // No example on purpose: an optional parameter with a null example stays documented but
                    // drops out of the rendered example request — ticket 21's convention on the same axis.
                    'example' => null,
                ],
                default => [
                    'type' => 'string',
                    'description' => "The `{$parameter}` parameter.",
                    'required' => false,
                    'example' => null,
                ],
            };
        }

        return $parameters;
    }
}
