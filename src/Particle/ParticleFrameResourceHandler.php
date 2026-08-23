<?php

namespace Splicewire\Beam\Particle;

use Closure;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;
use Schemastud\DataSchemas\Migration\AcceptanceGate;
use Schemastud\Frame\Contracts\FrameResourceHandler;
use Schemastud\Frame\Registry\ResourceDefinition;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Particle\Backing\QueriesRecords;
use Splicewire\Beam\Particle\Backing\ResolvesRecord;
use Splicewire\Beam\Particle\Backing\StreamsRecords;
use Splicewire\Beam\Particle\Contribution\ContributionProjector;
use Splicewire\Beam\Particle\Contribution\ResourceContributionRegistry;
use Splicewire\Beam\Read\Contracts\ParticleHydrator;
use Splicewire\Beam\Read\ReadContext;
use Splicewire\Beam\Schema\Contracts\SchemaTargetResolver;
use Splicewire\Beam\Write\ParticleWriter;
use Splicewire\Beam\Write\PolicyWriteGate;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The **one** beam-native implementation of Frame's {@see FrameResourceHandler} port (ADR-0156) — it
 * replaces the whole `laravel-beam-frame` handler layer: the 18 bespoke `…ResourceHandler` classes AND
 * the `DefaultResourceHandler`. Legal now that `beam → frame`: beam may implement Frame's contract
 * directly, so the diamond-bottom bridge package is no longer needed.
 *
 * Every Frame-served resource rides this: it runs the canonical CRUD through the beam seams — the
 * {@see ParticleWriter} write pipeline (validate→authorize→persist→emit) and a Data-class read
 * projection — so a Frame edit and a `ParticleController` REST call share ONE runtime. A resource that
 * declares only its `#[ParticleResource]` manifest works off the {@see ResourceDefinition}
 * alone (the old zero-glue `DefaultResourceHandler` archetype); a resource that also registers a
 * {@see ParticleResource} under its key gets its enrichment —
 * `includes` eager-loads, a `project` custom projector, `prepare`/`afterWrite` hooks, and input DTO
 * validation — applied identically to the REST transport.
 *
 * Authorization rides {@see PolicyWriteGate}: permissive when the resource declares no policy (the Frame
 * route's auth/realm middleware is the gate), delegating to the declared `policy` ability otherwise.
 */
class ParticleFrameResourceHandler implements FrameResourceHandler
{
    public function __construct(
        protected SchemaTargetResolver $targets,
        protected AcceptanceGate $acceptance,
        protected Dispatcher $events,
        protected Gate $gate,
        protected ParticleResourceRegistry $registry,
        protected ParticleHydrator $hydrator,
    ) {}

    public function index(ResourceDefinition $definition, array $params): array
    {
        // A service-backed (union) resource has no single model to query: its list is a MERGE of N
        // sub-sources fused behind a backing that only STREAMS (ADR-0156 §57-58, §83 — widen the
        // Particle to serve one, never keep a bespoke handler). Resolve the backing off the
        // definition and flatten its paginated page to the flat row array this handler returns (the
        // Frame socket wraps it into the `{data,…}` envelope). Model-backed resources are unaffected.
        if ($this->streamsOnly($definition)) {
            return $this->streamedIndex($definition);
        }

        $query = $this->indexQuery($definition);

        return $query
            ->get()
            ->map(fn (Model $model) => $this->projectRead($definition, $model))
            ->all();
    }

    /**
     * The list for a resource whose backing only {@see StreamsRecords}: take the request's opaque
     * `filter[…]` bag + cursor/perPage, hand them to the backing, and project
     * each merged item through the resource's read Data class (its spatie magic named constructor, e.g.
     * `ReviewItemResourceData::fromReviewItem`, resolves off the item type). The source owns merge/sort/
     * filter/pagination; Frame only wires it. Read-only by construction (`creatable: false` ⇒ store/
     * update/destroy/show already 405 via {@see assertWritable}).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function streamedIndex(ResourceDefinition $definition): array
    {
        $request = app(Request::class);

        /** @var class-string<Data> $dataClass */
        $dataClass = $definition->data;

        // The WHOLE `filter[...]` bag, passed through opaquely — the backing owns its own query
        // semantics and beam does not interpret it. Every backing reads only the keys it knows
        // (review-queue: `source`/`parent_id`/`keywords`; tenants: `period`) and ignores the rest, so
        // forwarding the full bag is additive. perPage floored at 1 to guard a paginator underflow.
        $filters = array_filter((array) $request->input('filter', []));
        $cursor = $request->query('cursor');
        $perPage = max(1, (int) $request->integer('perPage', 25));

        $backing = $this->backing($definition, StreamsRecords::class);

        return collect($backing->records($filters, $cursor, $perPage)->items())
            ->map(fn (mixed $item) => ($item instanceof Data ? $item : $dataClass::from($item))->toArray())
            ->all();
    }

    /**
     * The list query for a Frame index. A registered, `filterable` {@see ParticleResource} rides the
     * data-filters builder ({@see ParticleHydrator::query}) — the SAME owner-scoped, `filter[...]`-aware,
     * saved-filter-capable query the REST {@see ParticleController::index}
     * uses — so a Frame list and a REST list are one read (a pinned `filter[circuitId]` and per-caller
     * row-scoping hold in the editor exactly as they do over REST). A manifest-only resource, a
     * non-filterable one, or a host whose hydrator does not compose queries falls back to the plain
     * includes-eager-loaded query, ordered by the declaration's default sort.
     *
     * That fallback rides {@see ParticleListQuery}, the same builder the REST transport's
     * {@see ParticleController::defaultSortedQuery} calls. Until ticket 05 this method hardcoded
     * `orderByDesc('created_at')` here, silently ignoring the `#[Sortable(default: true)]` attribute the
     * REST twin reads and calls "the SINGLE source of truth for a resource's default order" — so the two
     * transports ordered the same declaration's list differently whenever it declared one.
     */
    protected function indexQuery(ResourceDefinition $definition): object
    {
        $resource = $this->resource($definition);

        if ($resource !== null && $resource->filterable) {
            try {
                return $this->hydrator->query(
                    $resource->key,
                    ReadContext::list($resource->includes, auth()->user()),
                );
            } catch (\LogicException) {
                // The degenerate beam-core reader does not compose queries — fall through to the plain query.
            }
        }

        // A manifest-only resource (no registered ParticleResource) has no declaration to read includes
        // or a default sort off — it keeps the framework default, which is what `created_at desc` was.
        if ($resource === null) {
            return $definition->model::query()->latest();
        }

        // The facet bag rides along for the same reason the REST twin forwards it: a CONTRIBUTED include
        // may be request-parameterized (a constrained eager-load). Frame does not interpret `filter[...]`
        // on a non-filterable resource — it never did — but uninterpreted is not the same as absent, and
        // dropping it here would leave the two transports honouring `filter[period]` differently, which
        // is precisely the drift ticket 05 collapsed these queries to stop.
        $filters = array_filter((array) app(Request::class)->input('filter', []));

        return $this->scoped((new ParticleListQuery)->forList($resource, $filters), $resource);
    }

    public function show(ResourceDefinition $definition, string $id): array
    {
        // A stream-only backing resolves ONE record through {@see ResolvesRecord::resolve()} — a
        // payload-resolved detail (the `schemaRef` dereference seam), NOT a model edit
        // projection. This runs BEFORE the `assertWritable` guard: a union is always detail-capable
        // regardless of its (never-set) `showable`. Model-backed resources fall through to the `showable`-
        // gated projection below (which, post-F04, serves a read-only browse's detail too).
        if ($this->streamsOnly($definition)) {
            return $this->resolvedShow($definition, $id);
        }

        // A per-record detail (`records/{id}`, show) rides the `showable` gate, INDEPENDENT of `editable`
        // (F04 / ADR-0156 §83 show-independent widening): a read-only resource (no create/edit/delete) can
        // still expose a detail view, and an editable resource always shows. `showable` defaults true
        // (readable ⇒ showable), so only a producer that explicitly closes it 405s here. The gate fires
        // BEFORE the id lookup, so a syntactically invalid id can't leak a 500 through the read path.
        $this->assertWritable($definition, 'show');

        // A read-only resource has no separate edit shape — project detail through the SAME read projection
        // as its list rows (the resource's `project` closure, e.g. CustomerData::fromModel), so a detail row
        // matches a list row. An editable resource keeps its edit projection (`editData ?? data`) to pre-fill
        // the form.
        $model = $this->query($definition)->findOrFail($id);

        return $definition->editable
            ? $this->projectEdit($definition, $model)
            : $this->projectRead($definition, $model);
    }

    /**
     * Detail for a service-backed union resource: keyed by (source, id) — the same row id can exist under
     * either arm, so the `source` discriminator (a detail-route query param) disambiguates. The resolved
     * item's envelope is merged with the payload-resolved `schemaRef`; a missing item 404s.
     *
     * @return array<string, mixed>
     */
    protected function resolvedShow(ResourceDefinition $definition, string $id): array
    {
        $request = app(Request::class);

        // The union-arm discriminator now rides the same opaque bag every other capability takes,
        // rather than a positional argument only unions ever used. `source` stays a detail-route query
        // param, so a caller's URL is unchanged; it is merged into the filters the backing already reads.
        $filters = array_filter((array) $request->input('filter', []));
        $filters['source'] = (string) $request->query('source', '');

        $resolved = $this->backing($definition, ResolvesRecord::class)->resolve($id, $filters);

        abort_if($resolved === null, 404, "No '{$definition->key}' record found.");

        return array_merge(
            $resolved->record->toArray(),
            ['schemaRef' => $resolved->schemaRef],
        );
    }

    public function store(ResourceDefinition $definition, array $input): array
    {
        $this->assertWritable($definition, 'store');

        $modelClass = $definition->model;
        $model = new $modelClass;

        $parsed = $this->parseInput($definition, $input);
        $this->prepare($definition, $model, $parsed);
        $this->writer($definition)->write($model, $this->toAttributes($parsed), auth()->user(), $this->afterHook($definition, $parsed));

        return $this->projectRead($definition, $model);
    }

    public function update(ResourceDefinition $definition, string $id, array $input): array
    {
        $this->assertWritable($definition, 'update');

        $model = $this->query($definition)->findOrFail($id);

        $parsed = $this->parseInput($definition, $input);
        $this->prepare($definition, $model, $parsed);
        $this->writer($definition)->write($model, $this->toAttributes($parsed), auth()->user(), $this->afterHook($definition, $parsed));

        return $this->projectRead($definition, $model->refresh());
    }

    public function destroy(ResourceDefinition $definition, string $id): void
    {
        $this->assertWritable($definition, 'destroy');

        $this->query($definition)->findOrFail($id)->delete();
    }

    // ---- internals -----------------------------------------------------------------------------------

    /**
     * Refuse a non-read verb on a resource whose {@see ResourceDefinition} does not permit it — a
     * read-only surface (declared `readOnly: true` on a `#[ParticleResource]`, or a service-backed union)
     * is a machine-authored LIST/inspection surface, so store/update/destroy
     * AND the per-record `show` are genuinely unavailable, not merely unauthorized (ADR-0156 §83 read-only
     * widening). Renders as HTTP 405 — the same refusal the retired bespoke read-only handlers raised (they
     * were list-only: they threw on `show` too) — so the Frame frontend gate and the API agree.
     *
     * The verb families ride INDEPENDENT gates (ADR-0156 §83 / F04), each defaulting so any resource that
     * never sets them is unchanged:
     *  - `store` → `creatable`;
     *  - `update` (in-place edit) → `editable` — so a create-and-delete-but-not-edit resource
     *    (invitations: sent + revoked, never edited) 405s update while `store`/`destroy` work;
     *  - `destroy` → `deletable` — so a prune-but-not-create/edit list (fragments) 405s store/update
     *    while DELETE works;
     *  - `show` (per-record detail) → `showable`, INDEPENDENT of `editable` (F04 show-independent widening),
     *    defaulting true (readable ⇒ showable) — so a read-only resource (no create/edit/delete) still
     *    serves a detail view. A producer closes it explicitly to make a resource genuinely list-only.
     */
    protected function assertWritable(ResourceDefinition $definition, string $action): void
    {
        $permitted = match ($action) {
            'destroy' => $definition->deletable,
            'show' => $definition->showable,
            'update' => $definition->editable,
            default => $definition->creatable,
        };

        if (! $permitted) {
            throw new HttpException(
                405,
                "The '{$action}' action is not supported for the '{$definition->key}' frame resource."
            );
        }
    }

    /**
     * Does this resource's backing yield only a finished PAGE, never a composable query?
     *
     * The successor to `$definition->source !== null`, and it asks the honest question. The old test
     * read a class-string field that existed solely to mean "not a plain model" — a discriminator
     * standing in for a type test. This IS the type test: a backing that cannot hand back a `Builder`
     * cannot be served by the model paths (subject resolution, the scope closure, `findOrFail`), so it
     * takes the streamed path instead.
     */
    protected function streamsOnly(ResourceDefinition $definition): bool
    {
        $resource = $this->resource($definition);

        return $resource !== null && ! $resource->backing() instanceof QueriesRecords;
    }

    /**
     * This resource's backing, asserted to carry the given capability.
     *
     * @template T of \Splicewire\Beam\Particle\Backing\ResourceBacking
     *
     * @param  class-string<T>  $capability
     * @return T
     */
    protected function backing(ResourceDefinition $definition, string $capability): object
    {
        $resource = $this->resource($definition);

        if ($resource === null) {
            throw new RuntimeException(
                "Resource [{$definition->key}] has no registered declaration, so it has no backing."
            );
        }

        $backing = $resource->backing();

        if (! $backing instanceof $capability) {
            throw new RuntimeException(
                "Resource [{$definition->key}]'s backing [".$backing::class."] does not implement {$capability}."
            );
        }

        return $backing;
    }

    /**
     * The registered particle declaration for this resource key, if any — carries the enrichment
     * ({@see ParticleResource::$includes} / `$project` / `$prepare` / `$afterWrite` / `$input`). Null for a
     * manifest-only resource (the zero-glue default).
     */
    protected function resource(ResourceDefinition $definition): ?ParticleResource
    {
        return $this->registry->has($definition->key) ? $this->registry->get($definition->key) : null;
    }

    /**
     * A base query for the resource's model, eager-loading any declared includes. It is the
     * SUBJECT-RESOLUTION base for `show`/`update`/`destroy` (`findOrFail($id)`), so a declared `scope`
     * closure (ADR-0156 §83 mutation-scope widening) is applied here: it row-scopes the reachable set so a
     * Frame edit/revoke cannot resolve a row the caller may not touch (e.g. another user's central Sanctum
     * token). Default (no `scope`) ⇒ the unscoped `model::query()` — every existing resource is unchanged.
     *
     * The resolved editor realm rides along as `$scope`'s second arg (the same `route()->defaults['realm']`
     * seam a host's Frame manifest controller reads to resolve nav context) — a shared resource can vary
     * its row-level scope by realm (e.g. "My Teams" vs "Members" over the same table). Beam depends DOWN
     * on nothing app-namespaced, so this stays prose, not a `@see` reference to a host class.
     */
    protected function query(ResourceDefinition $definition)
    {
        $resource = $this->resource($definition);

        $query = $this->backing($definition, QueriesRecords::class)->query([]);

        $includes = $resource?->includes ?? [];
        if ($includes !== []) {
            $query->with($includes);
        }

        return $this->scoped($query, $resource);
    }

    /**
     * Apply the declaration's row-level `scope` closure, with the resolved editor realm as its second
     * argument. Extracted so the subject-resolution base ({@see query()}) and the non-filterable list base
     * ({@see indexQuery()}) gate identically — ticket 05 split the two queries apart, and an authorization
     * gate applied in only one of them is exactly the drift that split caused elsewhere.
     *
     * Null resource, or a declaration with no `scope`, returns the query untouched.
     */
    protected function scoped(Builder $query, ?ParticleResource $resource): Builder
    {
        if ($resource?->scope === null) {
            return $query;
        }

        $realm = app(Request::class)->route()?->defaults['realm'] ?? null;

        return ($resource->scope)($query, $realm) ?? $query;
    }

    /**
     * A {@see ParticleWriter} bound to this resource's write gate (its declared `policy`, or permissive
     * when none). Built per resource so the gate carries the right policy.
     */
    protected function writer(ResourceDefinition $definition): ParticleWriter
    {
        return new ParticleWriter(
            new PolicyWriteGate($this->gate, $definition->policy),
            $this->targets,
            $this->acceptance,
            $this->events,
        );
    }

    protected function prepare(ResourceDefinition $definition, Model $model, mixed $input): void
    {
        $resource = $this->resource($definition);
        if ($resource?->prepare !== null) {
            ($resource->prepare)($model, $input, auth()->user());
        }
    }

    /** The after-persist relation-sync hook bound to this write's input, or null when none is declared. */
    protected function afterHook(ResourceDefinition $definition, mixed $input): ?Closure
    {
        $resource = $this->resource($definition);
        if ($resource?->afterWrite === null) {
            return null;
        }

        return fn (Model $model) => ($resource->afterWrite)($model, $input);
    }

    /**
     * The parsed, VALIDATED input: the resource's input DTO via `validateAndCreate` (its rules run and
     * reject with 422), else the raw input array.
     */
    protected function parseInput(ResourceDefinition $definition, array $input): mixed
    {
        $resource = $this->resource($definition);

        return $resource?->input !== null ? $resource->input::validateAndCreate($input) : $input;
    }

    /**
     * Map the parsed input to model columns: a DTO's own `toModelAttributes()` (the app convention), else
     * its array form, else the input array snake-cased (minus the PK — a create mints its own, an update
     * is keyed by the route id).
     *
     * @return array<string, mixed>
     */
    protected function toAttributes(mixed $input): array
    {
        if (is_object($input) && method_exists($input, 'toModelAttributes')) {
            return $input->toModelAttributes();
        }

        if ($input instanceof Data) {
            $input = $input->toArray();
        }

        $attributes = [];
        foreach ((array) $input as $key => $value) {
            if ($key === 'id') {
                continue;
            }
            $attributes[Str::snake($key)] = $value;
        }

        return $attributes;
    }

    /**
     * Project one record through the resource's READ shape, then fold on every contributed slice.
     *
     * This is Frame's half of ticket 04 §A4's TWO application points — the twin of
     * {@see ParticleController::projectRecord()}. Both call the ONE
     * shared {@see ContributionProjector}, deliberately rather than each growing its own fold: this map
     * has already watched these two transports drift apart once, when each owned its own list base query
     * and each implemented exactly the half the other was missing (ticket 05).
     *
     * The fold happens on the `Data` BEFORE `toArray()`, because `additional()` is a Data-level append —
     * folding into the array afterwards would work by coincidence and diverge the moment a contributed
     * slice needed its own serialization.
     */
    protected function projectRead(ResourceDefinition $definition, Model $model): array
    {
        $resource = $this->resource($definition);

        if ($resource?->project !== null) {
            $data = ($resource->project)($model);
        } else {
            /** @var class-string<Data> $dataClass */
            $dataClass = $definition->data;
            $data = $dataClass::from($model);
        }

        return $this->withId($this->contributed($definition->key, $data, $model)->toArray(), $model);
    }

    /**
     * Fold contributed slices onto a projected row. Resolved off the container so a host with no
     * contribution registry bound gets an inert projector rather than a resolution error.
     *
     * The facet bag is read from the live request — the same `filter[...]` passthrough
     * {@see streamedIndex()} already forwards to a streaming backing, and what carries `filter[period]`
     * to a contribution (the `ReadContext` provably cannot — ticket 05 §A3).
     */
    protected function contributed(string $key, Data $data, Model $model): Data
    {
        $projector = new ContributionProjector(
            app()->bound(ResourceContributionRegistry::class)
                ? app(ResourceContributionRegistry::class)
                : new ResourceContributionRegistry
        );

        $resource = $this->registry->has($key) ? $this->registry->get($key) : null;
        $filters = array_filter((array) app(Request::class)->input('filter', []));

        return $projector->apply(
            $key,
            $data,
            $model,
            ReadContext::detail($resource?->includes ?? [], auth()->user()),
            $filters,
        );
    }

    protected function projectEdit(ResourceDefinition $definition, Model $model): array
    {
        /** @var class-string<Data> $editClass */
        $editClass = $definition->editData ?? $definition->data;

        return $this->withId($editClass::from($model)->toArray(), $model);
    }

    /**
     * Ensure the row carries an `id` even when the projection omits the PK — Frame keys every list/edit
     * row by `id`.
     */
    protected function withId(array $row, Model $model): array
    {
        if (! array_key_exists('id', $row) || $row['id'] === null) {
            $row['id'] = $model->getKey();
        }

        return $row;
    }
}
