<?php

namespace Splicewire\Beam\Filters;

use Illuminate\Support\Facades\Gate;
use Rushing\DataFilters\Facades\DataFilter;
use Rushing\DataFilters\Query\ResourceQuery;
use Rushing\DataFilters\Registry\ResourceDefinition as FilterDefinition;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Schemastud\Frame\Contracts\ResourceAccessGate;
use Schemastud\Frame\Contracts\ResourceRegistry;
use Schemastud\Frame\Data\FilterOptionData;
use Schemastud\Frame\Data\FilterOptionsResponseData;
use Schemastud\Frame\Data\FilterSchemaResponseData;
use Schemastud\Frame\Data\FilterVariantData;
use Schemastud\Frame\Data\FilterVariantsData;
use Schemastud\Frame\Data\FilterVariantsResponseData;
use Schemastud\Frame\Registry\NavMetadata;
use Schemastud\Frame\Registry\ResourceDefinition;
use Splicewire\Beam\Authorization\ResourceVisibility;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Particle\Backing\BackingResolver;
use Splicewire\Beam\Particle\Backing\DeclaresFilterVocabulary;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/** The filter runtime shared by Frame's capability port and retained particle mounts. */
class ResourceFilters
{
    public function __construct(private ParticleResourceRegistry $particles) {}

    public function authorize(string $key, bool $legacy = false): void
    {
        $particle = $this->particles->find($key);
        $realm = request()->route('realm');
        if (is_string($realm) && $realm !== '') {
            abort_unless(in_array($realm, $this->particles->realmsFor($key), true), 404);
        }

        $definition = app()->bound(ResourceRegistry::class) ? app(ResourceRegistry::class)->find($key) : null;
        if ($definition === null && $legacy) {
            $filter = DataFilter::registry()->find($key);
            abort_if($particle === null && $filter === null, 404);
            $definition = new ResourceDefinition(
                key: $key, model: $particle?->modelClass() ?? $filter?->model,
                data: $particle?->data ?? $filter?->data ?? BeamData::class,
                creatable: false, query: null, editData: null, policy: $particle?->policy,
                form: 'bare', nav: new NavMetadata(label: ''),
            );
        }
        abort_if($definition === null, 404);
        abort_unless(app(ResourceAccessGate::class)->allowsResource($definition), 403);
        if ($particle !== null) {
            abort_unless(app(ResourceVisibility::class)->readable($particle, request()->user()), 403);
        }
        $this->authorizeModel($particle?->modelClass());
        $this->authorizeModel(DataFilter::registry()->find($key)?->model);
    }

    public function definition(string $key, bool $legacy = false): FilterDefinition
    {
        $this->authorize($key, $legacy);
        $definition = DataFilter::tryResource($key);
        abort_if($definition === null, 404, "No filter resource registered for [{$key}].");

        $this->authorizeModel($definition->model);

        return $definition;
    }

    public function schema(string $key, ?string $variant = null, bool $legacy = false): FilterSchemaResponseData
    {
        $this->authorize($key, $legacy);
        if ($variant !== null) {
            $definition = $this->definition($key, $legacy);
            abort_unless(ResourceFilterConstraints::variants($definition->resource)->contains($variant), 404);

            return new FilterSchemaResponseData($this->schemaFor(DataFilter::resource($variant)), $this->savedResource($key));
        }
        $particle = $this->particles->find($key);
        if ($particle !== null && (new BackingResolver)->hasCapability($particle->backing, DeclaresFilterVocabulary::class)) {
            return new FilterSchemaResponseData($particle->backing()->filterVocabulary()->toSchema(), $this->savedResource($key));
        }
        $definition = DataFilter::tryResource($key);
        if ($definition !== null) {
            $this->authorizeModel($definition->model);

            return new FilterSchemaResponseData($this->schemaFor($definition), $this->savedResource($key));
        }
        abort_if($particle === null || $particle->filterable, 404, "No filter resource registered for [{$key}].");

        return new FilterSchemaResponseData(['type' => 'object', 'properties' => (object) []]);
    }

    public function options(string $key, string $ref, ?string $search = null, bool $legacy = false): FilterOptionsResponseData
    {
        $schema = $this->schema($key, legacy: $legacy)->data;
        $schemas = [$schema];
        $particle = $this->particles->find($key);
        $declares = $particle !== null && (new BackingResolver)->hasCapability($particle->backing, DeclaresFilterVocabulary::class);
        $definition = DataFilter::registry()->find($key);
        if (! $declares && $definition !== null) {
            foreach (ResourceFilterConstraints::variants($definition->resource)->values() as $variant) {
                if ($variant !== $key) {
                    $schemas[] = $this->schemaFor(DataFilter::resource($variant));
                }
            }
        }
        $refs = [];
        array_walk_recursive($schemas, function ($value, $name) use (&$refs): void {
            if ($name === 'optionsRef' && is_string($value)) {
                $refs[] = $value;
            }
        });
        abort_unless(in_array($ref, $refs, true) && ResourceFilterConstraints::options()->contains($ref), 404);

        return new FilterOptionsResponseData(array_map(
            fn (array $option) => new FilterOptionData(value: (string) $option['value'], label: (string) $option['label']),
            DataFilter::resolveOptions($ref, $search),
        ));
    }

    public function variants(string $key, bool $legacy = false): FilterVariantsResponseData
    {
        $definition = $this->definition($key, $legacy);
        $variants = [];
        foreach (ResourceFilterConstraints::variants($definition->resource)->values() as $variant) {
            $candidate = DataFilter::resource($variant);
            $variants[] = new FilterVariantData((string) $variant, $definition->resource, $variant === $definition->resource, $candidate->data === $definition->data);
        }

        return new FilterVariantsResponseData(new FilterVariantsData($definition->resource, $variants));
    }

    public function supportsSavedFilters(string $key): bool
    {
        $definition = DataFilter::registry()->find($key);

        return $definition !== null && is_a($definition->query, ResourceQuery::class, true);
    }

    private function savedResource(string $key): ?string
    {
        return $this->supportsSavedFilters($key) ? 'saved-filters' : null;
    }

    private function schemaFor(FilterDefinition $definition): array
    {
        return (new JsonSchemaGenerator(['strategies' => config('data-schemas.strategies')]))->generate(new \ReflectionClass($definition->data));
    }

    private function authorizeModel(?string $model): void
    {
        if ($model !== null && ($policy = Gate::getPolicyFor($model)) !== null && method_exists($policy, 'viewAny')) {
            Gate::authorize('viewAny', $model);
        }
    }
}
