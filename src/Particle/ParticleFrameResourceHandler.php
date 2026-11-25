<?php

namespace Splicewire\Beam\Particle;

use Closure;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use LogicException;
use RuntimeException;
use Schemastud\DataSchemas\Migration\AcceptanceGate;
use Schemastud\Frame\Contracts\ActorAwareFrameResourceHandler;
use Schemastud\Frame\Contracts\FrameResourceHandler;
use Schemastud\Frame\Data\ResourcePageData;
use Schemastud\Frame\Registry\ResourceDefinition;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Agreements\RequirementAdmission;
use Splicewire\Beam\Agreements\RequirementRefused;
use Splicewire\Beam\Authorization\ResourceReadGuard;
use Splicewire\Beam\Filters\ResourceFilterDefinition;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Localization\CodedHttpException;
use Splicewire\Beam\Localization\CodedNotFoundHttpException;
use Splicewire\Beam\Localization\CodedValidationException;
use Splicewire\Beam\Particle\Backing\AcceptsPayloadWrites;
use Splicewire\Beam\Particle\Backing\EnrichesProjectedRecords;
use Splicewire\Beam\Particle\Backing\ProjectsPayload;
use Splicewire\Beam\Particle\Backing\QueriesRecords;
use Splicewire\Beam\Particle\Backing\ResolvesRecord;
use Splicewire\Beam\Particle\Backing\ResourceBacking;
use Splicewire\Beam\Particle\Backing\StreamsRecords;
use Splicewire\Beam\Particle\Backing\SuppliesParticleWriter;
use Splicewire\Beam\Particle\Backing\Unpaged;
use Splicewire\Beam\Particle\Backing\UsesActorOwnership;
use Splicewire\Beam\Particle\Backing\WritesRecords;
use Splicewire\Beam\Particle\Contribution\ContributionProjector;
use Splicewire\Beam\Particle\Contribution\ResourceContributionRegistry;
use Splicewire\Beam\Read\Contracts\ParticleHydrator;
use Splicewire\Beam\Read\ReadContext;
use Splicewire\Beam\Read\ResolvedRecordPublicProjector;
use Splicewire\Beam\Routing\RecordKey;
use Splicewire\Beam\Schema\Contracts\SchemaTargetResolver;
use Splicewire\Beam\Write\ModelAttributeMapper;
use Splicewire\Beam\Write\ParticleWriter;
use Splicewire\Beam\Write\PolicyWriteGate;
use Splicewire\Beam\Write\Stages\RequirementAdmissionStage;
use Splicewire\Beam\Write\WriteNotAuthorized;
use Splicewire\Beam\Write\WriteRequirement;

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
class ParticleFrameResourceHandler implements ActorAwareFrameResourceHandler, FrameResourceHandler
{
    /**
     * Frame's offset-page size, declared ONCE for the queried and the streamed list. laravel-frame names these
     * numbers in no constant: they are inline in `FrameResourceController::paginate()`
     * (`max(1, min(100, (int) $request->query('per_page', 25)))`) and in `ResourceQueryData`'s `per_page`
     * `#[Min(1), Max(100)]`, which refuses out-of-range input before a handler runs.
     */
    private const FRAME_DEFAULT_PER_PAGE = 25;

    private const FRAME_MAX_PER_PAGE = 100;

    private bool $actorBound = false;

    private ?Authenticatable $boundActor = null;

    private ?string $boundRealm = null;

    private ?Request $boundRequest = null;

    /** A server-side consumer supplies its own saved query, retaining the visitor's request context. */
    public function forRequest(Request $request, ?string $realm = null): self
    {
        $bound = $this->forActor($request->user(), $realm);
        $bound->boundRequest = clone $request;

        return $bound;
    }

    public function forActor(?Authenticatable $actor, ?string $realm = null): FrameResourceHandler
    {
        $bound = clone $this;
        $bound->actorBound = true;
        $bound->boundActor = $actor;
        $bound->boundRealm = $realm;

        return $bound;
    }

    protected function contextRequest(?ParticleResource $resource): Request
    {
        if (! $this->actorBound && ($resource?->publicRead !== null || $resource?->publicScope !== null)) {
            throw new LogicException('This Frame resource requires an explicit actor; bind with forActor(), including null for a guest.');
        }
        $request = clone ($this->boundRequest ?? app(Request::class));
        if ($this->actorBound) {
            $request->setUserResolver(fn () => $this->boundActor);
            $route = $request->route();
            $route = $route !== null ? clone $route : new Route(['GET'], '/', fn () => null);
            $route->defaults['realm'] = $this->boundRealm;
            $request->setRouteResolver(fn () => $route);
        }

        return $request;
    }

    public function __construct(
        protected SchemaTargetResolver $targets,
        protected AcceptanceGate $acceptance,
        protected Dispatcher $events,
        protected Gate $gate,
        protected ParticleResourceRegistry $registry,
        protected ParticleHydrator $hydrator,
        protected ScopedIndexQuery $listQuery,
    ) {}

    public function index(ResourceDefinition $definition, array $params): array
    {
        $this->contextRequest($this->resource($definition));
        // A service-backed (union) resource has no single model to query: its list is a MERGE of N
        // sub-sources fused behind a backing that only STREAMS (ADR-0156 §57-58, §83 — widen the
        // Particle to serve one, never keep a bespoke handler). Resolve the backing off the
        // definition and preserve its cursor page in Frame's declared envelope. The backing owns
        // pagination; the resource socket must not slice that page again.
        if ($this->streamsOnly($definition)) {
            return $this->streamedIndex($definition);
        }

        // attention-surfaces 29: a queryable list is paged IN the query, and only that page is projected and
        // enriched. Frame passes an offset envelope through unchanged
        // (laravel-frame FrameResourceController::index(), the `total` branch), so the wire shape is the one
        // Frame's own in-memory slice produced, and an out-of-range page answers no rows with the true total.
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = self::framePerPage($params['per_page'] ?? null);
        $total = 0;
        $rows = $this->readRows($definition, function (Builder $query) use ($page, $perPage, &$total): iterable {
            // Laravel's pagination count drops the ORDER BY, which Postgres refuses beside an aggregate.
            $total = $query->toBase()->getCountForPagination();

            return $total === 0 ? [] : $query->forPage($page, $perPage)->get();
        });

        // ResourcePageData::offset($rows, …)->toArray(), key for key (QueriedFrameIndexPagingTest pins it); built
        // by hand because an enriched row is typed only as `array` (EnrichesProjectedRecords::enrich()).
        return ['data' => $rows, 'perPage' => $perPage, 'total' => $total, 'page' => $page];
    }

    /**
     * The WHOLE scoped list, for a server-side consumer that needs every row (beam-ux's analytics site area
     * counts pages, redirects and health entries from it). Frame never calls this: its port is the paged
     * {@see index()}. Same guard, scoped query, projection and batch enrichment; unbounded by design, so
     * prefer {@see indexWindow()} wherever a window will do.
     *
     * @return list<mixed>
     */
    public function rows(ResourceDefinition $definition): array
    {
        $this->contextRequest($this->resource($definition));
        if ($this->streamsOnly($definition)) {
            throw new LogicException('The whole-list read requires a queryable resource; a streamed backing owns its own pages.');
        }

        return $this->readRows($definition, fn (Builder $query): iterable => $query->get());
    }

    /** Bounded server-side window through the same guard, query, projection and batch enrichment. */
    public function indexWindow(ResourceDefinition $definition, int $limit, int $offset = 0): array
    {
        if ($limit < 1 || $limit > 101 || $offset < 0 || $this->streamsOnly($definition)) {
            throw new LogicException('A binding window requires a queryable resource and a bounded positive limit.');
        }

        return $this->readRows($definition, fn (Builder $query): iterable => $query->offset($offset)->limit($limit)->get());
    }

    /** Frame's page size, floored and capped exactly as Frame's own slice does (see the constants). */
    private static function framePerPage(mixed $requested): int
    {
        return max(1, min(self::FRAME_MAX_PER_PAGE, (int) ($requested ?? self::FRAME_DEFAULT_PER_PAGE)));
    }

    /**
     * The one queried-list read behind {@see index()}, {@see rows()} and {@see indexWindow()}: the guarded,
     * scoped query; then requirement admission (agreements 05: after the read guard the scoped query just
     * ran); then `$fetch` chooses which models to load; then each is projected and the batch enriched.
     *
     * @param  Closure(Builder<Model>): iterable<Model>  $fetch
     * @return list<mixed> each row as {@see EnrichesProjectedRecords::enrich()} returns it
     */
    private function readRows(ResourceDefinition $definition, Closure $fetch): array
    {
        $query = $this->indexQuery($definition);
        if (! $query instanceof Builder) {
            throw new LogicException('A queried list read needs the scoped Eloquent list query.');
        }
        $this->admitRequirements($definition, 'read', null);
        $rows = [];
        foreach ($fetch($query) as $model) {
            $rows[] = $this->projectRead($definition, $model);
        }

        return array_values($this->enrichProjected($definition, $rows));
    }

    /**
     * The list for a resource whose backing only {@see StreamsRecords}: take the request's opaque
     * `filter[…]` bag + cursor/per_page, hand them to the backing, and project
     * each merged item through {@see ScopedIndexQuery::projectListRow()} — the declaration's `project`
     * closure when it declares one, else the resource's read Data class (its spatie magic named
     * constructor, e.g. `ReviewItemResourceData::fromReviewItem`, resolves off the item type). The source
     * owns merge/sort/filter/pagination; Frame only wires it. Read-only by construction
     * (`creatable: false` ⇒ store/update/destroy/show already 405 via {@see assertWritable}).
     *
     * The per-row projection lives on {@see ScopedIndexQuery} and not here because a summary provider
     * showing "the rows this index would list" has to apply the SAME ladder, and re-spelling it there is
     * how the two drift (realm-dashboards 06a review).
     *
     * A cursor page without an invented total, or an offset page containing the whole population
     * for an {@see Unpaged} backing. Frame passes either declared envelope through unchanged.
     *
     * @return array<string, mixed>
     */
    protected function streamedIndex(ResourceDefinition $definition): array
    {
        app(ResourceFilterDefinition::class)->definition($definition->key);
        $resource = $this->resource($definition);
        $request = $this->contextRequest($resource);
        if ($resource !== null) {
            ResourceReadGuard::forApp()->inspectReadFor($resource, $request, $request->user())->authorize();
        }
        abort_if($request->query('filterVariant') !== null, 404);
        $this->admitRequirements($definition, 'read', null);

        // The WHOLE `filter[...]` bag, passed through opaquely — the backing owns its own query
        // semantics and beam does not interpret it. Every backing reads only the keys it knows
        // (review-queue: `source`/`parent_id`/`keywords`; tenants: `period`) and ignores the rest, so
        // forwarding the full bag is additive. perPage floored at 1 to guard a paginator underflow.
        $filters = (array) $request->input('filter', []);
        $cursor = $request->query('cursor');
        $perPage = self::framePerPage($request->query('per_page'));

        $backing = $this->backing($definition, StreamsRecords::class);

        $page = $backing->records($filters, $cursor, $perPage);
        // Capture the backing's coordinate before projection drops any cursor-only fields.
        $nextCursor = $backing instanceof Unpaged ? null : $page->nextCursor()?->encode();
        $rows = collect($page->items())
            ->map(fn (mixed $item) => $this->listQuery->projectListRow($definition, $item)->toArray())
            ->all();

        if ($backing instanceof Unpaged) {
            return ResourcePageData::offset($rows, count($rows), 1, max(1, count($rows)))->toArray();
        }

        return ResourcePageData::cursor($rows, $perPage, $nextCursor)->toArray();
    }

    protected function enrichProjected(ResourceDefinition $definition, array $rows): array
    {
        $resource = $this->resource($definition);
        $backing = $resource?->backing($this->contextRequest($resource));

        return $backing instanceof EnrichesProjectedRecords
            ? $backing->enrich($rows, $this->contextRequest($resource)->user())
            : $rows;
    }

    /**
     * The list query for a Frame index — {@see ScopedIndexQuery::forDefinition()}, the ONE scoped builder
     * this index and beam's default summary provider both read. It used to live here; it was extracted
     * (realm-dashboards ticket 02) so a summary figure and the list it summarizes are provably one read
     * rather than two hand-synced queries — the drift ticket 05 already collapsed once between REST and Frame.
     */
    protected function indexQuery(ResourceDefinition $definition): object
    {
        return $this->listQuery->forDefinition($definition, $this->contextRequest($this->resource($definition)));
    }

    public function show(ResourceDefinition $definition, string $id): array
    {
        $this->contextRequest($this->resource($definition));
        // A stream-only backing resolves ONE record through {@see ResolvesRecord::resolve()} — a
        // payload-resolved detail (the `schemaRef` dereference seam), NOT a model edit
        // projection. A union leaves `showable` at its default (true) and is always detail-capable; a
        // producer that CLOSED it is refused here too — a streams-only resource whose rows are projections
        // of other resources (a realm dashboard) has no detail to serve, and asking its backing for a
        // capability it does not carry would be a 500, not a refusal. Model-backed resources fall through
        // to the `showable`-gated projection below (which, post-F04, serves a read-only browse's detail too).
        if ($this->streamsOnly($definition)) {
            $this->assertWritable($definition, 'show');

            return $this->resolvedShow($definition, $id);
        }

        // A per-record detail (`records/{id}`, show) rides the `showable` gate, INDEPENDENT of `editable`
        // (F04 / ADR-0156 §83 show-independent widening): a read-only resource (no create/edit/delete) can
        // still expose a detail view, and an editable resource always shows. `showable` defaults true
        // (readable ⇒ showable), so only a producer that explicitly closes it 405s here. The gate fires
        // BEFORE the id lookup, so a syntactically invalid id can't leak a 500 through the read path.
        $this->assertWritable($definition, 'show');

        $resource = $this->resource($definition);
        if ($resource !== null) {
            $request = $this->contextRequest($resource);
            ResourceReadGuard::forApp()->inspectReadFor($resource, $request, $request->user())->authorize();
        }

        // A read-only resource has no separate edit shape — project detail through the SAME read projection
        // as its list rows (the resource's `project` closure, e.g. CustomerData::fromModel), so a detail row
        // matches a list row. An editable resource keeps its edit projection (`editData ?? data`) to pre-fill
        // the form.
        $model = $this->find($definition, $id);
        // agreements 05: after the read guard and the scoped lookup; a guest admitted by a Public Read still meets it.
        $this->admitRequirements($definition, 'read', $model);

        // Editable describes the resource, not the guest's permission to see its edit DTO.
        $guest = $this->contextRequest($resource)->user() === null;

        $row = $definition->editable && ! $guest
            ? $this->projectEdit($definition, $model)
            : $this->projectRead($definition, $model);

        return $this->enrichProjected($definition, [$row])[0];
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
        $resource = $this->resource($definition);
        $request = $this->contextRequest($resource);
        if ($resource !== null) {
            ResourceReadGuard::forApp()->bindReadContext($resource, $request);
        }

        // The union-arm discriminator now rides the same opaque bag every other capability takes,
        // rather than a positional argument only unions ever used. `source` stays a detail-route query
        // param, so a caller's URL is unchanged; it is merged into the filters the backing already reads.
        $filters = (array) $request->input('filter', []);
        $filters['source'] = (string) $request->query('source', '');

        $resolved = $this->backing($definition, ResolvesRecord::class)->resolve($id, $filters);

        if ($resolved === null) {
            throw new CodedNotFoundHttpException(ParticleRequestError::RecordNotFound, ['resource' => $definition->key]);
        }
        $this->admitRequirements($definition, 'read', null);

        return ResolvedRecordPublicProjector::project($resource, $resolved, $request);
    }

    public function store(ResourceDefinition $definition, array $input): array
    {
        $this->contextRequest($this->resource($definition));
        $this->assertWritable($definition, 'store');

        $resource = $this->resource($definition);
        $modelClass = $definition->model;
        $model = $resource === null ? new $modelClass : $this->backing($definition, WritesRecords::class)->newRecord()->model();

        if (($backing = $this->resource($definition)?->backing($this->contextRequest($this->resource($definition)))) instanceof AcceptsPayloadWrites) {
            $backing::assertInput($input);
        }
        $parsed = $this->parseInput($definition, $input);
        $this->prepare($definition, $model, $parsed);
        $model = $this->writeRecord($definition, $model, $parsed, $input);

        return $this->projectRead($definition, $model);
    }

    public function update(ResourceDefinition $definition, string $id, array $input): array
    {
        $this->contextRequest($this->resource($definition));
        $this->assertWritable($definition, 'update');

        $model = $this->find($definition, $id);

        // projectEdit() supplies Frame's row identity alongside the declared editable fields.
        // It may round-trip, but it must never select another record or enter the write DTO.
        if (array_key_exists('id', $input)) {
            if ((! is_string($input['id']) && ! is_int($input['id'])) || (string) $input['id'] !== $id) {
                throw CodedValidationException::for('id', ParticleRequestError::IdMismatch);
            }

            unset($input['id']);
        }

        if (($backing = $this->resource($definition)?->backing($this->contextRequest($this->resource($definition)))) instanceof AcceptsPayloadWrites) {
            $backing::assertInput($input);
        }
        $parsed = $this->parseInput($definition, $input);
        $this->prepare($definition, $model, $parsed);
        $model = $this->writeRecord($definition, $model, $parsed, $input);

        return $this->projectRead($definition, $model->refresh());
    }

    public function destroy(ResourceDefinition $definition, string $id): void
    {
        $this->contextRequest($this->resource($definition));
        $this->assertWritable($definition, 'destroy');

        $model = $this->find($definition, $id);
        $resource = $this->resource($definition);
        $backing = $resource?->backing($this->contextRequest($resource));
        if ($backing instanceof UsesActorOwnership
            && (! $resource->policy || ! $this->gate->forUser($this->contextRequest($resource)->user())->allows($resource->policy, $model))) {
            throw WriteNotAuthorized::for($model);
        }
        // agreements 05: after the ownership check, before anything is removed.
        $this->admitRequirements($definition, 'write', $model);
        $model->delete();
    }

    // ---- internals -----------------------------------------------------------------------------------

    /**
     * Persist through this resource's write pipeline, carrying the `{key}:write` Requirement question so the
     * pipeline's {@see RequirementAdmissionStage} asks it right after the write gate (agreements 05). A refusal leaves as
     * an `HttpResponseException` with the declared 428 body.
     *
     * @param  array<string, mixed>  $input  the raw submitted input, read for agreement members
     */
    protected function writeRecord(ResourceDefinition $definition, Model $model, mixed $parsed, array $input): Model
    {
        $requirement = new WriteRequirement(
            ParticleResource::behaviorFor($definition->key, 'write'),
            $model->exists ? $model : null,
            $this->submittedRequirementMembers($definition, $input),
            $this->actorBound ? $this->boundActor : auth()->user(),
            is_string($this->resource($definition)?->input) ? $this->resource($definition)->input : null,
        );

        try {
            return $this->writer($definition)->requiring($requirement)->write(
                $model,
                $this->writePayload($definition, $parsed),
                $this->actorBound ? $this->boundActor : auth()->user(),
                $this->afterHook($definition, $parsed),
            );
        } catch (RequirementRefused $refused) {
            throw new HttpResponseException($refused->toResponse());
        }
    }

    /**
     * agreements 05 (ADR-0247 amendment): ask the requirements plane about a read or delete of this resource, after the
     * verb's own guards, and refuse with the declared 428 body.
     *
     * @param  'read'|'write'  $side
     *
     * @throws HttpResponseException 428 with {@see \Splicewire\Beam\Agreements\Data\RequirementRefusalData}
     */
    protected function admitRequirements(ResourceDefinition $definition, string $side, mixed $subject): void
    {
        $refusal = app(RequirementAdmission::class)->inspect(
            $this->contextRequest($this->resource($definition))->user(),
            ParticleResource::behaviorFor($definition->key, $side),
            $subject,
            $this->submittedRequirementMembers($definition, $this->contextRequest($this->resource($definition))->all()),
            is_string($this->resource($definition)?->input) ? $this->resource($definition)->input : null,
        );

        if ($refusal !== null) {
            throw new HttpResponseException(response()->json($refusal->toArray(), 428));
        }
    }

    /** @return array<string, \Splicewire\Beam\Agreements\Data\AgreementMemberData> */
    protected function submittedRequirementMembers(ResourceDefinition $definition, array $input): array
    {
        $declared = $this->resource($definition)?->input;

        return app(RequirementAdmission::class)->submittedMembers(is_string($declared) ? $declared : null, $input);
    }

    protected function find(ResourceDefinition $definition, string $id): Model
    {
        if ($definition->model !== null) {
            RecordKey::assertValidFor($definition->model, $id);
        }

        return $this->query($definition)->findOrFail($id);
    }

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
            throw new CodedHttpException(405, ParticleRequestError::ActionNotSupported, ['action' => $action, 'resource' => $definition->key]);
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

        return $resource !== null && ! $resource->backing($this->contextRequest($resource)) instanceof QueriesRecords;
    }

    /**
     * This resource's backing, asserted to carry the given capability.
     *
     * @template T of ResourceBacking
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

        $backing = $resource->backing($this->contextRequest($resource));

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
     *
     * Delegated to {@see ScopedIndexQuery::resource()} rather than repeated: the two bodies were
     * byte-identical over the same registry, and the helper this handler already holds is the one place
     * that lookup is spelled.
     */
    protected function resource(ResourceDefinition $definition): ?ParticleResource
    {
        return $this->listQuery->resource($definition);
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

        $query = $this->scoped($query, $resource);

        return $resource === null
            ? $query
            : app(ParticleListQuery::class)->publicScoped($query, $resource, $this->contextRequest($resource));
    }

    /**
     * Apply the declaration's row-level `scope` closure, with the resolved editor realm as its second
     * argument — {@see ScopedIndexQuery::scoped()}, so the subject-resolution base ({@see query()}) and
     * the non-filterable list base gate identically.
     */
    protected function scoped(Builder $query, ?ParticleResource $resource): Builder
    {
        return $this->listQuery->scoped($query, $resource, $this->contextRequest($resource));
    }

    /**
     * A {@see ParticleWriter} bound to this resource's write gate (its declared `policy`, or permissive
     * when none). Built per resource so the gate carries the right policy.
     */
    protected function writer(ResourceDefinition $definition): ParticleWriter
    {
        $gate = new PolicyWriteGate($this->actorBound ? $this->gate->forUser($this->boundActor) : $this->gate, $definition->policy);
        $backing = $this->resource($definition)?->backing($this->contextRequest($this->resource($definition)));
        if ($backing instanceof SuppliesParticleWriter) {
            return $backing->writer($gate);
        }

        return new ParticleWriter(
            $gate,
            $this->targets,
            $this->acceptance,
            $this->events,
        );
    }

    /** Keep JSON business names; an explicit model backing still uses the model-attribute mapper.
     * @return array<string, mixed>
     */
    protected function writePayload(ResourceDefinition $definition, mixed $input): array
    {
        $resource = $this->resource($definition);
        $backing = $resource?->backing($this->contextRequest($resource));

        return $backing instanceof AcceptsPayloadWrites
            ? $backing::payload($input) : $this->toAttributes($input);
    }

    protected function prepare(ResourceDefinition $definition, Model $model, mixed $input): void
    {
        $resource = $this->resource($definition);
        if ($resource?->prepare !== null) {
            ($resource->prepare)($model, $input, $this->actorBound ? $this->boundActor : auth()->user());
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
     *
     * `input: false` (api-surface-coherence 69) means the resource accepts nothing, so Frame's transport
     * hands the writer an EMPTY payload rather than the raw array — the same fact `ParticleController`
     * enforces with a 422 over HTTP, spelled for a transport whose caller is the editor rather than an API
     * client. Both leave the resource's `prepare()` hook as the only thing that fills the record.
     */
    protected function parseInput(ResourceDefinition $definition, array $input): mixed
    {
        $resource = $this->resource($definition);

        if ($resource?->input === false) {
            return [];
        }

        return $resource?->input !== null ? $resource->input::validateAndCreate($input) : $input;
    }

    /**
     * Map the parsed input to model columns — delegated to {@see ModelAttributeMapper}, the ONE mapper
     * this transport shares with {@see ParticleController} and the hosts' Frame handlers. This method was
     * one of four near-identical copies of that map; this one's spelling is the one they collapsed onto.
     *
     * @return array<string, mixed>
     */
    protected function toAttributes(mixed $input): array
    {
        return ModelAttributeMapper::map($input);
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

        // Match REST: a guest receives the narrower Public Read DTO. When it is
        // the normal Data class, retain the resource's custom projection.
        $guestPublicRead = ReadContext::projectsPublic($this->contextRequest($resource), $resource?->key)
            && $resource?->publicRead !== null
            && $resource->publicRead !== $resource->data;

        if ($guestPublicRead) {
            $data = $resource->publicRead::from($model);
        } elseif ($resource?->project !== null) {
            $data = ($resource->project)($model);
        } else {
            /** @var class-string<Data> $dataClass */
            $dataClass = $definition->data;
            $data = $resource?->backing($this->contextRequest($resource)) instanceof ProjectsPayload
                ? $resource->backing($this->contextRequest($resource))::project($resource, $model, $dataClass)
                : $dataClass::from($model);
        }

        $context = ReadContext::fromRequest($this->contextRequest($resource));
        if ($context?->publicProjection && $context->actor !== null) {
            // An actor-sensitive contribution must not widen a denied reader's public projection.
            return $this->withId($data->toArray(), $model);
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
        $request = $this->contextRequest($resource);
        $filters = (array) $request->input('filter', []);

        return $projector->apply(
            $key,
            $data,
            $model,
            ReadContext::detail($resource?->includes ?? [], $this->actorBound ? $this->boundActor : auth()->user()),
            $filters,
        );
    }

    protected function projectEdit(ResourceDefinition $definition, Model $model): array
    {
        /** @var class-string<Data> $editClass */
        $editClass = $definition->editData ?? $definition->data;

        $resource = $this->resource($definition);
        $data = $resource?->backing($this->contextRequest($resource)) instanceof ProjectsPayload
            ? $resource->backing($this->contextRequest($resource))::project($resource, $model, $editClass)
            : $editClass::from($model);

        return $this->withId($data->toArray(), $model);
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
