<?php

declare(strict_types=1);

namespace Splicewire\Beam\Write;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Schemastud\DataSchemas\Migration\AcceptanceGate;
use Splicewire\Beam\Concerns\PersistsSchemaRecord;
use Splicewire\Beam\Events\SchemaRecordPersisted;
use Splicewire\Beam\Models\SchemaRecord;
use Splicewire\Beam\Schema\Contracts\SchemaTargetResolver;
use Splicewire\Beam\Write\Contracts\WriteGate;

/**
 * The single, model-agnostic beam-core write pipeline (beam-write-pipeline ticket 03, DESIGN §3a) —
 * the one path every write surface rides instead of each controller hand-rolling
 * `validate → authorize → fill → save → sync-relations`.
 *
 * Given a target model (or class) + a schema-shaped payload + an actor, it runs, in order:
 *
 *   1. **authorize** through the deny-by-default {@see WriteGate} — refused ⇒ {@see WriteNotAuthorized}
 *      and nothing persists;
 *   2. **validate** the payload against the record type's resolved target schema via the
 *      {@see AcceptanceGate} — non-conforming ⇒ {@see PayloadRejected} and nothing persists (skipped
 *      when the type has no registered schema: a plain app model validates at its own DTO boundary);
 *   3. **persist** by filling the model through its {@see PersistsSchemaRecord}
 *      seam and saving — to ANY trait-bearing model, which keeps its own table;
 *   4. run the optional **after-persist hook** (relation syncs live HERE, deliberately not folded into
 *      the pipeline — DESIGN §3a);
 *   5. **emit** one {@see SchemaRecordPersisted} — the single post-persist signal every write path shares.
 *
 * It is named `RecordWriter`, NOT `SchemaRecordWriter`: it writes any persisting record, not only the
 * base {@see SchemaRecord}.
 */
final class RecordWriter
{
    public function __construct(
        private readonly WriteGate $gate,
        private readonly SchemaTargetResolver $targets,
        private readonly AcceptanceGate $acceptance,
        private readonly Dispatcher $events,
    ) {}

    /**
     * Write `$payload` to `$target`, returning the persisted model.
     *
     * @param  Model|class-string<Model>  $target  a model instance (pre-set with its schema binding) or
     *                                             a model class to instantiate
     * @param  array<string, mixed>  $payload  the schema-shaped content to validate and persist
     * @param  mixed  $actor  the authenticated actor, or null for an anonymous write
     * @param  (Closure(Model): void)|null  $after  an after-persist hook (relation syncs)
     *
     * @throws WriteNotAuthorized the gate refused the write (nothing persisted)
     * @throws PayloadRejected the payload does not conform to its target schema (nothing persisted)
     */
    public function write(Model|string $target, array $payload, mixed $actor = null, ?Closure $after = null): Model
    {
        $model = $target instanceof Model ? $target : new $target;

        // 1. Authorize — deny by default. A record type nothing explicitly permits is refused here,
        //    before any validation or write.
        if (! $this->gate->authorizes($model, $payload, $actor)) {
            throw WriteNotAuthorized::for($model);
        }

        // 2. Validate against the resolved target schema. A record type with no registered schema
        //    resolves an empty target — a total, non-throwing "no target" — so app models riding the
        //    pipeline validate at their own DTO boundary, not here.
        $recordType = $this->recordTypeOf($model);
        if ($recordType !== null) {
            $targetSchema = $this->targets->targetFor($recordType);
            if ($targetSchema !== [] && ! $this->acceptance->accepts($payload, $targetSchema)) {
                throw PayloadRejected::for($recordType);
            }
        }

        // 3. Persist through the model's schema-payload seam when it has one (a PersistsSchemaRecord
        //    model decides how content lands on it); otherwise fall back to a plain mass-fill, so the
        //    pipeline stays usable by ANY model — e.g. Frame's arbitrary host records (ticket 06).
        if (method_exists($model, 'fillFromSchemaPayload')) {
            $model->fillFromSchemaPayload($payload);
        } else {
            $model->fill($payload);
        }
        $model->save();

        // 4. After-persist hook — relation syncs and other record-specific writes live here.
        if ($after !== null) {
            $after($model);
        }

        // 5. One signal, every write path.
        $this->events->dispatch(new SchemaRecordPersisted($model, $payload, $this->bindingOf($model)));

        return $model;
    }

    /**
     * The record type used to resolve the target schema — a trait-bearing model exposes its stem via
     * {@see PersistsSchemaRecord::recordType()}; any other model has none
     * (validation is skipped).
     */
    private function recordTypeOf(Model $model): ?string
    {
        return method_exists($model, 'recordType') ? $model->recordType() : null;
    }

    /**
     * The schema binding the record was written under, for the emitted event — null for a model that
     * carries no binding.
     */
    private function bindingOf(Model $model): ?string
    {
        return method_exists($model, 'schemaBinding') ? $model->schemaBinding() : null;
    }
}
