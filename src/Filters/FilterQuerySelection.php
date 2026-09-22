<?php

namespace Splicewire\Beam\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Rushing\DataFilters\Facades\DataFilter;
use Rushing\DataFilters\Query\ResourceQuery;
use Rushing\DataFilters\Registry\ResourceDefinition;
use Rushing\DataFilters\Registry\UnresolvableResourceModel;
use Rushing\DataFilters\SavedFilters\SavedFilterValidator;
use Schemastud\Frame\Contracts\ResourceAccessGate;
use Schemastud\Frame\Contracts\ResourceRegistry;
use Schemastud\Frame\Data\ResourceQueryData;
use Splicewire\Beam\Particle\Backing\DeclaresFilterVocabulary;
use Splicewire\Beam\Particle\ParticleListQuery;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/** Select one resource vocabulary for schema, saved validation and actual record reads. */
class FilterQuerySelection
{
    public function __construct(private ParticleResourceRegistry $particles) {}

    public function definition(string $target, ?string $variant = null): ResourceDefinition
    {
        $canonical = app(ResourceFilterDefinition::class)->definition($target);
        abort_if($canonical === null, 404);
        if ($variant === null || $variant === $target) {
            return $canonical;
        }

        try {
            $selected = DataFilter::tryResource($variant);
        } catch (UnresolvableResourceModel) {
            abort(404);
        }
        abort_unless($selected !== null && is_a($selected->query, ResourceQuery::class, true)
            && $selected->resource === $canonical->resource
            && $selected->model !== null && $selected->model === $canonical->model, 404);
        $this->authorizeCandidate($target, $canonical);
        $this->authorizeCandidate($variant, $selected);

        return $selected;
    }

    /** Apply the selected declaration without replacing the backing or parent row boundary. */
    public function applyTo(string $target, Builder $base, Request $request): Builder
    {
        $input = ResourceQueryData::validateAndCreate($request->query());
        $variant = is_string($input->filterVariant) ? $input->filterVariant : null;
        $resolver = app(ResourceFilterDefinition::class);
        $canonical = $resolver->definition($target);
        if ($canonical === null) {
            abort_if($variant !== null, 404);

            return $base;
        }
        $selected = $this->definition($target, $variant);
        $scopeRequest = self::scopeRequest($request);
        $composer = app(ParticleListQuery::class);
        $composer->intersect($base, $resolver->query($canonical)->authorizationQuery($scopeRequest));
        if ($selected->key !== $canonical->key) {
            $composer->intersect($base, $resolver->query($selected)->authorizationQuery($scopeRequest));
        }

        return $resolver->query($selected)->applyTo($base, $request)->getEloquentBuilder();
    }

    public static function scopeRequest(Request $request): Request
    {
        $scopeRequest = clone $request;
        $scopeRequest->query->replace($request->query->all());
        foreach (['filter', 'sort', 'include', 'filterVariant', 'page', 'perPage', 'per_page', 'limit', 'cursor', 'savedFilter', 'saved_filter'] as $key) {
            $scopeRequest->query->remove($key);
        }

        return $scopeRequest;
    }

    /** @param array<string, mixed> $parameters */
    public function validate(string $target, array $parameters, bool $legacy = false): array
    {
        app(ResourceFilters::class)->authorize($target, $legacy);
        Validator::make(['query_parameters' => $parameters], [
            'query_parameters' => ['array:filter,sort,include,limit,filterVariant'],
            'query_parameters.filter' => ['sometimes', 'array'],
            'query_parameters.filterVariant' => ['sometimes', 'nullable', 'string'],
            'query_parameters.sort' => ['sometimes', function ($attribute, $value, $fail) {
                if (! is_string($value) && ! is_array($value)) {
                    $fail('Sort must be a string or list.');
                }
            }],
            'query_parameters.include' => ['sometimes', function ($attribute, $value, $fail) {
                if (! is_string($value) && ! is_array($value)) {
                    $fail('Include must be a string or list.');
                }
            }],
            'query_parameters.sort.*' => ['string'],
            'query_parameters.include.*' => ['string'],
        ])->validate();
        $definition = app(ResourceFilterDefinition::class)->definition($target);
        if ($definition === null) {
            $backing = $this->particles->find($target)?->backing();
            abort_unless($backing instanceof DeclaresFilterVocabulary, 404);
            $vocabulary = $backing->filterVocabulary();
            abort_if($vocabulary->isEmpty() || ($parameters['filterVariant'] ?? null) !== null, 404);

            return app(SavedFilterValidator::class)->validateVocabulary(
                $target, $parameters, $vocabulary->filterNames(), $vocabulary->sortNames(),
            );
        }
        $selected = $this->definition($target, $parameters['filterVariant'] ?? null);

        return app(SavedFilterValidator::class)->validate($selected->key, $parameters);
    }

    private function authorizeCandidate(string $key, ResourceDefinition $filter): void
    {
        $particle = $this->particles->find($key);
        if ($particle !== null) {
            // Non-Frame particle variants still carry their own realm and policy declarations.
            app(ResourceFilters::class)->authorize($key, legacy: true);
        }
        $frame = app()->bound(ResourceRegistry::class) ? app(ResourceRegistry::class)->find($key) : null;
        if ($frame !== null) {
            abort_unless(app(ResourceAccessGate::class)->allowsResource($frame), 403);
        }
        $model = $filter->requireModel();
        if (($policy = Gate::getPolicyFor($model)) !== null && method_exists($policy, 'viewAny')) {
            Gate::authorize('viewAny', $model);
        }
    }
}
