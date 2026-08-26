<?php

namespace Splicewire\Beam\Scribe\Strategies;

use Illuminate\Database\Eloquent\Model;
use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Extracting\Shared\UrlParamsNormalizer;
use Knuckles\Scribe\Extracting\Strategies\UrlParameters\GetFromLaravelAPI;

/**
 * Scribe's stock `GetFromLaravelAPI`, with the one line that reads a row out of the database removed.
 *
 * ## What was removed and why
 *
 * `GetFromLaravelAPI::inferBetterTypesAndExamplesForEloquentUrlParameters()` ends with
 *
 * ```php
 * $parameters[$paramName]['example'] = $modelInstance::first()->{$routeKey} ?? null;
 * ```
 *
 * — a live `SELECT … LIMIT 1` executed during documentation generation, whose result is written into
 * the published artifact as that parameter's example. Whatever row happens to be first in the table
 * becomes a public example value. Two costs, and the second is the one that makes this a defect
 * rather than a preference:
 *
 *  1. **Disclosure.** `storage/app/scribe/openapi.yaml` is served unauthenticated at
 *     `GET beam/openapi.yaml` (`beam.core.openapi.middleware` is `[]` by default — a docs surface
 *     nobody can read is not a docs surface). A real primary key therefore reaches the public
 *     internet on every host that generates against a populated database. Nothing about the mechanism
 *     restricts the leaked column to an opaque id: it publishes whatever `getRouteKeyName()` names,
 *     and a slug-, name- or email-shaped route key is a legal Laravel model.
 *  2. **Reproducibility.** The artifact stops being a function of the code. Two machines generate two
 *     different documents from the same commit, which is exactly the property a committed spec or a
 *     generated client needs.
 *
 * ## What was kept
 *
 * Everything else, including the TYPE inference this method mostly exists for — `getKeyName()` /
 * `getKeyType()` / `getRouteKeyName()` are reflection over the model, not queries, and an `integer`
 * path parameter documented as `string` is a real regression. The type is set; only the example is
 * left for a later strategy to fill.
 *
 * ## Two limbs, not one
 *
 * The suppressed method gathers model instances from two places, and the second is the dangerous one:
 *
 *  - **Type-hinted bindings** — `UrlParamsNormalizer::getTypeHintedEloquentModels()`, i.e. the route
 *    that declares `function show(Fragment $fragment)`.
 *  - **The URL-segment guess** — `findModelFromUrlThing()`, which turns `/tenants/{id}` into
 *    `App\Models\Tenant` and queries that, with no type hint anywhere. This limb is easy to miss when
 *    auditing, it fires on parameters nobody declared anything about, and the models it reaches are
 *    the host's own `App\Models\*` — the ones most likely to carry a human-legible primary key.
 *
 * Both go through the same removed line, so both are closed here.
 *
 * ## Where the example comes from instead
 *
 * Nowhere new. The parent's own `setTypesAndExamplesForOthers()` still runs and still fills any
 * example left null — from the route's `where` constraint via `regexify`, or from a dummy value —
 * and on a Beam host {@see ParticleUrlParameterStrategy} is registered AFTER this one and overwrites
 * both with a value DERIVED from the declared resource. That derivation is the actual answer to "what
 * should a path parameter's example be"; this class only guarantees the answer is never a customer's
 * data.
 *
 * Register it in place of `Strategies\UrlParameters\GetFromLaravelAPI::class` — same position, same
 * contract. It is not additive: leaving the stock strategy in the list alongside this one puts the
 * query back.
 */
class UrlParametersWithoutRowReads extends GetFromLaravelAPI
{
    /**
     * The parent method verbatim, minus the `::first()` read — the example is simply never set here.
     *
     * Kept as an override rather than a post-filter because a post-filter cannot unmake the query:
     * the disclosure is the SELECT reaching a live database during generation, not only the value
     * surviving into the artifact. A strategy registered later can overwrite the example and the row
     * has still been read.
     */
    protected function inferBetterTypesAndExamplesForEloquentUrlParameters(array $parameters, ExtractedEndpointData $endpointData): array
    {
        foreach ($this->eloquentModelsByParameter($parameters, $endpointData) as $paramName => $modelInstance) {
            // If the routeKey is the same as the primary key in the database, use the PK's type.
            $routeKey = $modelInstance->getRouteKeyName();

            $parameters[$paramName]['type'] = $modelInstance->getKeyName() === $routeKey
                ? static::normalizeTypeName($modelInstance->getKeyType())
                : 'string';
        }

        return $parameters;
    }

    /**
     * The parent's model-gathering, lifted out of the suppressed method so the two limbs stay
     * readable and so a subclass can narrow either one without re-copying the query-free body.
     *
     * @param  array<string, array<string, mixed>>  $parameters
     * @return array<string, Model>
     */
    protected function eloquentModelsByParameter(array $parameters, ExtractedEndpointData $endpointData): array
    {
        $modelInstances = [];

        // Limb 1: bound models. Eg if the route is /users/{id} and (User $user) is type-hinted on the
        // method, then {id} is a User and takes the User's key type.
        $typeHinted = UrlParamsNormalizer::getTypeHintedEloquentModels($endpointData->method);

        foreach ($typeHinted as $argumentName => $modelInstance) {
            $routeKey = $modelInstance->getRouteKeyName();

            // In the normalized URL, argument $user might be param {user}, {user_id}, or {id}.
            if (isset($parameters[$argumentName])) {
                $paramName = $argumentName;
            } elseif (isset($parameters["{$argumentName}_{$routeKey}"])) {
                $paramName = "{$argumentName}_{$routeKey}";
            } elseif (isset($parameters[$routeKey])) {
                $paramName = $routeKey;
            } else {
                continue;
            }

            $modelInstances[$paramName] = $modelInstance;
        }

        // Limb 2: the segment guess — no type hint, no binding, just `/things/{id}` → App\Models\Thing.
        foreach ($parameters as $name => $data) {
            if (isset($data['type'])) {
                continue;
            }

            $urlThing = $this->getNameOfUrlThing($endpointData->uri, $name);

            if ($urlThing && ($modelInstance = $this->findModelFromUrlThing($urlThing))) {
                $modelInstances[$name] = $modelInstance;
            }
        }

        return $modelInstances;
    }
}
