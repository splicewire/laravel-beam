<?php

namespace Splicewire\Beam\Scribe\Strategies;

use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Extracting\Strategies\Strategy;
use ReflectionClass;
use Rushing\LaravelDataSchemasScribe\Support\ScribeBodyParameters;
use Schemastud\DataSchemas\Generators\Generator;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Particle\Delivery\DeliveryResolvers;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Scribe\OpenApi\DeliveryGenerator;

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

        // ASK, don't demand (api-surface-coherence 102) — an op route with no registration on this host
        // documents without its query contract rather than vanishing from the spec.
        $operation = app(ParticleOperationRegistry::class)->find($resource, $name);

        if ($operation === null) {
            return null;
        }

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

        // Container-resolved for chain dispatch, guarded because the chain throws and Scribe
        // swallows — see {@see ParticleRequestStrategy::fromDataClass()}. Refusal takes the same
        // empty return the two branches above take, so the op keeps its framework parameters
        // (`?async`, the signer's `expires`/`signature`) and loses only the declared axis.
        $reflection = new ReflectionClass($input);
        $generator = app(Generator::class)->forRequest();

        if (! $generator->canGenerate($reflection)) {
            return [];
        }

        return ScribeBodyParameters::fromSchema($generator->generate($reflection));
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
                ParticleOperation::FORMAT_PARAMETER => $this->format($operation),
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

    /**
     * The `?format` parameter of a `delivery:`-declaring operation, off the delivery's own live
     * enumeration (particle-operation-surface 14).
     *
     * It reaches this method only because {@see ParticleOperation::frameworkParameters()} listed it,
     * and that list is empty unless the delivery enumerates a format axis — so a delivery with one
     * representation publishes NO parameter rather than a zero-member enum, which is the same call the
     * rendering surface's parameter strategy made, for the same reason, until
     * particle-operation-surface 13 deleted it: the absent parameter IS the accurate description.
     *
     * The enum published here is the same expression
     * {@see ParticleOperationController::format()} refuses against,
     * so the documented set and the enforced set cannot drift.
     *
     * The default rides `example` rather than a `default` keyword — Scribe's parameter model has no
     * `default` slot, and the machine-readable `schema.default` is written at document assembly by
     * {@see DeliveryGenerator}, which is where the rest of
     * this endpoint's un-expressible spec lives too.
     *
     * @return array<string, mixed>
     */
    protected function format(ParticleOperation $operation): array
    {
        $formats = $operation->formats();
        $default = DeliveryResolvers::contract($operation)['default'] ?? null;

        $set = implode(', ', array_map(fn (string $format) => "`{$format}`", $formats));

        $applied = $default === null
            ? ' Omit it to get the operation\'s own default.'
            : " Defaults to `{$default}`.";

        return [
            'type' => 'string',
            'description' => "Which representation to deliver — one of {$set}.".$applied
                .' A value outside that set comes back 422 on `format`, before the operation runs.',
            'required' => false,
            'enumValues' => $formats,
            'example' => $default,
        ];
    }
}
