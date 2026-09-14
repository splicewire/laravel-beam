<?php

namespace Splicewire\Beam\Filters\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Rushing\LaravelDataSchemasScribe\Attributes\QueryFromData;
use Rushing\LaravelDataSchemasScribe\Attributes\RequestFromData;
use Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData;
use Schemastud\Frame\Contracts\ResourceRegistry;
use Schemastud\Frame\Data\FilterOptionsQueryData;
use Schemastud\Frame\Data\FilterOptionsResponseData;
use Schemastud\Frame\Data\FilterSchemaResponseData;
use Schemastud\Frame\Data\FilterVariantsResponseData;
use Schemastud\Frame\Data\ResourceQueryData;
use Schemastud\Frame\Filters\ResourceFilters as FrameResourceFilters;
use Splicewire\Beam\Filters\Data\SavedFilterData;
use Splicewire\Beam\Filters\Data\SavedFilterListResponseData;
use Splicewire\Beam\Filters\Data\SavedFilterResponseData;
use Splicewire\Beam\Filters\Data\SavedFilterStoreInputData;
use Splicewire\Beam\Filters\Data\SavedFilterUpdateInputData;
use Splicewire\Beam\Filters\ResourceFilters;
use Splicewire\Beam\Filters\SavedFilterService;
use Splicewire\Beam\Http\Controller;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/** Retained Particle::filters() HTTP projection of the shared resource runtime. */
class ResourceFiltersController extends Controller
{
    public const CONFIG = '_resource_filters';

    public function __construct(private ResourceFilters $filters, private SavedFilterService $saved) {}

    #[QueryFromData(ResourceQueryData::class)]
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
        $key = $this->resourceKey($request);

        return response()->json(($this->frame($key)?->schema($key)
            ?? $this->filters->schema($key, legacy: true))->toArray());
    }

    #[ResponseFromData(FilterSchemaResponseData::class)]
    public function variantSchema(Request $request): JsonResponse
    {
        $key = $this->resourceKey($request);
        $variant = (string) $request->route('variant');

        return response()->json(($this->frame($key)?->schema($key, $variant)
            ?? $this->filters->schema($key, $variant, legacy: true))->toArray());
    }

    #[ResponseFromData(FilterVariantsResponseData::class)]
    public function variants(Request $request): JsonResponse
    {
        $key = $this->resourceKey($request);

        return response()->json(($this->frame($key)?->variants($key)
            ?? $this->filters->variants($key, legacy: true))->toArray());
    }

    #[QueryFromData(FilterOptionsQueryData::class)]
    #[ResponseFromData(FilterOptionsResponseData::class)]
    public function options(Request $request): JsonResponse
    {
        $key = $this->resourceKey($request);
        $frame = $this->frame($key);
        if ($frame !== null) {
            $frame->definition($key);
        } else {
            $this->filters->authorize($key, legacy: true);
        }
        $query = FilterOptionsQueryData::validateAndCreate($request->query());
        $ref = (string) $request->route('ref');
        $search = is_string($query->search) ? $query->search : null;

        return response()->json(($frame?->options($key, $ref, $search)
            ?? $this->filters->options($key, $ref, $search, legacy: true))->toArray());
    }

    /** Only explicitly non-Frame retained resources use Beam's default runtime directly. */
    private function frame(string $key): ?FrameResourceFilters
    {
        $realm = request()->route('realm');
        if (is_string($realm) && $realm !== '') {
            abort_unless(in_array($realm, app(ParticleResourceRegistry::class)->realmsFor($key), true), 404);
        }

        return app()->bound(ResourceRegistry::class) && app(ResourceRegistry::class)->find($key) !== null
            ? app(FrameResourceFilters::class)
            : null;
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
