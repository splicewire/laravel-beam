<?php

namespace Splicewire\Beam\Filters;

use Illuminate\Support\Facades\Validator;
use Schemastud\Frame\Contracts\FrameResourceHandler;
use Schemastud\Frame\Registry\ResourceDefinition;
use Splicewire\Beam\Filters\Data\SavedFilterData;

class SavedFilterResourceHandler implements FrameResourceHandler
{
    public function __construct(private SavedFilterService $saved) {}

    public function index(ResourceDefinition $definition, array $params): array
    {
        $target = $params['filter']['resource'] ?? null;
        Validator::make(['resource' => $target], ['resource' => ['required', 'string']])->validate();
        $page = $this->saved->visible($target)->orderByDesc('updated_at')->orderByDesc('id')
            ->paginate(max(1, min(100, (int) ($params['perPage'] ?? 25))), ['*'], 'page', max(1, (int) ($params['page'] ?? 1)));

        return ['data' => array_map(fn ($row) => SavedFilterData::from($row)->toArray(), $page->items()),
            'total' => $page->total(), 'page' => $page->currentPage(), 'perPage' => $page->perPage()];
    }

    public function show(ResourceDefinition $definition, string $id): array
    {
        return SavedFilterData::from($this->saved->find($id))->toArray();
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
