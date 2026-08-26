<?php

namespace Splicewire\Beam\Http\Particle;

use Closure;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Http\Contracts\ResponseEnvelope;
use Splicewire\Beam\Particle\Backing\BackingResolver;
use Splicewire\Beam\Particle\Backing\QueriesRecords;
use Splicewire\Beam\Particle\Backing\WritesRecords;
use Splicewire\Beam\Particle\Contribution\ContributionProjector;
use Splicewire\Beam\Particle\Contribution\ResourceContributionRegistry;
use Splicewire\Beam\Particle\ParticleListQuery;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Read\Contracts\ParticleHydrator;
use Splicewire\Beam\Read\ReadContext;
use Splicewire\Beam\Scribe\Strategies\ParticleListParameterStrategy;
use Splicewire\Beam\Write\ParticleWriter;

/**
 * The generic REST controller every model-backed resource can ride instead of hand-rolling
 * index/show/store/update/destroy. It wires the two beam seams — the {@see ParticleWriter} write pipeline
 * (validate→authorize→persist→emit) and the {@see ParticleHydrator} read seam (query→project) — against a
 * declarative {@see ParticleResource}, so a plain CRUD resource needs zero controller code and a nearly-
 * plain one needs only its bespoke deltas.
 *
 * Authorization is deny-by-default and unchanged from a hand-rolled controller: the writer's `WriteGate`
 * delegates create/update to the model's policy; `show`/`destroy` authorize `view`/`delete`; and the list
 * query's own row-scoping (the data-filters resource) is the read gate for `index`.
 *
 * THREE WAYS TO MOUNT IT (see {@see ParticleResource}):
 *   - **inline** — `Route::particleResource('saved-filters', 'saved_filter')` points conventional routes
 *     here with a `_particle` route-default naming the registered resource; no subclass.
 *   - **extension** — `class SiloController extends ParticleController` overrides {@see ParticleResource()}
 *     and adds bespoke actions that call the inherited internals (`writeParticle`, `findParticle`, …).
 *
 * The ONE app-coupling this surface used to carry — the app's `Splicewire\Beam\Data\ResponseBody` — is hoisted behind
 * the {@see ResponseEnvelope} port (ADR-0116): beam-core builds its `{ data: … }` JSON through the seam,
 * and a host binds its own envelope adapter so its responses stay byte-compatible.
 */
class ParticleController extends Controller
{
    use AuthorizesRequests;

    /** Route-default key naming the {@see ParticleResource} for the inline tier. */
    public const RESOURCE = '_particle';

    /**
     * The pagination query keys the generic index reads. Named here rather than inlined so the reference
     * DOCUMENTS the same words the controller ACCEPTS — {@see ParticleListParameterStrategy}
     * derives the parameter names from these constants instead of restating them, which is what lets the
     * camelCase cutover flip one word in one place and carry the docs with it.
     */
    public const PAGE = 'page';

    public const PER_PAGE = 'perPage';

    /**
     * The route parameter naming the SUBJECT of a show/update/destroy — read by name (see
     * {@see subjectId()}), which is what makes a standalone and a relative mount resolve identically.
     * Named here for the same reason as {@see PAGE}: {@see ParticleUrlParameterStrategy} documents
     * the parameter this controller resolves, and a second spelling of the word is a drift window.
     */
    public const SUBJECT = 'id';

    /**
     * Route-default keys naming the optional **relative** context (HTTP-02). When present, the resource is
     * mounted *through* a route-model-bound relative (e.g. `/fragments/{fragment}/media`): {@see RELATIVE}
     * carries the bound parent model instance, {@see VIA} the relation name (string) or scope (Closure) the
     * child list/create is based on. Absent (the default) ⇒ the standalone path, byte-for-byte today's code.
     */
    public const RELATIVE = '_particle_relative';

    public const RELATIVE_MODEL = '_particle_relative_model';

    public const VIA = '_particle_via';

    public function __construct(
        protected ParticleWriter $writer,
        protected ParticleHydrator $hydrator,
        protected ParticleResourceRegistry $registry,
        protected ResponseEnvelope $envelope,
    ) {}

    public function index(Request $request): Responsable
    {
        $resource = $this->particleResource($request);
        $ctx = ReadContext::list($resource->includes, $request->user());
        $facets = $this->facets($request);

        // Relative mount (HTTP-02): when the route bound a relative, the index is a listing THROUGH it
        // (`$relative->{via}()` / the scope closure) instead of `model::query()` — the child rows a caller
        // sees are exactly the ones hanging off the (already-authorized) parent. Absent a relative, this is
        // null and the standalone path below is byte-for-byte today's code.
        $relativeQuery = $this->relativeBaseQuery($request);

        $query = $resource->filterable
            ? $this->hydrator->query($resource->key, $ctx)
            : ($relativeQuery ?? $this->defaultSortedQuery($resource, $facets));

        // Row-level authorization for the non-filterable list (ADR-0156 §83): a `filterable:false` resource
        // has no data-filters query to gate its index, so its owner/inverse `scope` closure is the ONLY read
        // guard — without it the list would return every row across all callers. Applied here (not for the
        // filterable path, whose data-filters query is its own gate). Mirrors ParticleFrameResourceHandler.
        if (! $resource->filterable && $resource->scope !== null) {
            $query = ($resource->scope)($query) ?? $query;
        }

        $page = $query->paginate($request->integer(self::PER_PAGE, $resource->perPage), ['*'], self::PAGE);
        $page->through(fn (Model $record) => $this->projectRecord($resource, $record, $ctx, $facets));

        return $this->envelope->paginated($page);
    }

    /**
     * The resource's backing, asserted to compose an Eloquent query.
     *
     * The REST transport's subject resolution and relative-mount base both need a `Builder` they can go
     * on composing (a relation scope, the `scope` closure, `findOrFail`), which is
     * {@see QueriesRecords} — the Eloquent-only capability, not the general {@see StreamsRecords} one.
     * A resource whose backing merely streams cannot be served here, and says so by name rather than
     * failing later on a method the returned value does not have.
     */
    protected function queryableBacking(ParticleResource $resource): QueriesRecords
    {
        $backing = $resource->backing();

        if (! $backing instanceof QueriesRecords) {
            throw new RuntimeException(
                "Resource [{$resource->key}] cannot be served over REST: its backing [".$backing::class
                .'] does not implement '.QueriesRecords::class.'.'
            );
        }

        return $backing;
    }

    /**
     * The resource's backing, asserted to write.
     *
     * ⚠️ This is a LAST-LINE assertion, not the gate. Capability is checked at REGISTRATION
     * ({@see BackingResolver::assertAffordancesWithinCapability()}),
     * so a resource declaring `creatable` against a non-writing backing never boots. Reaching this
     * throw means a store arrived for a resource that declared no create affordance at all.
     */
    protected function writableBacking(ParticleResource $resource): WritesRecords
    {
        $backing = $resource->backing();

        if (! $backing instanceof WritesRecords) {
            throw new RuntimeException(
                "Resource [{$resource->key}] cannot be written: its backing [".$backing::class
                .'] does not implement '.WritesRecords::class.'.'
            );
        }

        return $backing;
    }

    /**
     * The base query for a NON-filterable index: the declaration's `includes` eager-loaded, ordered by
     * its declared default sort.
     *
     * Both axes live in {@see ParticleListQuery}, which the Frame transport calls too. Until ticket 05
     * this method built its own query and applied ONLY the sort — so a non-filterable REST list lazy-
     * loaded every declared include per row, while the Frame transport eager-loaded them and hardcoded
     * `created_at desc` instead. Two transports, one declaration, each missing the half the other had.
     *
     * The facet bag is forwarded because a CONTRIBUTED include may be request-parameterized (a
     * constrained eager-load — `with(['bills' => fn ($q) => $q->forPeriod($period)])`). A non-filterable
     * resource has no data-filters query interpreting `filter[...]`, but that never made the bag absent —
     * only uninterpreted, which is exactly the passthrough a streaming backing already gets.
     *
     * @param  array<string, mixed>  $filters  the opaque facet bag
     */
    protected function defaultSortedQuery(ParticleResource $resource, array $filters = []): Builder
    {
        return (new ParticleListQuery)->forList($resource, $filters);
    }

    public function show(Request $request, string $id): Responsable
    {
        $resource = $this->particleResource($request);
        $model = $this->findParticle($resource, $this->subjectId($request, $id), $request);
        $this->authorize('view', $model);

        return $this->respond($resource, $model, $request);
    }

    public function store(Request $request): Responsable
    {
        return $this->createParticle($request, created: true);
    }

    /**
     * The create body. `store()` calls it with `created: true` (a REST `201`); an extending controller whose
     * legacy verb answered `200` (e.g. Silo's `POST /silos`) rides it with the default `created: false`.
     */
    protected function createParticle(Request $request, bool $created = false): Responsable
    {
        $resource = $this->particleResource($request);
        $input = $this->parseInput($resource, $request);

        // Relative mount (HTTP-02): create THROUGH the bound relation so the FK is set from the bound parent
        // — structural association, never a forgeable body field. `newRelativeModel` returns a fresh model
        // whose inverse is pre-associated (the relation-name `via:` form); a scope-closure `via:` can't
        // auto-associate, so it falls back to a plain `new` and pairs with the resource's own prepare hook.
        // Absent a relative, this is the backing's own fresh record — today's exact code path for an
        // EloquentBacking, which is `new $model`.
        $model = $this->newRelativeModel($request) ?? $this->writableBacking($resource)->newRecord();
        if ($resource->prepare !== null) {
            ($resource->prepare)($model, $input, $request->user());
        }

        $model = $this->writeParticle($resource, $model, $input, $request->user());

        return $this->respond($resource, $model, $request, created: $created);
    }

    public function update(Request $request, string $id): Responsable
    {
        $resource = $this->particleResource($request);
        $input = $this->parseInput($resource, $request);
        $model = $this->findParticle($resource, $this->subjectId($request, $id), $request);

        if ($resource->prepare !== null) {
            ($resource->prepare)($model, $input, $request->user());
        }

        $model = $this->writeParticle($resource, $model, $input, $request->user());

        return $this->respond($resource, $model, $request);
    }

    public function destroy(Request $request, string $id): Responsable
    {
        $resource = $this->particleResource($request);
        $model = $this->findParticle($resource, $this->subjectId($request, $id), $request);
        $this->authorize('delete', $model);
        $model->delete();

        return $this->envelope->item(null);
    }

    // ---- Internals — the seam an extending controller rides for its bespoke actions -----------------

    /**
     * The resource this controller serves. The inline tier resolves it from the route default + registry;
     * an extending controller OVERRIDES this to return its declaration directly.
     */
    protected function particleResource(Request $request): ParticleResource
    {
        $key = $request->route()?->defaults[static::RESOURCE] ?? null;

        if ($key === null) {
            throw new RuntimeException(static::class.' has no particle resource — override particleResource() or mount via Route::particleResource().');
        }

        return $this->registry->get($key);
    }

    /**
     * The optional **relative** context (HTTP-02): the route-model-bound parent + the `via` (relation name
     * or scope closure) a `Route::particleRelative` mount stamped into the route defaults, or
     * `[null, null]` when the resource is mounted standalone (every existing resource — today's exact path).
     *
     * The macro stamps the binding PARAM NAME (`RELATIVE`) into defaults; the bound Model itself is resolved
     * per-request off the route parameter (Laravel's route-model binding), read here.
     *
     * @return array{0: ?Model, 1: string|Closure|null}
     */
    protected function relativeContext(Request $request): array
    {
        $route = $request->route();
        $binding = $route?->defaults[static::RELATIVE] ?? null;
        $model = $route?->defaults[static::RELATIVE_MODEL] ?? null;
        $via = $route?->defaults[static::VIA] ?? null;

        if ($route === null || $binding === null || $via === null || $model === null) {
            return [null, null];
        }

        // The route-model binding may already have substituted a Model (when SubstituteBindings ran); else
        // the parameter is the raw id — resolve it here (findOrFail → the same 404 a stranger parent gets).
        $bound = $route->parameter($binding);
        $relative = $bound instanceof Model ? $bound : $model::query()->findOrFail($bound);

        return [$relative, $this->resolveVia($via)];
    }

    /**
     * Normalise the stamped `VIA` into the two forms the rest of this class already understands — a
     * relation NAME or a Closure — resolving the third, newer form on the way through.
     *
     * That third form is a `#[ParticleRelative]` edge CLASS NAME (api-surface-coherence ticket 50). It
     * exists because a behavioural `via` cannot ride the route defaults as a Closure: `route:cache`
     * serializes defaults and dies on one (ticket 51 §2). A class name serializes fine, and the edge's
     * `public static via()` is resolved back off it here, per request.
     *
     * Resolution happens ONCE, in {@see relativeContext()}, so every downstream reader
     * ({@see relativeBaseQuery()}, {@see newRelativeModel()}) keeps its existing `is_string` /
     * `instanceof Closure` test and is correct without knowing this form exists. In particular
     * `newRelativeModel()` must NOT see a class name: it treats a string as a relation and would call
     * `$parent->{Edge::class}()`.
     *
     * A class-string with no `via()` method falls through as itself. That is not a silent failure — it
     * is the ordinary relation-name path, and a relation named after a class does not exist, so the
     * error names the missing relation at the point of use rather than here.
     */
    protected function resolveVia(string|Closure $via): string|Closure
    {
        if ($via instanceof Closure || ! class_exists($via) || ! method_exists($via, 'via')) {
            return $via;
        }

        return Closure::fromCallable([$via, 'via']);
    }

    /**
     * The list/resolve base query THROUGH the bound relative, or null when there is no relative context.
     * A relation-name `via:` bases on `$relative->{via}()` (Eloquent handles hasMany / hasManyThrough /
     * belongsToMany "through" for free); a closure `via:` applies `($via)($relative, model::query())`.
     */
    protected function relativeBaseQuery(Request $request): mixed
    {
        [$relative, $via] = $this->relativeContext($request);

        if ($relative === null || $via === null) {
            return null;
        }

        if ($via instanceof Closure) {
            $resource = $this->particleResource($request);

            return $via($relative, $this->queryableBacking($resource)->query([]));
        }

        return $relative->{$via}()->getQuery();
    }

    /**
     * A fresh model with its inverse pre-associated to the bound relative, for a relative CREATE — or null
     * when there is no relative, or the `via:` is a scope closure (which cannot auto-associate). The
     * relation-name form uses `$relative->{via}()->make()` so the FK is stamped structurally at save.
     */
    protected function newRelativeModel(Request $request): ?Model
    {
        [$relative, $via] = $this->relativeContext($request);

        if ($relative === null || ! is_string($via)) {
            return null;
        }

        return $relative->{$via}()->make();
    }

    /** Persist through the beam write pipeline: WriteGate authorize → persist → after-hook → emit. */
    protected function writeParticle(ParticleResource $resource, Model $model, mixed $input, mixed $actor): Model
    {
        return $this->writer->write($model, $this->toAttributes($input), $actor, $this->afterHook($resource, $input));
    }

    /**
     * The subject `{id}`, read BY NAME off the route — never trusted from the positional method argument.
     *
     * Laravel splices route parameters into a controller action POSITIONALLY (only class-typed params are
     * matched by type; the rest are `array_values`'d in declaration order). Under a relative mount
     * (`/sellers/{seller}/items/{id}`) the FIRST route parameter is the bound relative, so it — not the
     * subject — lands in `$id`. The failure is silent rather than loud: `findOrFail()` unwraps a bound Model
     * to its key, and the relative base query keeps the result inside the parent's own set, so the request
     * answers **200 with the wrong record** (and `destroy` deletes it). Reading the parameter by name makes
     * the arity of the mount irrelevant — a standalone and a relative mount resolve identically.
     *
     * Falls back to the passed argument when the route carries no `{id}` (an extending controller calling
     * `show()`/`findParticle()` directly, off-route), and unwraps a Model either way.
     */
    protected function subjectId(Request $request, mixed $id): string
    {
        $named = $request->route()?->parameter(static::SUBJECT);

        $subject = $named ?? $id;

        return $subject instanceof Model ? (string) $subject->getKey() : (string) $subject;
    }

    protected function findParticle(ParticleResource $resource, string $id, ?Request $request = null): Model
    {
        // Relative mount (HTTP-02): resolve the `{id}` THROUGH the bound relative when present, so a
        // show/update/destroy can only reach a child hanging off the (authorized) parent — a cross-parent id
        // 404s (never resolves). Absent a relative, `$query` is the unscoped base — today's exact code path.
        $query = ($request !== null ? $this->relativeBaseQuery($request) : null)
            ?? $this->queryableBacking($resource)->query([]);

        // Row-level authorization for subject resolution (ADR-0156 §83): the resource's `scope` closure
        // gates the show/update/destroy `findOrFail`, so a resolve-by-id can never reach a row the caller
        // may not touch (e.g. another user's own-scoped record → 404, not 403-after-load). Mirrors
        // ParticleFrameResourceHandler; null scope ⇒ the unscoped query (every existing resource unchanged).
        if ($resource->scope !== null) {
            $query = ($resource->scope)($query) ?? $query;
        }

        if ($resource->includes !== []) {
            $query->with($resource->includes);
        }

        // A DECLARED route key (`ParticleResource(routeKey: 'slug')`) resolves the `{id}` segment against that
        // column instead of the primary key — and the PK stops resolving entirely, which is the point: one
        // public identifier per resource, never two. Note this branches BELOW the relative base query and the
        // `scope` gate above, deliberately: the lookup inherits both, so a route key need only be unique per
        // PARENT under a relative mount (a product slug unique per seller, the seller slug carrying the single
        // global constraint). null routeKey ⇒ today's exact `findOrFail`, so every existing resource is unchanged.
        if ($resource->routeKey !== null) {
            return $query->where($resource->routeKey, $id)->firstOrFail();
        }

        return $query->findOrFail($id);
    }

    /**
     * The parsed, VALIDATED input: the resource's input DTO via `validateAndCreate` (so its rules — a
     * spatie `rules()` method or inferred from types — run and reject with 422), else the raw request.
     *
     * A validation failure is rendered as the STANDARD Laravel JSON 422 envelope
     * (`{ message, errors: { field: [msg…] } }`, byte-identical to what a FormRequest returns) so a JS
     * client can surface per-field errors and `assertJsonValidationErrors` passes — regardless of the
     * host's `shouldRenderJsonWhen` config, which would otherwise redirect a `web`-group write. We rethrow
     * a `ValidationException` whose `response` is that JSON 422: the host's exception handler renders the
     * carried response verbatim (never the redirect branch, never an app envelope wrapper), so every
     * particle write resource gets the standard shape without an app binding. The success path is untouched.
     */
    protected function parseInput(ParticleResource $resource, Request $request): mixed
    {
        if ($resource->input === null) {
            return $request;
        }

        try {
            return $resource->input::validateAndCreate($request);
        } catch (ValidationException $e) {
            throw new HttpResponseException(
                new JsonResponse(
                    ['message' => $e->getMessage(), 'errors' => $e->errors()],
                    $e->status,
                ),
            );
        }
    }

    /**
     * Map the parsed input to model columns: a DTO's own `toModelAttributes()` (the app convention), else
     * its array form, else the request body snake-cased (minus the PK, which the model/route owns).
     *
     * @return array<string, mixed>
     */
    protected function toAttributes(mixed $input): array
    {
        if (is_object($input) && method_exists($input, 'toModelAttributes')) {
            return $input->toModelAttributes();
        }

        if ($input instanceof Data) {
            return $input->toArray();
        }

        /** @var Request $input */
        $attributes = [];
        foreach ($input->except('id') as $key => $value) {
            $attributes[Str::snake($key)] = $value;
        }

        return $attributes;
    }

    /** The after-persist relation-sync hook bound to this write's input, or null when none is declared. */
    protected function afterHook(ParticleResource $resource, mixed $input): ?Closure
    {
        if ($resource->afterWrite === null) {
            return null;
        }

        return fn (Model $model) => ($resource->afterWrite)($model, $input);
    }

    /**
     * Project a single record to its typed Data and wrap it in the response envelope (`200`, or `201` when
     * `$created`).
     */
    protected function respond(ParticleResource $resource, Model $model, Request $request, bool $created = false): Responsable
    {
        $model->refresh();
        if ($resource->includes !== []) {
            $model->load($resource->includes);
        }

        $ctx = ReadContext::detail($resource->includes, $request->user());
        $data = $this->projectRecord($resource, $model, $ctx, $this->facets($request));

        return $created ? $this->envelope->created($data) : $this->envelope->item($data);
    }

    /**
     * Project one record to its typed Data: the resource's DECLARED Data class when it names one (the
     * extension tier, e.g. `SiloData`), else the hydrator resolves it off beam's `#[ParticleResource]`
     * registry (ADR-0156: the record → Data-class map is read straight from the registry, not a port).
     *
     * Then every package that CONTRIBUTES a slice of this resource's projection folds its slice on
     * ({@see ContributionProjector::apply()}) — the same helper Frame's twin projection path calls, so
     * a contributed key is on the wire identically over both transports. Inert (identity) for a resource
     * nobody contributes to, which is all but a handful estate-wide.
     *
     * ⚠️ This fires on the LIST path too (`index()` maps it over the page), so the value arm is a
     * per-row call site. That is a known, accepted cost of this seam rather than an oversight: ticket 04
     * disposed of it explicitly, and the includes arm — folded upstream in
     * {@see ParticleResourceRegistry::get()} and {@see ParticleListQuery} — is what keeps the slice's
     * RELATIONS off the per-row path.
     *
     * @param  array<string, mixed>  $filters  the opaque facet bag; it is what carries `filter[period]`
     *                                         to a contribution, because the `ReadContext` provably
     *                                         cannot (ticket 05 §A3).
     */
    protected function projectRecord(ParticleResource $resource, Model $model, ReadContext $ctx, array $filters = []): Data
    {
        $data = $resource->project !== null
            ? ($resource->project)($model)
            : ($resource->data !== null
                ? $resource->data::from($model)
                : $this->hydrator->project($model, $ctx));

        return $this->contributions()->apply($resource->key, $data, $model, $ctx, $filters);
    }

    /**
     * The shared contribution fold, resolved off the container so a host (or a test) with no
     * contribution registry bound gets an inert projector rather than a resolution error.
     */
    protected function contributions(): ContributionProjector
    {
        return new ContributionProjector(
            app()->bound(ResourceContributionRegistry::class)
                ? app(ResourceContributionRegistry::class)
                : new ResourceContributionRegistry
        );
    }

    /**
     * The request's opaque `filter[...]` bag — forwarded verbatim to a contribution's arms. Beam does not
     * interpret it; the same bag a {@see \Splicewire\Beam\Particle\Backing\StreamsRecords} backing is
     * already handed.
     *
     * @return array<string, mixed>
     */
    protected function facets(Request $request): array
    {
        return array_filter((array) $request->input('filter', []));
    }
}
