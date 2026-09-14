<?php

namespace Splicewire\Beam\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Rushing\DataFilters\SavedFilters\SavedFilter;
use Rushing\DataFilters\SavedFilters\SavedFilterValidator;
use Rushing\DataFilters\SavedFilters\Visibility;
use Splicewire\Beam\Filters\Data\SavedFilterEditData;
use Splicewire\Beam\Filters\Data\SavedFilterInputData;
use Splicewire\Beam\Filters\Data\SavedFilterStoreInputData;
use Splicewire\Beam\Filters\Data\SavedFilterUpdateInputData;
use Splicewire\Beam\Write\ModelAttributeMapper;

/** One persistence and authorization path for both resource CRUD and legacy filter mounts. */
class SavedFilterService
{
    public function __construct(private ResourceFilters $filters, private SavedFilterValidator $validator) {}

    public function visible(string $target, bool $legacy = false): Builder
    {
        $this->authorizeTarget($target, $legacy);
        $user = request()->user();
        abort_if($user === null, 403);

        return SavedFilter::query()->where('resource', $target)->where(fn ($q) => $q
            ->where(fn ($owner) => $owner->where('owner_type', $user->getMorphClass())->where('owner_id', $user->getAuthIdentifier()))
            ->orWhereIn('visibility', [Visibility::Shared->value, Visibility::Public->value]));
    }

    public function find(string $id, ?string $target = null, bool $owned = false, bool $legacy = false): SavedFilter
    {
        if ($target !== null) {
            $this->authorizeTarget($target, $legacy);
        }
        $saved = SavedFilter::query()->when($target !== null, fn ($q) => $q->where('resource', $target))->findOrFail($id);
        $query = $this->visible($saved->resource, $legacy);
        if ($owned) {
            $user = request()->user();
            $query->where('owner_type', $user->getMorphClass())->where('owner_id', $user->getAuthIdentifier());
        }

        return $query->findOrFail($id);
    }

    public function store(string $target, array $payload, bool $legacy = false): SavedFilter
    {
        $this->authorizeTarget($target, $legacy);
        Gate::authorize('create', SavedFilter::class);
        if (! $legacy) {
            $this->validateTarget($payload, $target);
        }
        $input = ($legacy ? SavedFilterStoreInputData::class : SavedFilterInputData::class)::validateAndCreate($payload);
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
            'query_parameters' => $this->queryParameters($saved->resource, $input->queryParameters),
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

    private function authorizeTarget(string $target, bool $legacy): void
    {
        $this->filters->definition($target, $legacy);
        abort_unless($this->filters->supportsSavedFilters($target), 404);
    }

    private function validateTarget(array $payload, string $target): void
    {
        Validator::make($payload, ['resource' => ['sometimes', 'string', Rule::in([$target])]])->validate();
    }

    private function queryParameters(string $target, mixed $parameters): array
    {
        $parameters = is_array($parameters) ? $parameters : [];
        Validator::make(['query_parameters' => $parameters], [
            'query_parameters' => ['array:filter,sort,include,limit'],
            'query_parameters.filter' => ['sometimes', 'array'],
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

        return $this->validator->validate($target, $parameters);
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
