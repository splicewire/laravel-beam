<?php

namespace Splicewire\Beam\Filters;

use Illuminate\Auth\Access\AuthorizationException;
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
use Splicewire\Beam\Authorization\ResourceReadGuard;
use Splicewire\Beam\Authorization\ResourceVisibility;
use Splicewire\Beam\Particle\Backing\BackingResolver;
use Splicewire\Beam\Particle\Backing\DeclaresFilterVocabulary;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** The declared filter runtime used by Frame's capability provider and particle query selection. */
class ResourceFilters
{
    public function __construct(private ParticleResourceRegistry $particles) {}

    public function authorize(string $key): void
    {
        $realm = request()->route('realm');
        if (is_string($realm) && $realm !== '') {
            abort_unless(in_array($realm, $this->particles->realmsFor($key), true), 404);
        }
        $definition = app()->bound(ResourceRegistry::class) ? app(ResourceRegistry::class)->find($key) : null;
        abort_if($definition === null, 404);
        abort_unless(app(ResourceAccessGate::class)->allowsResource($definition), 403);
        $particle = $this->particles->find($key);
        if ($particle !== null) {
            abort_unless(app(ResourceVisibility::class)->readable($particle, request()->user()), 403);
            $this->authorizeModel($particle->modelClass(), $particle);
        }
        $this->authorizeModel(DataFilter::registry()->find($key)?->model, $particle);
    }

    /** A declared particle's list variants retain their access checks without exposing Frame metadata. */
    public function authorizeParticle(ParticleResource $particle): void
    {
        $realm = request()->route('realm');
        if (is_string($realm) && $realm !== '') {
            abort_unless(in_array($realm, $this->particles->realmsFor($particle->key), true), 404);
        }
        abort_unless(app(ResourceAccessGate::class)->allowsResource($particle->toResourceDefinition()), 403);
        abort_unless(app(ResourceVisibility::class)->readable($particle, request()->user()), 403);
        $this->authorizeModel($particle->modelClass(), $particle);
    }

    public function definition(string $key): FilterDefinition
    {
        $this->authorize($key);
        $definition = app(ResourceFilterDefinition::class)->definition($key);
        abort_if($definition === null, 404, "No filter resource registered for [{$key}].");

        $this->authorizeModel($definition->model, $this->particles->find($key));

        return $definition;
    }

    public function schema(string $key, ?string $variant = null): FilterSchemaResponseData
    {
        $this->authorize($key);
        if ($variant !== null) {
            $this->definition($key);
            $selected = app(FilterQuerySelection::class)->definition($key, $variant);

            return new FilterSchemaResponseData($this->schemaFor($selected), $this->savedResource($key));
        }
        $definition = app(ResourceFilterDefinition::class)->definition($key);
        $particle = $this->particles->find($key);
        if ($particle !== null && (new BackingResolver)->hasCapability($particle->backing, DeclaresFilterVocabulary::class)) {
            return new FilterSchemaResponseData($particle->backing()->filterVocabulary()->toSchema(), $this->savedResource($key));
        }
        if ($definition !== null) {
            $this->authorizeModel($definition->model, $this->particles->find($key));

            return new FilterSchemaResponseData($this->schemaFor($definition), $this->savedResource($key));
        }

        return new FilterSchemaResponseData(['type' => 'object', 'properties' => (object) []]);
    }

    public function options(string $key, string $ref, ?string $search = null): FilterOptionsResponseData
    {
        $schema = $this->schema($key)->data;
        $schemas = [$schema];
        $particle = $this->particles->find($key);
        $declares = $particle !== null && (new BackingResolver)->hasCapability($particle->backing, DeclaresFilterVocabulary::class);
        $definition = DataFilter::registry()->find($key);
        if (! $declares && $definition !== null) {
            foreach ($this->availableVariants($key, $definition) as $candidate) {
                if ($candidate->key !== $key) {
                    $schemas[] = $this->schemaFor($candidate);
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

    public function variants(string $key): FilterVariantsResponseData
    {
        $this->authorize($key);
        $definition = app(ResourceFilterDefinition::class)->definition($key);
        if ($definition === null) {
            return new FilterVariantsResponseData(new FilterVariantsData($key, []));
        }
        $this->authorizeModel($definition->model, $this->particles->find($key));
        $variants = [];
        foreach ($this->availableVariants($key, $definition) as $candidate) {
            $variant = $candidate->key;
            $variants[] = new FilterVariantData((string) $variant, $definition->resource, $variant === $definition->resource, $candidate->data === $definition->data);
        }

        return new FilterVariantsResponseData(new FilterVariantsData($definition->resource, $variants));
    }

    /** @return list<FilterDefinition> */
    private function availableVariants(string $key, FilterDefinition $definition): array
    {
        $available = [];
        foreach (ResourceFilterConstraints::variants($definition->resource)->values() as $variant) {
            try {
                $available[] = app(FilterQuerySelection::class)->definition($key, $variant);
            } catch (AuthorizationException $e) {
                if (! in_array($e->status() ?? 403, [403, 404], true)) {
                    throw $e;
                }
            } catch (HttpExceptionInterface $e) {
                if (! in_array($e->getStatusCode(), [403, 404], true)) {
                    throw $e;
                }
            }
        }

        return $available;
    }

    public function supportsSavedFilters(string $key): bool
    {
        $definition = app(ResourceFilterDefinition::class)->definition($key);

        $particle = $this->particles->find($key);
        if ($particle !== null && (new BackingResolver)->hasCapability($particle->backing, DeclaresFilterVocabulary::class)) {
            return ! $particle->backing()->filterVocabulary()->isEmpty();
        }

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

    /** Metadata uses the same declared row boundary as reads, without requiring class-wide access. */
    public function authorizeModel(?string $model, ?ParticleResource $resource): void
    {
        if ($model === null || ($policy = Gate::getPolicyFor($model)) === null || ! method_exists($policy, 'viewAny')) {
            return;
        }

        $permission = Gate::inspect('viewAny', $model);
        if ($permission->allowed() || ($resource !== null && $resource->modelClass() === $model
            && app(ResourceReadGuard::class)->scoped($resource, request()) === true)) {
            return;
        }

        $permission->authorize();
    }
}
