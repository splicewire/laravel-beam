<?php

namespace Splicewire\Beam\Scribe\OpenApi;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;
use Knuckles\Camel\Output\OutputEndpointData;
use Knuckles\Scribe\Writing\OpenApiSpecGenerators\OpenApiGenerator;
use Splicewire\Beam\Routing\BeamRouteAction;

/**
 * An `operationId` is a function of the ROUTE, never of the sidebar title (api-surface-coherence 36/78).
 *
 * Two rungs, in order, and the title is not consulted at either:
 *
 *  1. **(E) the declaration** — `action['beam']['operationId']`, written by the mount that registered the
 *     route and read back through {@see BeamRouteAction::operationId()}. ONE key, N declarers: a generator
 *     with an arm per stamp family (`_particle`, `_renderings`, `_renderings_catalog`, `_versions`, …)
 *     needs a new arm every time a macro is added, so the reading was inverted instead.
 *  2. **(B) the wire shape** — verb + the FULL uri, params included as segments, nothing stripped.
 *     `GET api/v1/embeds/{id}/versions` → `getApiV1EmbedsIdVersions`.
 *
 * ## Why a title is not an identifier
 *
 * Scribe derives `operationId` from `metadata->title` and nothing else
 * (`OpenApiSpecGenerators\BaseGenerator::operationId()`), which fails at being an identifier three ways at
 * once. It is **non-unique**: a macro mount puts ONE controller method on N routes, they all inherit its
 * docblock summary, and N operations ship one id — invalid OpenAPI, and a client generator either collides
 * or silently drops all but one. It is **not always an identifier at all**: Scribe's
 * `preg_replace('/[^\w+]/', '', …)` character class KEEPS `+` (almost certainly a typo for `[^\w]`), and
 * four shipped ids carried a literal `+`. And it is **unstable**: rewording a summary silently renames a
 * generated SDK class.
 *
 * This runs after `BaseGenerator` and **overwrites** what it wrote — it does not defer. The precedence is
 * total, so no operation ever reaches the vendor branch and the `+` bug is superseded rather than patched.
 *
 * ## The fallback is deliberately unlovely — do not "improve" it
 *
 * Both obvious improvements are unsafe, and 36 Q8 wrote that down so nobody re-derives it the hard way.
 * **Dropping path params** reintroduces collisions (`/embeds/{id}/versions` would merge with
 * `/embeds/versions`), which is the one property (B) exists to hold. **Stripping the `api/v1` prefix**
 * stops being safe the moment a second prefix (`/api/operator`) is documented. Beyond safety it is a
 * standing incentive: an ugly SDK class name is the cost of not declaring, and the failure mode of a
 * family that has not declared is *ugly but unique*, never duplicate. A missing declaration degrades; it
 * does not break.
 *
 * ## The route is still reachable at assembly time
 *
 * Ticket 32 recorded that a document-assembly generator cannot help, "because Scribe hands it an
 * `OutputEndpointData` which has already dropped the Laravel route". Scribe drops the route REFERENCE, not
 * the route: it is still in the router, and `verb + uri` is an exact key back into it (measured over the
 * whole shipped document — 0 mismatches). What survives of 32's claim is narrower and was verified:
 * `Knuckles\Camel\Extraction\Metadata` has no custom bag, so an extraction strategy could only ever have
 * carried a TITLE forward — which is the coupling being removed.
 */
class OperationIdGenerator extends OpenApiGenerator
{
    /** @var array<string, list<Route>>|null */
    protected ?array $routes = null;

    public function pathItem(array $pathItem, array $groupedEndpoints, OutputEndpointData $endpoint): array
    {
        $pathItem['operationId'] = $this->operationId($endpoint);

        return $pathItem;
    }

    /** Rung (E) when the mount declared one, rung (B) otherwise. */
    public function operationId(OutputEndpointData $endpoint): string
    {
        $verb = (string) ($endpoint->httpMethods[0] ?? 'GET');
        $uri = $endpoint->uri;

        return $this->declared($verb, $uri) ?? static::wireShape($verb, $uri);
    }

    /**
     * The id the mount wrote onto the route, or null when it wrote none — including when the wire key
     * resolves to no route or to SEVERAL (a double registration is ambiguous, so it declares nothing and
     * falls to (B); the host's totality guard is what turns that into a red test).
     */
    protected function declared(string $verb, string $uri): ?string
    {
        if ($this->routes === null) {
            $this->routes = static::routesByWireKey();
        }

        $matches = $this->routes[static::wireKey($verb, $uri)] ?? [];

        return count($matches) === 1 ? BeamRouteAction::operationId($matches[0]) : null;
    }

    /**
     * Verb + the full uri, every non-alphanumeric run a word boundary. `[^A-Za-z0-9]` rather than `\W`
     * because `\w` keeps `_`, and an id is promised to hold nothing outside `[A-Za-z0-9]`.
     */
    public static function wireShape(string $verb, string $uri): string
    {
        $parts = preg_split('/[^A-Za-z0-9]+/', $uri, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return Str::camel(strtolower($verb).' '.implode(' ', $parts));
    }

    /**
     * `verb uri` → every route registered at it. A LIST rather than one route because the key is only
     * *almost* unique: `GET api/v1/search` is registered twice in this estate.
     *
     * @return array<string, list<Route>>
     */
    public static function routesByWireKey(): array
    {
        $byKey = [];

        foreach (RouteFacade::getRoutes() as $route) {
            foreach ($route->methods() as $verb) {
                $byKey[static::wireKey($verb, $route->uri())][] = $route;
            }
        }

        return $byKey;
    }

    /**
     * The lookup key both sides normalise to: lowercased verb, no leading slash, and the optional-parameter
     * marker dropped — Scribe writes `{param?}` as `{param}` into the document's paths, and the router
     * keeps the `?`.
     */
    public static function wireKey(string $verb, string $uri): string
    {
        return strtolower($verb).' '.str_replace('?}', '}', ltrim($uri, '/'));
    }
}
