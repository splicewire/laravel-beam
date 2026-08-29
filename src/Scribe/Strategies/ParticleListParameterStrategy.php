<?php

namespace Splicewire\Beam\Scribe\Strategies;

use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Extracting\Strategies\Strategy;
use ReflectionClass;
use Rushing\DataFilters\Facades\DataFilter;
use Rushing\DataFilters\Keywords;
use Rushing\DataFilters\Query\ResourceQuery;
use Schemastud\DataSchemas\Generators\Generator;
use Splicewire\Beam\Discovery\SubSurface;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Routing\BeamRouteAction;
use Splicewire\Beam\Routing\BeamRouteProxy;
use Splicewire\Beam\Routing\RouteReturnType;

/**
 * Document a DISSOLVED particle index's full list contract from the declarations the route already carries.
 *
 * The sibling of {@see ParticleRequestStrategy} one axis over: the schema signal is the ROUTE (its
 * `_particle` default names a {@see ParticleResource}), and {@see ParticleResource::$key} IS the
 * data-filters resource key, so this needs ZERO new declaration — no config lookup, no per-resource
 * registration.
 *
 * Two sources, deliberately, because neither is sufficient alone (api-surface-coherence ticket 07 §2):
 *
 *  - **The SET** comes from {@see ResourceQuery::filterNames()} and friends,
 *    which merge the attribute-declared facets with the imperative `extraFilters()`/`extraSorts()`/
 *    `extraIncludes()` escape hatches. Deriving the set from the schema instead would silently DROP every
 *    closure filter — relocating the exact defect this strategy exists to close.
 *  - **The TYPES** come from the generated Filter-Data schema (the same generator and
 *    `config('data-schemas.strategies')` wiring `filter-schema/{resource}` serves at runtime), joined to
 *    the set by facet NAME — not property name, since `#[Filterable(name: …)]` routinely renames a facet.
 *    A name with no backing property documents as UNTYPED; it is never omitted.
 *
 * Four axes, one strategy: `filter[…]`, `sort`, `include`, and pagination. They all ride the same
 * spatie/laravel-query-builder grammar off the same resource key, so splitting them would be four
 * resolutions of one declaration for no gained knob.
 *
 * Every name here is DERIVED, never spelled: the `filter`/`sort`/`include` prefixes come from
 * `config('query-builder.parameters')` and pagination from {@see ParticleController::PAGE}/`PER_PAGE`,
 * so the camelCase cutover (ticket 22) carries the reference with it and nothing in this file changes.
 *
 * Returns `null` (defer) for any non-particle route, so it composes transparently alongside Scribe's
 * stock query-parameter strategies.
 */
class ParticleListParameterStrategy extends Strategy
{
    public function __invoke(ExtractedEndpointData $endpointData, array $settings = []): ?array
    {
        $key = $endpointData->route?->defaults[ParticleController::RESOURCE] ?? null;

        if ($key === null) {
            return null; // Not a particle route — defer to the other strategies.
        }

        // The list contract belongs to the resource's own collection READ. show/store/update/destroy
        // take none of it, and neither do the sub-surfaces mounted beside the resource.
        if (! $this->isCollectionRead($endpointData)) {
            return [];
        }

        // ASK, don't demand (api-surface-coherence 102). `inResource()` stamps this same route default on
        // a HAND-ROLLED exposure, and its argument is a *data-filters* resource key that need not also be
        // a `#[ParticleResource]` — `guest-links` and `releases` at the flagship are both, deliberately.
        // Demanding here turned that legitimate mount into a per-route `RuntimeException`; Scribe catches
        // per-route, prints only under `-v` and exits WARN, so 30 live endpoints were absent from
        // `openapi.yaml`, the SDK and the docs surface with nothing on screen but a mild warning.
        $resource = app(ParticleResourceRegistry::class)->find($key);

        if ($resource === null) {
            // No particle declaration, but the key may still be a data-filters resource — which is the
            // whole point of `inResource($key, filters: true)`. Document the query contract that IS
            // declared and omit pagination: a hand-rolled index chooses its own paging (the flagship's
            // `ReleaseController::index` does a bare `->get()`), so `perPage` would be an invention.
            // The absence itself is reported by `ParticleRouteResourceAudit`, not swallowed.
            return $this->fromFilterRegistry($key);
        }

        // A `filterable: false` resource has no data-filters query at all — its index is an unfiltered,
        // default-sorted list, so there is nothing but pagination to document.
        if (! $resource->filterable) {
            return $this->pagination($resource);
        }

        // Declared `filterable: true` but absent from the data-filters registry: a real inconsistency,
        // but one the runtime index will raise on its own. Documenting pagination alone is honest here.
        //
        // The key comes from a `#[ParticleResource]` declaration and is checked against a DIFFERENT
        // registry, so from here it is outside input and the lookup is the nullable half of the miss
        // pair (registry-kernel ticket 61). This was a `catch (InvalidArgumentException)` until
        // `data-filters.resources` conformed to the popcorn kernel (ticket 38) and its miss became a
        // `RegistryMiss` — a `RuntimeException`, so the catch stopped catching and the degrade-to-
        // pagination path became a fatal doc-generation error. Same cause as tower's
        // `FilterSchemaController`, found by the same audit.
        $definition = DataFilter::tryResource($resource->key);

        if ($definition === null) {
            return $this->pagination($resource);
        }

        $query = DataFilter::query($resource->key);
        $facets = $this->facets($definition->data);

        return [
            ...$this->filters($query->filterNames(), $facets['filters']),
            ...$this->sorts($query->sortNames()),
            ...$this->includes($query->includeNames()),
            ...$this->pagination($resource),
        ];
    }

    /**
     * Is this route the resource's own collection read — the one exposure the list contract describes?
     *
     * It used to be `$endpointData->method?->getName() === 'index'`, and that proxy was wrong in BOTH
     * directions (api-surface-coherence 103), measured at the flagship against the booted router:
     *
     *  - **Under-triggered by 1.** `GET /api/v1/guest-tokens` is served by `GuestTokenController@indexAll`
     *    — a second collection action on one controller — so the resource's declared filter/sort contract
     *    was absent from the spec while its circuit-scoped twin published three parameters. Same resource,
     *    same data-filters key, different documentation.
     *  - **Over-triggered by 96.** Every SUB-SURFACE mounted beside a resource carries the same
     *    `_particle` stamp and is served by a controller whose method is *also* called `index`:
     *    `ResourceDiscoveryController` (38 routes), `HookEventCatalogController` (32) and
     *    `ResourceFiltersController` (26). The saved-filter LISTING at `GET /{resource}/filters` was
     *    therefore documented as accepting `filter[…]`, `sort`, `include`, `page` and `perPage` off the
     *    resource it lists saved filters FOR — none of which it reads. That half was invisible, because a
     *    contract published where none is served produces no error anywhere.
     *
     * So the gate is two questions, not one. **Which surface** is a stamped fact ({@see SubSurface::of()},
     * ticket 105) read off the route's own defaults — the sub-surfaces classify themselves at mount time,
     * so this is a lookup rather than a parse. **Which action** is asked declaration-first:
     *
     *  1. `->beam()->returns(X::class, many: true)` — an explicitly declared cardinality
     *     ({@see BeamRouteAction::returnsMany()}). 3 routes at the flagship.
     *  2. {@see BeamRouteProxy::FILTERS_PROMISE} — `->beam()->inResource($key, filters: true)`, whose own
     *     docblock defines the flag as *"this route is the resource's INDEX at this exposure"*. 12 routes,
     *     and the one that lets `indexAll` in.
     *  3. …and, where neither is declared, the method name — kept, named, and CONFINED to the CRUD
     *     surface, where a method called `index` genuinely means the resource's index.
     *
     * ⚠️ **Rung 3 is a convention, not a declaration, and three flagship routes rest on it alone:**
     * `GET api/v1/silos`, `GET api/v1/agents`, `GET api/v1/circuits` — hand-mounted indexes that declare
     * neither cardinality nor a filter promise. Dropping the rung would silently strip a filter contract
     * those three genuinely serve, so the honest move is to report the declaration gap rather than take
     * the documentation away: each of them wants one word (`filters: true`, or a `many: true` return) at
     * its mount, after which this rung can go. It is scoped rather than removed for the same reason
     * {@see RouteReturnType} keeps its own name-shaped fallback — a missing declaration must degrade,
     * not break.
     */
    protected function isCollectionRead(ExtractedEndpointData $endpointData): bool
    {
        $route = $endpointData->route;

        if ($route === null || SubSurface::of($route) !== SubSurface::CRUD) {
            return false;
        }

        return BeamRouteAction::returnsMany($route)
            || isset($route->defaults[BeamRouteProxy::FILTERS_PROMISE])
            || $endpointData->method?->getName() === 'index';
    }

    /**
     * The filter/sort/include contract for a route stamped `inResource()` with a key that has a
     * data-filters declaration but no `#[ParticleResource]` (api-surface-coherence 102).
     *
     * Deliberately the same three axes minus pagination — see the caller. A key registered in NEITHER
     * registry documents as a plain endpoint with no query contract, which is the honest reading of a
     * stamp that resolves to nothing.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function fromFilterRegistry(string $key): array
    {
        $definition = DataFilter::tryResource($key);

        if ($definition === null) {
            return [];
        }

        $query = DataFilter::query($key);
        $facets = $this->facets($definition->data);

        return [
            ...$this->filters($query->filterNames(), $facets['filters']),
            ...$this->sorts($query->sortNames()),
            ...$this->includes($query->includeNames()),
        ];
    }

    /**
     * The declared facets, indexed by the name they answer to ON THE WIRE — `x-filter.name` /
     * `x-sort.name` — rather than by property name.
     *
     * @param  class-string  $dataClass
     * @return array{filters: array<string, array<string, mixed>>, sorts: array<string, array<string, mixed>>}
     */
    protected function facets(string $dataClass): array
    {
        // Container-resolved, and this site changed in TWO ways rather than one.
        //
        // Dispatch: `data-schemas.generators` is a LIST and the rule "the first member whose
        // `canGenerate()` accepts this class" lives only inside `ChainedGenerator`, so hand-building
        // the default member ran the PLAIN generator over a class a narrow member owns at
        // `~/Herd/thingsontv` — a downgraded facet set behind a successful extraction.
        //
        // Config: the hand-build passed `strategies` and NOTHING ELSE, which withheld `base_uri`
        // from every class reaching it. That is the exact `a6989da` shape — a `data:` class
        // implementing `SchemaIdentity` threw `MissingSchemaBaseUri` here, Scribe caught it
        // per-route and printed only under `-v`, and the index endpoint left the spec. This file
        // already carries one 30-endpoint scar from that mechanism (see `__invoke`); the narrowed
        // config was a second one waiting.
        //
        // Widening the config is safe for what this method reads. Backed enums — the only facet type
        // whose `$defs` entry `dereference()` needs — always hoist as `#/$defs/<Short>`
        // (`ensureEnumDef()` never consults `base_uri`), so enum accepted-value lists are unchanged.
        // A nested SchemaIdentity OBJECT now hoists under its absolute `$id` instead, which
        // `dereference()` declines to follow — and that degrades correctly, because the property's
        // own keywords win there anyway and `x-filter`/`x-sort` live on the property, not the `$defs`
        // entry.
        //
        // GUARDED: the chain throws where the hand-built generator generated regardless, and a throw
        // here is silent amputation, not a loud failure. A refused class yields no facets, so the
        // filter/sort names still publish from the data-filters query — untyped and undescribed,
        // which is what this method's own `$facets[$name] ?? null` fallback already handles.
        $reflection = new ReflectionClass($dataClass);
        $generator = app(Generator::class);

        if (! $generator->canGenerate($reflection)) {
            return ['filters' => [], 'sorts' => []];
        }

        $schema = $generator->generate($reflection);

        $filters = [];
        $sorts = [];

        foreach ($schema['properties'] ?? [] as $property) {
            $property = $this->dereference($property, $schema);

            if ($name = $property[Keywords::Filter]['name'] ?? null) {
                $filters[$name] = $property;
            }

            if ($name = $property[Keywords::Sort]['name'] ?? null) {
                $sorts[$name] = $property;
            }
        }

        return ['filters' => $filters, 'sorts' => $sorts];
    }

    /**
     * Fold a local `$ref` back into the property.
     *
     * A backed-enum facet emits `{$ref: #/$defs/Status}` and carries NO `type` of its own — so a reader
     * that only looks at the property documents it as an untyped string and drops the one thing an enum
     * facet is worth documenting for: its finite set of accepted values.
     *
     * @param  array<string, mixed>  $property
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    protected function dereference(array $property, array $schema): array
    {
        $ref = $property['$ref'] ?? null;

        if (! is_string($ref) || ! str_starts_with($ref, '#/$defs/')) {
            return $property;
        }

        $definition = $schema['$defs'][substr($ref, strlen('#/$defs/'))] ?? null;

        // The property's own keywords win: `$defs` describes the TYPE, the property describes this USE
        // of it (which is where `x-filter` and any per-property prose live).
        return is_array($definition) ? [...$definition, ...$property] : $property;
    }

    /**
     * @param  list<string>  $names
     * @param  array<string, array<string, mixed>>  $facets
     * @return array<string, array<string, mixed>>
     */
    protected function filters(array $names, array $facets): array
    {
        $prefix = config('query-builder.parameters.filter', 'filter');
        $parameters = [];

        foreach ($names as $name) {
            $property = $facets[$name] ?? null;
            $keyword = $property[Keywords::Filter] ?? [];

            $parameters["{$prefix}[{$name}]"] = [
                'type' => $this->scribeType($property),
                'description' => $this->filterDescription($name, $property, $keyword),
                'required' => false,
                'enumValues' => $this->enumValues($property),
                // No example on purpose: an optional parameter with a null example is documented but left
                // out of the rendered example request, which is what keeps a 20-facet resource readable.
                'example' => null,
            ];
        }

        return $parameters;
    }

    /**
     * @param  list<string>  $names
     * @return array<string, array<string, mixed>>
     */
    protected function sorts(array $names): array
    {
        if ($names === []) {
            return [];
        }

        $sort = config('query-builder.parameters.sort', 'sort');

        return [$sort => [
            'type' => 'string',
            'description' => 'Sort the result set by one of '.$this->code($names)
                .'. Prefix with `-` for descending (e.g. `-'.$names[0].'`). Comma-separate to sort by several.',
            'required' => false,
            'example' => null,
        ]];
    }

    /**
     * @param  list<string>  $names
     * @return array<string, array<string, mixed>>
     */
    protected function includes(array $names): array
    {
        if ($names === []) {
            return [];
        }

        $include = config('query-builder.parameters.include', 'include');

        return [$include => [
            'type' => 'string',
            'description' => 'Comma-separated related resources to load alongside each record. One or more of '
                .$this->code($names).'.',
            'required' => false,
            'example' => null,
        ]];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function pagination(ParticleResource $resource): array
    {
        return [
            ParticleController::PAGE => [
                'type' => 'integer',
                'description' => 'The page of results to return.',
                'required' => false,
                'example' => 1,
            ],
            ParticleController::PER_PAGE => [
                'type' => 'integer',
                'description' => "Records per page. Defaults to {$resource->perPage}.",
                'required' => false,
                'example' => $resource->perPage,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $property
     * @param  array<string, mixed>  $keyword
     */
    protected function filterDescription(string $name, ?array $property, array $keyword): string
    {
        if ($property === null) {
            // An escape-hatch filter: real, accepted, and invisible to the schema because it has no backing
            // property. Documented untyped rather than dropped.
            return "Filter by `{$name}`.";
        }

        $description = $property['description'] ?? "Filter by `{$name}`.";

        if ($operator = $keyword['operator'] ?? null) {
            $description .= " Matched with the `{$operator}` operator.";
        }

        return $description;
    }

    /**
     * The JSON-Schema type of the backing property, mapped onto Scribe's vocabulary. A declared `array`
     * facet documents as a **string**: spatie/laravel-query-builder splits the value on its configured
     * delimiter, so `filter[tags]=a,b` is what actually goes over the wire.
     *
     * @param  array<string, mixed>|null  $property
     */
    protected function scribeType(?array $property): string
    {
        $declared = array_values(array_filter(
            (array) ($property['type'] ?? []),
            fn ($type) => $type !== 'null',
        ));

        return match ($declared[0] ?? null) {
            'integer' => 'integer',
            'number' => 'number',
            'boolean' => 'boolean',
            default => 'string',
        };
    }

    /**
     * The finite domain a facet accepts, read off the (dereferenced) property schema. Absent for
     * relational facets, whose domain is a table rather than an enumeration.
     *
     * @param  array<string, mixed>|null  $property
     * @return list<mixed>
     */
    protected function enumValues(?array $property): array
    {
        return array_values((array) ($property['enum'] ?? []));
    }

    /**
     * @param  list<string>  $names
     */
    protected function code(array $names): string
    {
        return implode(', ', array_map(fn (string $name) => "`{$name}`", $names));
    }
}
