<?php

declare(strict_types=1);

namespace Splicewire\Beam\Particle;

use Closure;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Schemastud\DataSchemas\Migration\AcceptanceGate;
use Schemastud\Frame\Contracts\FrameResourceHandler;
use Schemastud\Frame\Registry\ResourceDefinition;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Http\Particle\ParticleController;
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
 * declares only its `#[AdminResource]`-successor manifest works off the {@see ResourceDefinition}
 * alone (the old zero-glue `DefaultResourceHandler` archetype); a resource that also registers a
 * {@see ParticleAdminResource} (or {@see ParticleResource}) under its key gets its enrichment —
 * `includes` eager-loads, a `project` custom projector, `prepare`/`afterWrite` hooks, and input DTO
 * validation — applied identically to the REST transport.
 *
 * Authorization rides {@see PolicyWriteGate}: permissive when the resource declares no policy (the Frame
 * route's auth/realm middleware is the gate), delegating to the declared `policy` ability otherwise.
 */
class ParticleFrameResourceHandler implements FrameResourceHandler
{
    public function __construct(
        protected readonly SchemaTargetResolver $targets,
        protected readonly AcceptanceGate $acceptance,
        protected readonly Dispatcher $events,
        protected readonly Gate $gate,
        protected readonly ParticleResourceRegistry $registry,
        protected readonly ParticleHydrator $hydrator,
    ) {}

    public function index(ResourceDefinition $definition, array $params): array
    {
        $query = $this->indexQuery($definition);

        return $query
            ->get()
            ->map(fn (Model $model) => $this->projectRead($definition, $model))
            ->all();
    }

    /**
     * The list query for a Frame index. A registered, `filterable` {@see ParticleResource} rides the
     * data-filters builder ({@see ParticleHydrator::query}) — the SAME owner-scoped, `filter[...]`-aware,
     * saved-filter-capable query the REST {@see ParticleController::index}
     * uses — so a Frame list and a REST list are one read (a pinned `filter[circuit_id]` and per-caller
     * row-scoping hold in the editor exactly as they do over REST). A manifest-only resource, a
     * non-filterable one, or a host whose hydrator does not compose queries falls back to the plain
     * includes-eager-loaded, newest-first query.
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

        return $this->query($definition)->orderByDesc('created_at');
    }

    public function show(ResourceDefinition $definition, string $id): array
    {
        // A read-only (`! creatable`) resource is a LIST/inspection surface — machine-authored, with no
        // per-record edit projection — so a Frame `show` (records/{id}) is unavailable, exactly like the
        // retired bespoke read-only handlers which threw on `show` (ADR-0156 §83). The 405 fires BEFORE the
        // id lookup, so a syntactically invalid id can't leak a 500 through the read path.
        $this->assertWritable($definition, 'show');

        return $this->projectEdit($definition, $this->query($definition)->findOrFail($id));
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
     * Refuse a non-read verb on a resource whose {@see ResourceDefinition} is not `creatable` — a
     * read-only surface (declared `readOnly: true` on `#[AdminResource]` / {@see ParticleAdminResource},
     * or a service-backed union) is a machine-authored LIST/inspection surface, so store/update/destroy
     * AND the per-record `show` are genuinely unavailable, not merely unauthorized (ADR-0156 §83 read-only
     * widening). Renders as HTTP 405 — the same refusal the retired bespoke read-only handlers raised (they
     * were list-only: they threw on `show` too) — so the Frame frontend gate and the API agree.
     */
    protected function assertWritable(ResourceDefinition $definition, string $action): void
    {
        if (! $definition->creatable) {
            throw new HttpException(
                405,
                "The '{$action}' action is not supported for the '{$definition->key}' frame resource."
            );
        }
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

    /** A base query for the resource's model, eager-loading any declared includes. */
    protected function query(ResourceDefinition $definition)
    {
        $query = $definition->model::query();
        $includes = $this->resource($definition)?->includes ?? [];
        if ($includes !== []) {
            $query->with($includes);
        }

        return $query;
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

    protected function projectRead(ResourceDefinition $definition, Model $model): array
    {
        $resource = $this->resource($definition);
        if ($resource?->project !== null) {
            return $this->withId(($resource->project)($model)->toArray(), $model);
        }

        /** @var class-string<Data> $dataClass */
        $dataClass = $definition->data;

        return $this->withId($dataClass::from($model)->toArray(), $model);
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
