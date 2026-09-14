<?php

namespace Splicewire\Beam\Filters;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Rushing\DataFilters\SavedFilters\SavedFilter;
use Rushing\DataFilters\SavedFilters\Visibility;
use Schemastud\Frame\Contracts\ResourceFilterValidator;
use Schemastud\Frame\Contracts\ResourceRegistry;
use Schemastud\Frame\Data\ResourceQueryData;
use Schemastud\Frame\Filters\ResourceFilters as FrameResourceFilters;
use Splicewire\Beam\Filters\Data\SavedFilterEditData;
use Splicewire\Beam\Filters\Data\SavedFilterInputData;
use Splicewire\Beam\Filters\Data\SavedFilterStoreInputData;
use Splicewire\Beam\Filters\Data\SavedFilterUpdateInputData;
use Splicewire\Beam\Write\ModelAttributeMapper;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** One persistence and authorization path for both resource CRUD and legacy filter mounts. */
class SavedFilterService
{
    public function __construct(private ResourceFilters $filters) {}

    public function visible(string $target, bool $legacy = false): Builder
    {
        $this->filters->authorize($target, $legacy);
        $query = ResourceQueryData::validateAndCreate(request()->query());
        $this->authorizeTarget($target, $legacy, is_string($query->filterVariant) ? $query->filterVariant : null);

        return $this->visibleQuery($target);
    }

    private function visibleQuery(string $target): Builder
    {
        $user = request()->user();
        abort_if($user === null, 403);

        return SavedFilter::query()->where('resource', $target)->where(fn ($q) => $q
            ->where(fn ($owner) => $owner->where('owner_type', $user->getMorphClass())->where('owner_id', $user->getAuthIdentifier()))
            ->orWhereIn('visibility', [Visibility::Shared->value, Visibility::Public->value]));
    }

    public function find(string $id, ?string $target = null, bool $owned = false, bool $legacy = false): SavedFilter
    {
        if ($target !== null) {
            $this->filters->authorize($target, $legacy);
        }
        $saved = SavedFilter::query()->when($target !== null, fn ($q) => $q->where('resource', $target))->findOrFail($id);
        $this->authorizeStoredTarget($saved, $legacy);
        $query = $this->visibleQuery($saved->resource);
        if ($owned) {
            $user = request()->user();
            $query->where('owner_type', $user->getMorphClass())->where('owner_id', $user->getAuthIdentifier());
        }

        return $query->findOrFail($id);
    }

    public function store(string $target, array $payload, bool $legacy = false): SavedFilter
    {
        $this->filters->authorize($target, $legacy);
        Gate::authorize('create', SavedFilter::class);
        if (! $legacy) {
            $this->validateTarget($payload, $target);
        }
        $input = ($legacy ? SavedFilterStoreInputData::class : SavedFilterInputData::class)::validateAndCreate($payload);
        $saved = new SavedFilter([
            ...ModelAttributeMapper::map($input),
            'resource' => $target,
            'query_parameters' => $this->queryParameters($target, $input->queryParameters, $legacy),
            'visibility' => is_string($input->visibility) ? $input->visibility : Visibility::Private->value,
            'is_default' => is_bool($input->isDefault) ? $input->isDefault : false,
        ]);
        $saved->owner()->associate(request()->user());
        $this->persist($saved);

        return $saved;
    }

    public function update(string $id, array $payload, ?string $target = null, bool $legacy = false): SavedFilter
    {
        $saved = $this->find($id, $target, owned: true, legacy: $legacy);
        Gate::authorize('update', $saved);
        if (! $legacy) {
            $this->validateTarget($payload, $saved->resource);
        }
        $input = ($legacy ? SavedFilterUpdateInputData::class : SavedFilterEditData::class)::validateAndCreate($payload);
        $saved->fill([
            ...ModelAttributeMapper::map($input),
            'query_parameters' => $this->queryParameters($saved->resource, is_array($input->queryParameters) ? $input->queryParameters : $saved->query_parameters, $legacy),
            'visibility' => is_string($input->visibility) ? $input->visibility : $saved->visibility->value,
            'is_default' => is_bool($input->isDefault) ? $input->isDefault : $saved->is_default,
        ]);
        $this->persist($saved);

        return $saved;
    }

    public function destroy(string $id, ?string $target = null, bool $legacy = false): void
    {
        $saved = $this->find($id, $target, owned: true, legacy: $legacy);
        Gate::authorize('delete', $saved);
        $saved->delete();
    }

    /** Service ownership and target checks remain authoritative even when a host policy permits all. */
    public function allowsMutation(SavedFilter $saved): bool
    {
        $user = request()->user();
        if ($user === null || $saved->owner_type !== $user->getMorphClass()
            || (string) $saved->owner_id !== (string) $user->getAuthIdentifier()) {
            return false;
        }
        try {
            // Metadata is emitted only after the serving entry point has authorized its exposure.
            // Retained non-Frame targets therefore keep the same explicit default-provider fallback.
            $this->authorizeStoredTarget($saved, legacy: true);

            return true;
        } catch (AuthorizationException $e) {
            if (! in_array($e->status() ?? 403, [403, 404], true)) {
                throw $e;
            }
        } catch (HttpExceptionInterface $e) {
            if (! in_array($e->getStatusCode(), [403, 404], true)) {
                throw $e;
            }
        }

        return false;
    }

    /** Check a loaded record's target/variant without looking the record up again. */
    public function authorizeStoredTarget(SavedFilter $saved, bool $legacy = false): void
    {
        $this->authorizeTarget($saved->resource, $legacy, $saved->query_parameters['filterVariant'] ?? null);
    }

    private function authorizeTarget(string $target, bool $legacy, ?string $variant = null): void
    {
        if ($this->isFramed($target)) {
            $this->filters->authorize($target);
            $frame = app(FrameResourceFilters::class);
            abort_unless($frame->provider($target) instanceof ResourceFilterValidator
                && $frame->schema($target, $variant)->savedViewsResource === 'saved-filters', 404);

            return;
        }
        $this->filters->definition($target, $legacy);
        abort_unless($this->filters->supportsSavedFilters($target), 404);
        if ($variant !== null) {
            app(FilterQuerySelection::class)->definition($target, $variant);
        }
    }

    private function validateTarget(array $payload, string $target): void
    {
        Validator::make($payload, ['resource' => ['sometimes', 'string', Rule::in([$target])]])->validate();
    }

    private function queryParameters(string $target, mixed $parameters, bool $legacy): array
    {
        $parameters = is_array($parameters) ? $parameters : [];
        $selection = ResourceQueryData::validateAndCreate($parameters);
        $this->authorizeTarget($target, $legacy, is_string($selection->filterVariant) ? $selection->filterVariant : null);
        if ($this->isFramed($target)) {
            $frame = app(FrameResourceFilters::class);
            $provider = $frame->provider($target);
            abort_unless($provider instanceof ResourceFilterValidator, 404);

            return $provider->validate($frame->definition($target), $parameters);
        }

        return app(FilterQuerySelection::class)->validate($target, $parameters, legacy: $legacy);
    }

    private function isFramed(string $target): bool
    {
        return app()->bound(ResourceRegistry::class) && app(ResourceRegistry::class)->find($target) !== null;
    }

    private function persist(SavedFilter $saved): void
    {
        $saved->getConnection()->transaction(function () use ($saved): void {
            if ($saved->is_default) {
                SavedFilter::query()->where('resource', $saved->resource)
                    ->where('owner_type', $saved->owner_type)->where('owner_id', $saved->owner_id)
                    ->when($saved->exists, fn ($q) => $q->whereKeyNot($saved->getKey()))
                    ->where('is_default', true)->update(['is_default' => false]);
            }
            $saved->save();
        });
    }
}
