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

    public const PER_PAGE = 'per_page';

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

        // Relative mount (HTTP-02): when the route bound a relative, the index is a listing THROUGH it
        // (`$relative->{via}()` / the scope closure) instead of `model::query()` — the child rows a caller
        // sees are exactly the ones hanging off the (already-authorized) parent. Absent a relative, this is
        // null and the standalone path below is byte-for-byte today's code.
        $relativeQuery = $this->relativeBaseQuery($request);

        $query = $resource->filterable
            ? $this->hydrator->query($resource->key, $ctx)
            : ($relativeQuery ?? $this->defaultSortedQuery($resource));

        // Row-level authorization for the non-filterable list (ADR-0156 §83): a `filterable:false` resource
        // has no data-filters query to gate its index, so its owner/inverse `scope` closure is the ONLY read
        // guard — without it the list would return every row across all callers. Applied here (not for the
        // filterable path, whose data-filters query is its own gate). Mirrors ParticleFrameResourceHandler.
        if (! $resource->filterable && $resource->scope !== null) {
            $query = ($resource->scope)($query) ?? $query;
        }

        $page = $query->paginate($request->integer(self::PER_PAGE, $resource->perPage), ['*'], self::PAGE);
        $page->through(fn (Model $record) => $this->projectRecord($resource, $record, $ctx));

        return $this->envelope->paginated($page);
    }

    /**
     * The base query for a NON-filterable index: the declaration's `includes` eager-loaded, ordered by
     * its declared default sort.
     *
     * Both axes live in {@see ParticleListQuery}, which the Frame transport calls too. Until ticket 05
     * this method built its own query and applied ONLY the sort — so a non-filterable REST list lazy-
     * loaded every declared include per row, while the Frame transport eager-loaded them and hardcoded
     * `created_at desc` instead. Two transports, one declaration, each missing the half the other had.
     */
    protected function defaultSortedQuery(ParticleResource $resource): Builder
    {
        return (new ParticleListQuery)->forList($resource);
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
        // Absent a relative, this is a plain `new $resource->model` — today's exact code path.
        $model = $this->newRelativeModel($request) ?? new $resource->model;
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

        return [$relative, $via];
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

            return $via($relative, $resource->model::query());
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
        $query = ($request !== null ? $this->relativeBaseQuery($request) : null) ?? $resource->model::query();

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
        $data = $this->projectRecord($resource, $model, $ctx);

        return $created ? $this->envelope->created($data) : $this->envelope->item($data);
    }

    /**
     * Project one record to its typed Data: the resource's DECLARED Data class when it names one (the
     * extension tier, e.g. `SiloData`), else the hydrator resolves it off beam's `#[ParticleResource]`
     * registry (ADR-0156: the record → Data-class map is read straight from the registry, not a port).
     */
    protected function projectRecord(ParticleResource $resource, Model $model, ReadContext $ctx): Data
    {
        if ($resource->project !== null) {
            return ($resource->project)($model);
        }

        return $resource->data !== null
            ? $resource->data::from($model)
            : $this->hydrator->project($model, $ctx);
    }
}
