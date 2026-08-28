<?php

namespace Splicewire\Beam\Filters\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Rushing\DataFilters\Facades\DataFilter;
use Rushing\DataFilters\Registry\ResourceDefinition;
use Rushing\DataFilters\SavedFilters\SavedFilter;
use Rushing\DataFilters\SavedFilters\SavedFilterValidator;
use Rushing\DataFilters\SavedFilters\Visibility;
use Rushing\LaravelDataSchemasScribe\Attributes\RequestFromData;
use Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Splicewire\Beam\Data\ResponseBody;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Filters\Data\ResourceFilterVariantData;
use Splicewire\Beam\Filters\Data\ResourceFilterVariantsData;
use Splicewire\Beam\Filters\Data\SavedFilterStoreInputData;
use Splicewire\Beam\Filters\Data\SavedFilterUpdateInputData;
use Splicewire\Beam\Http\Controller;
use Splicewire\Beam\Rendering\Http\RenderingCatalogController;
use Symfony\Component\HttpFoundation\Response;

/**
 * The per-resource filter sub-surface — saved filters, the filter schema, its variants, and the
 * relational option vocabularies, all hanging off the resource they belong to
 * (api-surface-coherence ticket 10, build 35).
 *
 * It replaces three flat routes that had no resource in the path: `GET /saved-filters` (which took
 * `?resource=` and a body `resource` field), `GET /filter-schema/{resource}`, and
 * `GET /filter-options/{key}`. Two things fall out of putting the resource in the path.
 *
 * **The membership check disappears.** Both saved-filter write DTOs carried
 * `Rule::in(array_keys(DataFilter::registry()->all()))` on a body field. The registry lookup on the path
 * segment is the same check, done once, by construction.
 *
 * **The options leak closes.** `GET /filter-options/{key}` had *no resource to check against* and so
 * enumerated every silo, tag, agent and circuit name to any authenticated tenant user (ticket 10 §5 —
 * and {@see RenderingCatalogController} already names it as the reason
 * its own gate exists). Here every read and every write gates on the resource first.
 *
 * **Mounting.** Never mount these by hand — {@see Particle::filters()} does it, and
 * `Route::particleResource()` calls that macro automatically, so the sub-surface follows a resource to
 * every exposure of it, nested mounts included. The resource is read off the route's frozen config,
 * NEVER off the URI segment: half the estate's filter keys diverge from their URL word and that
 * divergence is legitimate (ticket 10 §1). The one exception is deliberate and marked — the Frame
 * resource root is itself parameterised by the registration key, so there the segment IS the key.
 *
 * @group Filters
 */
class ResourceFiltersController extends Controller
{
    /**
     * The route default the macro stamps this route's per-resource config under.
     *
     * Shape: `['resource' => string|null]`. A null resource means "read it from the `{resource}` route
     * parameter" — the Frame-resource-root case.
     */
    public const CONFIG = '_resource_filters';

    /**
     * Saved filters for this resource
     *
     * Every saved filter on this resource the caller can see: their own, plus anything shared or
     * public. Ordered most-recently-updated first.
     *
     * @urlParam resource string required Present only at the Frame resource root, which is parameterised BY the registry key; at a bespoke exposure the resource is frozen into the route and this segment does not exist. Example: circuit-runs
     */
    public function index(Request $request): JsonResponse
    {
        $definition = $this->definition($request);
        $user = $request->user();

        $filters = SavedFilter::query()
            ->where('resource', $definition->key)
            ->where(fn ($q) => $this->scopeVisible($q, $user))
            ->orderByDesc('updated_at')
            ->get();

        return response()->json(['data' => $filters]);
    }

    /**
     * Save a filter on this resource
     *
     * The resource comes from the path, not the body. `query_parameters` is validated against the
     * resource's own filter vocabulary and rejected with a 422 if it names a facet the resource does
     * not allow.
     *
     * @urlParam resource string required Present only at the Frame resource root, which is parameterised BY the registry key; at a bespoke exposure the resource is frozen into the route and this segment does not exist. Example: circuit-runs
     */
    #[RequestFromData(SavedFilterStoreInputData::class)]
    public function store(Request $request, SavedFilterValidator $validator): JsonResponse
    {
        $definition = $this->definition($request);

        // Validated AFTER the resource gate, deliberately: a caller who may not see the resource gets
        // the authorization failure, not a 422 that would confirm the field vocabulary. Resolved here
        // rather than injected as a typed parameter for the same reason — container injection would
        // validate during resolution, ahead of this method body.
        $input = SavedFilterStoreInputData::validateAndCreate($request);

        $saved = new SavedFilter([
            'name' => $input->name,
            'resource' => $definition->key,
            'query_parameters' => $validator->validate($definition->key, $this->arrayOr($input->queryParameters)),
            'visibility' => $this->stringOr($input->visibility) ?? Visibility::Private->value,
            'is_default' => $this->boolOr($input->isDefault) ?? false,
        ]);
        $saved->owner()->associate($request->user());

        $this->demoteSiblingDefaults($saved);

        $saved->save();

        return response()->json(['data' => $saved], Response::HTTP_CREATED);
    }

    /**
     * One saved filter
     *
     * @urlParam resource string required Present only at the Frame resource root, which is parameterised BY the registry key; at a bespoke exposure the resource is frozen into the route and this segment does not exist. Example: circuit-runs
     */
    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->findVisible($request, $id)]);
    }

    /**
     * Update a saved filter
     *
     * @urlParam resource string required Present only at the Frame resource root, which is parameterised BY the registry key; at a bespoke exposure the resource is frozen into the route and this segment does not exist. Example: circuit-runs
     */
    #[RequestFromData(SavedFilterUpdateInputData::class)]
    public function update(Request $request, SavedFilterValidator $validator, string $id): JsonResponse
    {
        $saved = $this->findOwned($request, $id);

        // Below findOwned() so a caller who does not own the filter keeps the 404 that lookup raises
        // rather than a 422 that would confirm the field vocabulary.
        $input = SavedFilterUpdateInputData::validateAndCreate($request);

        $saved->fill([
            'name' => $input->name,
            'query_parameters' => $validator->validate($saved->resource, $this->arrayOr($input->queryParameters)),
            'visibility' => $this->stringOr($input->visibility) ?? $saved->visibility->value,
            'is_default' => $this->boolOr($input->isDefault) ?? $saved->is_default,
        ]);

        $this->demoteSiblingDefaults($saved);

        $saved->save();

        return response()->json(['data' => $saved]);
    }

    /**
     * Delete a saved filter
     *
     * @urlParam resource string required Present only at the Frame resource root, which is parameterised BY the registry key; at a bespoke exposure the resource is frozen into the route and this segment does not exist. Example: circuit-runs
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->findOwned($request, $id)->delete();

        return response()->json(status: Response::HTTP_NO_CONTENT);
    }

    /**
     * The filter vocabulary for this resource
     *
     * The JSON Schema of the resource's filter vocabulary — every facet it accepts, with the operators
     * and option references each one carries. It is the runtime twin of the `filter[…]` parameters this
     * resource's own index documents; both are generated from one declaration, so they cannot drift.
     *
     * Facet names on the wire are **camelCase**: `filter[externalRef]`, not `filter[external_ref]`.
     *
     * @urlParam resource string required Present only at the Frame resource root, which is parameterised BY the registry key; at a bespoke exposure the resource is frozen into the route and this segment does not exist. Example: circuit-runs
     */
    public function schema(Request $request): JsonResponse
    {
        return $this->schemaFor($this->definition($request));
    }

    /**
     * The filter vocabulary for one variant of this resource
     *
     * `{variant}` is a registry key whose declaration names this resource. Omitting it — plain
     * `/filters/schema` — means the canonical one. Today every non-canonical key on a resource is a
     * legacy alias declared on the same Data class, so a variant schema is byte-identical to the
     * canonical; that is correct, and `/filters/variants` says so per variant rather than leaving a
     * caller to discover it by diffing.
     *
     * @urlParam variant string required A registry key whose declaration names this resource — one of the `key` values `GET /{resource}/filters/variants` lists. Example: circuit-runs
     * @urlParam resource string required Present only at the Frame resource root, which is parameterised BY the registry key; at a bespoke exposure the resource is frozen into the route and this segment does not exist. Example: circuit-runs
     */
    public function variantSchema(Request $request, string $variant): JsonResponse
    {
        $definition = $this->definition($request);
        $candidate = DataFilter::tryResource($variant);

        // The variant must belong to THIS resource. Without that check the variant segment would be a
        // second, ungated address for every other resource's schema — which is the flat
        // `filter-schema/{resource}` leak re-opened one segment to the right.
        if ($candidate === null || $candidate->resource !== $definition->resource) {
            abort(Response::HTTP_NOT_FOUND, "No filter variant [{$variant}] on resource [{$definition->key}].");
        }

        return $this->schemaFor($candidate);
    }

    /**
     * The filter vocabularies this resource offers
     *
     * @urlParam resource string required Present only at the Frame resource root, which is parameterised BY the registry key; at a bespoke exposure the resource is frozen into the route and this segment does not exist. Example: circuit-runs
     */
    #[ResponseFromData(ResourceFilterVariantsData::class)]
    public function variants(Request $request)
    {
        $definition = $this->definition($request);
        $canonicalDataClass = $definition->data;
        $variants = [];

        foreach (DataFilter::registry()->all() as $key => $candidate) {
            if ($candidate->resource !== $definition->resource) {
                continue;
            }

            $variants[] = new ResourceFilterVariantData(
                key: (string) $key,
                resource: $definition->resource,
                canonical: (string) $key === $definition->resource,
                sameAsCanonical: $candidate->data === $canonicalDataClass,
            );
        }

        return ResponseBody::from(['data' => new ResourceFilterVariantsData(
            resource: $definition->resource,
            variants: $variants,
        )]);
    }

    /**
     * The option vocabulary behind one facet of this resource
     *
     * `{ref}` is the `optionsRef` a facet publishes in its `x-filter` keyword — the handle for a
     * relational value list (silos, tags, agents, …). Optional `?search=` narrows it.
     *
     * The options registry is a flat, cross-resource namespace, which is exactly why the flat route had
     * nothing to authorize against. Reaching it through a resource does not make the vocabulary
     * per-resource; it gives the read a subject.
     *
     * @urlParam ref string required The `optionsRef` a facet publishes in its `x-filter` keyword — the handle for one relational value list. Example: run-statuses
     * @urlParam resource string required Present only at the Frame resource root, which is parameterised BY the registry key; at a bespoke exposure the resource is frozen into the route and this segment does not exist. Example: circuit-runs
     */
    public function options(Request $request, string $ref): JsonResponse
    {
        $this->definition($request);

        abort_unless(DataFilter::hasOptions($ref), Response::HTTP_NOT_FOUND, "No filter options registered for [{$ref}].");

        return response()->json([
            'data' => DataFilter::resolveOptions($ref, $request->string('search')->toString() ?: null),
        ]);
    }

    /**
     * Resolve the resource this route serves, and gate on it.
     *
     * Every public action funnels through here — that is the whole point of the inversion, and the
     * reason there is no un-gated read left in this sub-surface.
     */
    private function definition(Request $request): ResourceDefinition
    {
        $config = $request->route()->defaults[self::CONFIG] ?? null;

        if (! is_array($config) || ! array_key_exists('resource', $config)) {
            throw new RuntimeException(
                'Filter sub-surface route is missing its '.self::CONFIG.' config. '
                .'Register it via Particle::filters() — never by hand.'
            );
        }

        // A null configured resource is the Frame-resource-root case and ONLY that: the frame root is
        // itself parameterised by the registration key, so `{resource}` there is not a URL word that
        // might diverge from the key — it IS the key.
        $key = $config['resource'] ?? (string) $request->route('resource');

        // `tryResource()`, never `resource()` — registry-kernel ticket 61. The throwing accessor raises
        // `RegistryMiss`, which escaping a controller is a 500; that is right for a key the code chose
        // and wrong for one that came off a URL. The nullable twin also swallows a segment that is not
        // a legal registry key AT ALL (`Fragments`, `fragments-`), which the kernel's parser rejects
        // with `InvalidRegistryKey` before a miss is even considered. From a path segment both are the
        // same unknown resource, and both must answer 404. The flat `filter-schema/{resource}` this
        // replaces got exactly this wrong: it caught `InvalidArgumentException`, which the conforming
        // registry stopped throwing, so its 404 branch was dead code and an unknown resource 500'd.
        $definition = $key === '' ? null : DataFilter::tryResource($key);

        if ($definition === null) {
            abort(Response::HTTP_NOT_FOUND, "No filter resource registered for [{$key}].");
        }

        $this->gateOnResource($definition);

        return $definition;
    }

    /**
     * Gate on the CLASS-level ability the resource's own index requires.
     *
     * There is no record here, so the per-record `view` the read routes authorize has nothing to
     * authorize against; `viewAny` is the ability that means "may see that these exist" — the same
     * derivation {@see RenderingCatalogController} made one surface over.
     *
     * ASKS the Gate for whatever policy is bound rather than naming one, and skips when the model has no
     * policy or the policy declares no `viewAny`. That is not a hole being left open, it is the only
     * safe reading: `Gate::authorize()` on an ability nothing defines DENIES, so a blind call would 403
     * every resource in the estate that leans on its `ResourceQuery::baseQuery()` row-level scoping
     * instead of a class-level policy — which is most of them (ADR-0156 §83: for a `filterable` resource
     * the data-filters query IS the index's gate). What lands here is therefore strictly more gating
     * than the flat routes had, never less, and the row-level scope still governs the actual reads.
     *
     * ⚠️ It is also STRICTER than a filterable resource's own index, and that is a decision rather
     * than an oversight. Ticket 35 words the gate as "the same ability the resource's own index
     * requires", but a filterable index requires NO class-level ability — its data-filters query is
     * its gate — so there is no same-ability to match and `viewAny` is the nearest honest reading of
     * ticket 10 §5's "gate EVERY read and write … saved filters *and* options". Measured consequence:
     * a caller holding no permissions at all gets `200` (an empty, caller-scoped list) from
     * `GET /circuits` and `403` from `/circuits/filters/schema`. That caller is a bare user with no
     * role, not a working tenant member — a member holding `circuit.own.view` passes both — and the
     * host pins the asymmetry in `ResourceFilterGateTest` so it stays a decision.
     *
     * Named `gateOnResource` and not `authorizeResource`: the latter is already taken by
     * `Illuminate\Foundation\Auth\Access\AuthorizesRequests` (a PUBLIC method that maps a controller
     * onto a model for implicit policy binding), and a private redeclaration is a fatal
     * "access level must be public" at boot, not a soft override. Measured here, on the first
     * `route:list` after writing it.
     */
    private function gateOnResource(ResourceDefinition $definition): void
    {
        $model = $definition->model;

        if ($model === null) {
            return;
        }

        $policy = Gate::getPolicyFor($model);

        if ($policy === null || ! method_exists($policy, 'viewAny')) {
            return;
        }

        $this->authorize('viewAny', $model);
    }

    private function schemaFor(ResourceDefinition $definition): JsonResponse
    {
        $generator = new JsonSchemaGenerator(['strategies' => config('data-schemas.strategies')]);

        return response()->json(['data' => $generator->generate(new \ReflectionClass($definition->data))]);
    }

    /**
     * One default per (owner, resource) — specified and enforced here rather than deleted.
     *
     * `is_default` has been writable, stored and cast since the table was created, and read by nothing,
     * with no uniqueness rule and no scoping (ticket 10 §5). A flat cross-resource list could not make
     * "the default" mean anything; a per-resource surface can. The database carries the matching partial
     * unique index, so a concurrent write cannot land a second default behind this check's back.
     */
    private function demoteSiblingDefaults(SavedFilter $saved): void
    {
        if (! $saved->is_default) {
            return;
        }

        SavedFilter::query()
            ->where('resource', $saved->resource)
            ->where('owner_type', $saved->owner_type)
            ->where('owner_id', $saved->owner_id)
            ->when($saved->exists, fn ($q) => $q->whereKeyNot($saved->getKey()))
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }

    private function findOwned(Request $request, string $id): SavedFilter
    {
        $definition = $this->definition($request);
        $user = $request->user();

        return SavedFilter::query()
            ->where('resource', $definition->key)
            ->where('owner_type', $user->getMorphClass())
            ->where('owner_id', $user->getKey())
            ->findOrFail($id);
    }

    private function findVisible(Request $request, string $id): SavedFilter
    {
        $definition = $this->definition($request);
        $user = $request->user();

        return SavedFilter::query()
            ->where('resource', $definition->key)
            ->where(fn ($q) => $this->scopeVisible($q, $user))
            ->findOrFail($id);
    }

    /**
     * Owner-or-Shared-or-Public. Visibility survives the inversion; it just stopped being the ONLY
     * gate (ticket 10 §5).
     */
    private function scopeVisible($query, $user)
    {
        return $query
            ->where(fn ($o) => $o->where('owner_type', $user->getMorphClass())->where('owner_id', $user->getKey()))
            ->orWhereIn('visibility', [Visibility::Shared->value, Visibility::Public->value]);
    }

    /** @return array<string, mixed> */
    private function arrayOr(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function stringOr(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private function boolOr(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }
}
