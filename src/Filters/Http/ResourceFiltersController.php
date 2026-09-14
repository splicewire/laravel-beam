<?php

namespace Splicewire\Beam\Filters\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Rushing\LaravelDataSchemasScribe\Attributes\QueryFromData;
use Rushing\LaravelDataSchemasScribe\Attributes\RequestFromData;
use Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData;
use Schemastud\Frame\Data\FilterOptionsQueryData;
use Schemastud\Frame\Data\FilterOptionsResponseData;
use Schemastud\Frame\Data\FilterSchemaResponseData;
use Schemastud\Frame\Data\FilterVariantsResponseData;
use Splicewire\Beam\Filters\Data\SavedFilterData;
use Splicewire\Beam\Filters\Data\SavedFilterListResponseData;
use Splicewire\Beam\Filters\Data\SavedFilterResponseData;
use Splicewire\Beam\Filters\Data\SavedFilterStoreInputData;
use Splicewire\Beam\Filters\Data\SavedFilterUpdateInputData;
use Splicewire\Beam\Filters\ResourceFilters;
use Splicewire\Beam\Filters\SavedFilterService;
use Splicewire\Beam\Http\Controller;

/** Retained Particle::filters() HTTP projection of the shared resource runtime. */
class ResourceFiltersController extends Controller
{
    public const CONFIG = '_resource_filters';

    public function __construct(private ResourceFilters $filters, private SavedFilterService $saved) {}

    #[ResponseFromData(SavedFilterListResponseData::class)]
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->saved->visible($this->resourceKey($request), legacy: true)->orderByDesc('updated_at')->get()
            ->map(fn ($saved) => SavedFilterData::withLegacyMetadata($saved)->toArray())]);
    }

    #[RequestFromData(SavedFilterStoreInputData::class)]
    #[ResponseFromData(SavedFilterResponseData::class, status: 201)]
    public function store(Request $request): JsonResponse
    {
        $saved = $this->saved->store($this->resourceKey($request), $request->all(), legacy: true);

        return response()->json(['data' => SavedFilterData::withLegacyMetadata($saved)->toArray()], 201);
    }

    #[ResponseFromData(SavedFilterResponseData::class)]
    public function show(Request $request): JsonResponse
    {
        $saved = $this->saved->find((string) $request->route('id'), $this->resourceKey($request), legacy: true);

        return response()->json(['data' => SavedFilterData::withLegacyMetadata($saved)->toArray()]);
    }

    #[RequestFromData(SavedFilterUpdateInputData::class)]
    #[ResponseFromData(SavedFilterResponseData::class)]
    public function update(Request $request): JsonResponse
    {
        $saved = $this->saved->update((string) $request->route('id'), $request->all(), $this->resourceKey($request), legacy: true);

        return response()->json(['data' => SavedFilterData::withLegacyMetadata($saved)->toArray()]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->saved->destroy((string) $request->route('id'), $this->resourceKey($request), legacy: true);

        return response()->json(status: 204);
    }

    #[ResponseFromData(FilterSchemaResponseData::class)]
    public function schema(Request $request): JsonResponse
    {
        return response()->json($this->filters->schema($this->resourceKey($request), legacy: true)->toArray());
    }

    #[ResponseFromData(FilterSchemaResponseData::class)]
    public function variantSchema(Request $request): JsonResponse
    {
        return response()->json($this->filters->schema($this->resourceKey($request), (string) $request->route('variant'), legacy: true)->toArray());
    }

    #[ResponseFromData(FilterVariantsResponseData::class)]
    public function variants(Request $request): JsonResponse
    {
        return response()->json($this->filters->variants($this->resourceKey($request), legacy: true)->toArray());
    }

    #[QueryFromData(FilterOptionsQueryData::class)]
    #[ResponseFromData(FilterOptionsResponseData::class)]
    public function options(Request $request): JsonResponse
    {
        $key = $this->resourceKey($request);
        $this->filters->authorize($key, legacy: true);
        $query = FilterOptionsQueryData::validateAndCreate($request->query());

        return response()->json($this->filters->options($key, (string) $request->route('ref'), is_string($query->search) ? $query->search : null, legacy: true)->toArray());
    }

    private function resourceKey(Request $request): string
    {
        $config = $request->route()->defaults[self::CONFIG] ?? null;
        if (! is_array($config) || ! array_key_exists('resource', $config)) {
            throw new RuntimeException('Filter sub-surface route is missing its '.self::CONFIG.' config. Register it via Particle::filters() — never by hand.');
        }

        return $config['resource'] ?? (string) $request->route('resource');
    }
}
