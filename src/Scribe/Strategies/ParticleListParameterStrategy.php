<?php

namespace Splicewire\Beam\Scribe\Strategies;

use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Extracting\Strategies\Strategy;
use ReflectionClass;
use Rushing\DataFilters\Facades\DataFilter;
use Rushing\DataFilters\Keywords;
use Rushing\DataFilters\Query\ResourceQuery;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

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

        // The list contract belongs to the list. show/store/update/destroy take none of it.
        if ($endpointData->method?->getName() !== 'index') {
            return [];
        }

        $resource = app(ParticleResourceRegistry::class)->get($key);

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
     * The declared facets, indexed by the name they answer to ON THE WIRE — `x-filter.name` /
     * `x-sort.name` — rather than by property name.
     *
     * @param  class-string  $dataClass
     * @return array{filters: array<string, array<string, mixed>>, sorts: array<string, array<string, mixed>>}
     */
    protected function facets(string $dataClass): array
    {
        $generator = new JsonSchemaGenerator([
            'strategies' => config('data-schemas.strategies'),
        ]);

        $schema = $generator->generate(new ReflectionClass($dataClass));

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
