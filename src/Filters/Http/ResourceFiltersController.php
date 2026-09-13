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
use Splicewire\Beam\Doctor\FilterablePromiseAudit;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Filters\Data\ResourceFilterVariantData;
use Splicewire\Beam\Filters\Data\ResourceFilterVariantsData;
use Splicewire\Beam\Filters\Data\SavedFilterStoreInputData;
use Splicewire\Beam\Filters\Data\SavedFilterUpdateInputData;
use Splicewire\Beam\Filters\ResourceFilterConstraints;
use Splicewire\Beam\Http\Controller;
use Splicewire\Beam\Particle\Backing\BackingResolver;
use Splicewire\Beam\Particle\Backing\BacksModel;
use Splicewire\Beam\Particle\Backing\DeclaresFilterVocabulary;
use Splicewire\Beam\Particle\ParticleListQuery;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
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
 * enumerated every silo, tag, agent and circuit name to any authenticated tenant user (ticket 10 §5).
 * Here every read and every write gates on the resource first.
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
     * List saved filters
     *
     * Every saved filter on this resource the caller can see: their own, plus anything shared or
     * public. Ordered most-recently-updated first.
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
     * Create saved filter
     *
     * The resource comes from the path, not the body. `query_parameters` is validated against the
     * resource's own filter vocabulary and rejected with a 422 if it names a facet the resource does
     * not allow.
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
     * Show saved filter
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->findVisible($request, (string) $request->route('id'))]);
    }

    /**
     * Update saved filter
     */
    #[RequestFromData(SavedFilterUpdateInputData::class)]
    public function update(Request $request, SavedFilterValidator $validator): JsonResponse
    {
        $saved = $this->findOwned($request, (string) $request->route('id'));

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
     * Delete saved filter
     */
    public function destroy(Request $request): JsonResponse
    {
        $this->findOwned($request, (string) $request->route('id'))->delete();

        return response()->json(status: Response::HTTP_NO_CONTENT);
    }

    /**
     * Get filter schema
     *
     * The JSON Schema of the resource's filter vocabulary — every facet it accepts, with the operators
     * and option references each one carries. It is the runtime twin of the `filter[…]` parameters this
     * resource's own index documents; both are generated from one declaration, so they cannot drift.
     *
     * Facet names on the wire are **camelCase**: `filter[externalRef]`, not `filter[external_ref]`.
     *
     * Three answers, consulted in this order:
     *
     *  1. **A backing that DECLARES its vocabulary** ({@see DeclaresFilterVocabulary}) is served that
     *     declaration — the streams-only case, where there is no Data class to reflect a schema off. It
     *     is consulted first, so a stub data-filters registration a host kept under the same key purely
     *     to render a panel stops answering the day the backing declares (beam ADR-0219). See
     *     {@see declaredVocabulary()}.
     *  2. **A data-filters registration** is reflected the way it always was — the `QueriesRecords`
     *     path, untouched.
     *  3. **A declaration that opted out** (`#[ParticleResource(filterable: false)]`) answers an EMPTY
     *     vocabulary — `{"type":"object","properties":{}}` — rather than 404. See
     *     {@see declaredEmptyVocabulary()} for why that is the honest answer and what still 404s.
     */
    public function schema(Request $request, ParticleResourceRegistry $resources): JsonResponse
    {
        $key = $this->resourceKey($request);
        $declaring = $key === '' ? null : $this->declaringBacking($resources->find($key));

        if ($declaring !== null) {
            $this->gateOnBacking($declaring);

            return response()->json(['data' => $declaring->filterVocabulary()->toSchema()]);
        }

        $definition = $key === '' ? null : DataFilter::tryResource($key);

        if ($definition !== null) {
            $this->gateOnModel($definition->model);

            return $this->schemaFor($definition);
        }

        return $this->declaredEmptyVocabulary($key, $resources);
    }

    /**
     * The resource's backing when it DECLARES its vocabulary, or null when there is no such resource or
     * its backing does not carry the capability (composite-backing ticket 02).
     *
     * Asked statically first — {@see BackingResolver::hasCapability()} is an `instanceof` on the
     * class-string and constructs nothing — and only then resolved, because {@see ParticleResource::backing()}
     * is container resolution at request time and may build a backing that needs a tenant connection.
     * Resolved ONCE: the caller gates on this instance ({@see gateOnBacking()}) and then asks it for
     * the vocabulary, rather than resolving again through `ParticleResource::modelClass()`. An EMPTY
     * declared vocabulary is a legitimate answer and is served as such (an object with no properties),
     * not demoted to the declared-empty branch.
     */
    private function declaringBacking(?ParticleResource $resource): ?DeclaresFilterVocabulary
    {
        if ($resource === null || ! (new BackingResolver)->hasCapability($resource->backing, DeclaresFilterVocabulary::class)) {
            return null;
        }

        $backing = $resource->backing();

        return $backing instanceof DeclaresFilterVocabulary ? $backing : null;
    }

    /**
     * Gate a declaring backing BEFORE asking it anything — the sub-surface's own rule ({@see store()}
     * validates after the gate for the same reason). Same `viewAny` derivation as every other read
     * here, keyed on the one model the backing backs when it backs one. A backing that backs none
     * (the review queue: two record types) has no policy subject, so the read is gated by the route's
     * middleware alone — which is exactly what the declared-empty branch and the flagship's stub
     * registration (`model: CircuitNodeRun`, a model with no policy) amounted to before.
     */
    private function gateOnBacking(DeclaresFilterVocabulary $backing): void
    {
        $this->gateOnModel($backing instanceof BacksModel ? $backing->modelClass() : null);
    }

    /**
     * The 200 a resource that DECLARED no filter surface gets, in place of the 404 a missing
     * data-filters registration used to produce (api-surface-coherence ticket 125).
     *
     * ## Why 404 was the wrong answer
     *
     * `DataFilter::tryResource()` misses for two structurally different reasons, and the flat 404 said
     * the same thing about both: *"there is no such resource"*. For `tenants` and `scaffold-packs` that
     * is false — both are registered `#[ParticleResource]`s, listed in the frame manifest the same
     * request already fetched, whose declarations say `filterable: false`. The honest answer to *"what
     * may I filter here"* is **nothing**, and an empty vocabulary says exactly that.
     *
     * ## What still 404s, and why the narrowing is the whole point
     *
     * Only a declaration that opted OUT reaches the empty vocabulary. Two other misses keep their 404:
     *
     *  - **An unknown key.** Nothing is registered under it in either registry; 404 is true, and it is
     *    also what keeps the flat `filter-schema/{resource}` enumeration leak closed (see the class
     *    docblock) — this branch never confirms a key the particle registry does not carry.
     *  - **A `filterable: true` resource with no data-filters registration** — the promise made by *not
     *    opting out* ({@see FilterablePromiseAudit}). That is a live defect, its
     *    index raises, and its filter sub-surface must keep answering 404 or the audit's own live arm
     *    would be reporting a fault the wire had learned to hide. Measured at the flagship 2026-08-29:
     *    3 of 51 registered resources are in that state (`role-assignments`, `role-templates`,
     *    `role-obligations`); 15 declare `filterable: false`, and those are this branch's population.
     *
     * ## It cannot cost sorting, which is the trap this ticket was filed around
     *
     * `x-sort` rides the same schema as `x-filter`, so anything that suppresses the schema fetch also
     * silently deletes column sorting. Nothing is deleted here: a `filterable: false` index is served by
     * {@see ParticleListQuery::forList()}, which applies the DECLARED default
     * order and never reads the request's `sort`. The set of sortable fields such a resource offers a
     * client is already empty; an empty `properties` is that fact stated rather than a fact lost.
     *
     * Gated like every other read in this sub-surface — on the declaration's own model, `viewAny` where
     * a policy defines one. The gate is the same code path {@see gateOnResource()} takes, not a second
     * spelling of it.
     */
    private function declaredEmptyVocabulary(string $key, ParticleResourceRegistry $resources): JsonResponse
    {
        $resource = $key === '' ? null : $resources->find($key);

        if ($resource === null || $resource->filterable) {
            abort(Response::HTTP_NOT_FOUND, "No filter resource registered for [{$key}].");
        }

        $this->gateOnModel($resource->modelClass());

        // `(object) []` and never `[]`: an empty PHP array encodes as a JSON *array*, and the wire
        // contract's `properties` is an object (`FilterSchema` in `@schemastud/facets`). A client
        // reading `Object.values(schema.properties)` survives either, which is precisely why the wrong
        // one would ship unnoticed.
        return response()->json(['data' => [
            'type' => 'object',
            'properties' => (object) [],
        ]]);
    }

    /**
     * Get filter variant schema
     *
     * Returns the schema for a variant listed by this resource's `/filters/variants` endpoint.
     * Use `/filters/schema` for the canonical vocabulary.
     */
    public function variantSchema(Request $request): JsonResponse
    {
        $definition = $this->definition($request);
        $variant = (string) $request->route('variant');

        // The same scoped vocabulary drives the discovery list and the documented allowed values.
        // Keep the resource gate above membership validation and the URL miss as a 404.
        if (! ResourceFilterConstraints::variants($definition->resource)->contains($variant)) {
            abort(Response::HTTP_NOT_FOUND, "No filter variant [{$variant}] on resource [{$definition->key}].");
        }

        return $this->schemaFor(DataFilter::resource($variant));
    }

    /**
     * List filter variants
     */
    #[ResponseFromData(ResourceFilterVariantsData::class)]
    public function variants(Request $request)
    {
        $definition = $this->definition($request);
        $canonicalDataClass = $definition->data;
        $variants = [];

        foreach (ResourceFilterConstraints::variants($definition->resource)->values() as $key) {
            $candidate = DataFilter::registry()->get($key);

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
     * per-resource; it gives the read a subject. A resource whose backing DECLARES its vocabulary
     * ({@see DeclaresFilterVocabulary}) goes one step further: it answers only the handles its own facets
     * name, and 404s the rest.
     */
    public function options(Request $request, ParticleResourceRegistry $resources): JsonResponse
    {
        // Read off the route, never taken as a positional method argument: at the Frame resource root
        // the route carries TWO parameters (`{resource}`, `{ref}`) and the dispatcher hands them to the
        // action positionally, so a `string $ref` parameter would receive the RESOURCE key there and
        // the real ref would be dropped on the floor. Measured at the flagship 2026-09-12 — the
        // frame-root options read answered 404 for a registered handle for exactly this reason.
        $ref = (string) $request->route('ref');
        $key = $this->resourceKey($request);
        $declaring = $key === '' ? null : $this->declaringBacking($resources->find($key));

        if ($declaring !== null) {
            // No data-filters definition to gate through, so the gate is {@see gateOnBacking()} — the
            // backing's own model's `viewAny` when it backs one, the route middleware alone when it
            // does not — and the read narrows the flat namespace further than the registry path can:
            // only a handle one of ITS facets names is this resource's to enumerate.
            $this->gateOnBacking($declaring);

            abort_unless(ResourceFilterConstraints::options($declaring->filterVocabulary())->contains($ref), Response::HTTP_NOT_FOUND, "No filter options [{$ref}] on resource [{$key}].");
        } else {
            $this->definition($request);
        }

        abort_unless(ResourceFilterConstraints::options()->contains($ref), Response::HTTP_NOT_FOUND, "No filter options registered for [{$ref}].");

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
        $key = $this->resourceKey($request);

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
     * The resource key this route serves, read off the route's frozen config — never off the URI.
     *
     * Split out of {@see definition()} so {@see schema()} can ask "which resource is this route for"
     * WITHOUT committing to "…and it must have a data-filters registration": the two questions used to
     * be one method, which is why a declaration that opted out of filtering could only be answered 404.
     */
    private function resourceKey(Request $request): string
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
        return $config['resource'] ?? (string) $request->route('resource');
    }

    /**
     * Gate on the CLASS-level ability the resource's own index requires.
     *
     * There is no record here, so the per-record `view` the read routes authorize has nothing to
     * authorize against; `viewAny` is the ability that means "may see that these exist" — the same
     * derivation the rendering catalog made one surface over, before
     * particle-operation-surface 13 dissolved it.
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
        $this->gateOnModel($definition->model);
    }

    /**
     * The gate itself, keyed on the model rather than on a data-filters definition — so the
     * declared-empty-vocabulary branch ({@see declaredEmptyVocabulary()}), which has no
     * `ResourceDefinition` to hand, gates through the SAME code rather than a second spelling of it.
     *
     * @param  class-string|null  $model
     */
    private function gateOnModel(?string $model): void
    {
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
