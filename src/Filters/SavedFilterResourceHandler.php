<?php

namespace Splicewire\Beam\Filters;

use Illuminate\Support\Facades\Validator;
use Schemastud\Frame\Contracts\FrameResourceHandler;
use Schemastud\Frame\Registry\ResourceDefinition;
use Splicewire\Beam\Authorization\ResourceReadGuard;
use Splicewire\Beam\Filters\Data\SavedFilterData;

/**
 * The saved-filter resource's bespoke Frame handler. Frame's socket asks no read question of a handler, and the
 * {@see SavedFilterService} predicate (the caller's own filters, or shared and public ones on the target) is an
 * ownership read outside the Gate, so a scoped credential is asked the read's name here (fleet ADR 0006 C2;
 * credential-scope 07 R5): `saved-filters.view` or `saved-filter.view` for the list, plus `saved-filter.{id}.view` for
 * one filter ({@see ResourceReadGuard::readNames()}). The target-resource and ownership restrictions still apply.
 */
class SavedFilterResourceHandler implements FrameResourceHandler
{
    public function __construct(private SavedFilterService $saved) {}

    public function index(ResourceDefinition $definition, array $params): array
    {
        $target = $params['filter']['resource'] ?? null;
        Validator::make(['resource' => $target], ['resource' => ['required', 'string']])->validate();
        ResourceReadGuard::forApp()->authorizeScopedRead($definition->key);
        $page = $this->saved->visible($target)->orderByDesc('updated_at')->orderByDesc('id')
            ->paginate(max(1, min(100, (int) ($params['perPage'] ?? 25))), ['*'], 'page', max(1, (int) ($params['page'] ?? 1)));

        return ['data' => array_map(fn ($row) => SavedFilterData::from($row)->toArray(), $page->items()),
            'total' => $page->total(), 'page' => $page->currentPage(), 'perPage' => $page->perPage()];
    }

    public function show(ResourceDefinition $definition, string $id): array
    {
        $filter = $this->saved->find($id);
        ResourceReadGuard::forApp()->authorizeScopedRead($definition->key, $filter);

        return SavedFilterData::from($filter)->toArray();
    }

    public function store(ResourceDefinition $definition, array $input): array
    {
        Validator::make($input, ['resource' => ['required', 'string']])->validate();

        return SavedFilterData::from($this->saved->store($input['resource'], $input))->toArray();
    }

    public function update(ResourceDefinition $definition, string $id, array $input): array
    {
        return SavedFilterData::from($this->saved->update($id, $input))->toArray();
    }

    public function destroy(ResourceDefinition $definition, string $id): void
    {
        $this->saved->destroy($id);
    }
}
