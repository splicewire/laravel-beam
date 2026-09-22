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
use Schemastud\Frame\Data\ResourceQueryData;
use Schemastud\Frame\Filters\ResourceFilters as FrameResourceFilters;
use Splicewire\Beam\Filters\Data\SavedFilterEditData;
use Splicewire\Beam\Filters\Data\SavedFilterInputData;
use Splicewire\Beam\Write\ModelAttributeMapper;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Persistence and authorization for the contextual saved-filters resource. */
class SavedFilterService
{
    public function __construct(private ResourceFilters $filters) {}

    public function visible(string $target): Builder
    {
        $this->filters->authorize($target);
        $query = ResourceQueryData::validateAndCreate(request()->query());
        $this->authorizeTarget($target, is_string($query->filterVariant) ? $query->filterVariant : null);

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

    public function find(string $id, bool $owned = false): SavedFilter
    {
        $saved = SavedFilter::query()->findOrFail($id);
        $this->authorizeStoredTarget($saved);
        $query = $this->visibleQuery($saved->resource);
        if ($owned) {
            $user = request()->user();
            $query->where('owner_type', $user->getMorphClass())->where('owner_id', $user->getAuthIdentifier());
        }

        return $query->findOrFail($id);
    }

    public function store(string $target, array $payload): SavedFilter
    {
        $this->filters->authorize($target);
        Gate::authorize('create', SavedFilter::class);
        $this->validateTarget($payload, $target);
        $input = SavedFilterInputData::validateAndCreate($payload);
        $saved = new SavedFilter([
            ...ModelAttributeMapper::map($input),
            'resource' => $target,
            'query_parameters' => $this->queryParameters($target, $input->queryParameters),
            'visibility' => is_string($input->visibility) ? $input->visibility : Visibility::Private->value,
            'is_default' => is_bool($input->isDefault) ? $input->isDefault : false,
        ]);
        $saved->owner()->associate(request()->user());
        $this->persist($saved);

        return $saved;
    }

    public function update(string $id, array $payload): SavedFilter
    {
        $saved = $this->find($id, owned: true);
        Gate::authorize('update', $saved);
        $this->validateTarget($payload, $saved->resource);
        $input = SavedFilterEditData::validateAndCreate($payload);
        $saved->fill([
            ...ModelAttributeMapper::map($input),
            'query_parameters' => $this->queryParameters($saved->resource, is_array($input->queryParameters) ? $input->queryParameters : $saved->query_parameters),
            'visibility' => is_string($input->visibility) ? $input->visibility : $saved->visibility->value,
            'is_default' => is_bool($input->isDefault) ? $input->isDefault : $saved->is_default,
        ]);
        $this->persist($saved);

        return $saved;
    }

    public function destroy(string $id): void
    {
        $saved = $this->find($id, owned: true);
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
            $this->authorizeStoredTarget($saved);

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
    public function authorizeStoredTarget(SavedFilter $saved): void
    {
        $this->authorizeTarget($saved->resource, $saved->query_parameters['filterVariant'] ?? null);
    }

    private function authorizeTarget(string $target, ?string $variant = null): void
    {
        $this->filters->authorize($target);
        $frame = app(FrameResourceFilters::class);
        abort_unless($frame->provider($target) instanceof ResourceFilterValidator
            && $frame->schema($target, $variant)->savedViewsResource === 'saved-filters', 404);
    }

    private function validateTarget(array $payload, string $target): void
    {
        Validator::make($payload, ['resource' => ['sometimes', 'string', Rule::in([$target])]])->validate();
    }

    private function queryParameters(string $target, mixed $parameters): array
    {
        $parameters = is_array($parameters) ? $parameters : [];
        $selection = ResourceQueryData::validateAndCreate($parameters);
        $this->authorizeTarget($target, is_string($selection->filterVariant) ? $selection->filterVariant : null);
        $frame = app(FrameResourceFilters::class);
        $provider = $frame->provider($target);
        abort_unless($provider instanceof ResourceFilterValidator, 404);

        return $provider->validate($frame->definition($target), $parameters);
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
