<?php

declare(strict_types=1);

namespace Splicewire\Beam\Http\Particle;

use Closure;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Http\Contracts\ResponseEnvelope;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Read\Contracts\ParticleHydrator;
use Splicewire\Beam\Read\ReadContext;
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
 * The ONE app-coupling this surface used to carry — the app's `App\Data\ResponseBody` — is hoisted behind
 * the {@see ResponseEnvelope} port (ADR-0116): beam-core builds its `{ data: … }` JSON through the seam,
 * and a host binds its own envelope adapter so its responses stay byte-compatible.
 */
class ParticleController extends Controller
{
    use AuthorizesRequests;

    /** Route-default key naming the {@see ParticleResource} for the inline tier. */
    public const RESOURCE = '_particle';

    public function __construct(
        protected readonly ParticleWriter $writer,
        protected readonly ParticleHydrator $hydrator,
        protected readonly ParticleResourceRegistry $registry,
        protected readonly ResponseEnvelope $envelope,
    ) {}

    public function index(Request $request): Responsable
    {
        $resource = $this->particleResource($request);
        $ctx = ReadContext::list($resource->includes, $request->user());

        $query = $resource->filterable
            ? $this->hydrator->query($resource->key, $ctx)
            : $resource->model::query()->latest();

        $page = $query->paginate($request->integer('per_page', $resource->perPage), ['*'], 'page');
        $page->through(fn (Model $record) => $this->projectRecord($resource, $record, $ctx));

        return $this->envelope->paginated($page);
    }

    public function show(Request $request, string $id): Responsable
    {
        $resource = $this->particleResource($request);
        $model = $this->findParticle($resource, $id);
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

        $model = new $resource->model;
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
        $model = $this->findParticle($resource, $id);

        if ($resource->prepare !== null) {
            ($resource->prepare)($model, $input, $request->user());
        }

        $model = $this->writeParticle($resource, $model, $input, $request->user());

        return $this->respond($resource, $model, $request);
    }

    public function destroy(Request $request, string $id): Responsable
    {
        $resource = $this->particleResource($request);
        $model = $this->findParticle($resource, $id);
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

    /** Persist through the beam write pipeline: WriteGate authorize → persist → after-hook → emit. */
    protected function writeParticle(ParticleResource $resource, Model $model, mixed $input, mixed $actor): Model
    {
        return $this->writer->write($model, $this->toAttributes($input), $actor, $this->afterHook($resource, $input));
    }

    protected function findParticle(ParticleResource $resource, string $id): Model
    {
        $query = $resource->model::query();
        if ($resource->includes !== []) {
            $query->with($resource->includes);
        }

        return $query->findOrFail($id);
    }

    /**
     * The parsed, VALIDATED input: the resource's input DTO via `validateAndCreate` (so its rules — a
     * spatie `rules()` method or inferred from types — run and reject with 422), else the raw request.
     */
    protected function parseInput(ParticleResource $resource, Request $request): mixed
    {
        return $resource->input !== null ? $resource->input::validateAndCreate($request) : $request;
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
     * extension tier, e.g. `SiloData`), else the bound `SchemaDataResolver` via the hydrator (the Frame
     * `#[AdminResource]` registry, for resources declared there).
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
