<?php

namespace Splicewire\Beam\Collections;

use Closure;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Models\BeamParticle;

/** Authorized Collection persistence only; callers must authorize before entering this boundary. */
final class CollectionUniqueWrite
{
    public function __construct(private StandaloneRecordQuery $records) {}

    /**
     * The stable oldest schema row serializes unique checks and persistence on the record's DB.
     * The callback must contain persistence and its after-hook, but not post-commit event dispatch.
     *
     * @param  list<string>  $refs  Exact registered schema identities for this collection.
     */
    public function run(BeamParticle $model, CollectionDefinition $definition, array $refs, array $payload, Closure $persist): mixed
    {
        if ($definition->uniqueFields() === []) {
            return $persist();
        }

        $connection = $model->getConnection();

        if (! $connection->getSchemaBuilder()->hasTable(Beam::table('schemas'))) {
            throw ValidationException::withMessages(['collection' => 'Unique Collection writes require a registered database schema anchor on the record connection; the schema registry table is absent.']);
        }

        return $connection->transaction(function () use ($connection, $model, $definition, $refs, $payload, $persist): mixed {
            $anchor = $connection->table(Beam::table('schemas'))
                ->where('schema_name', $definition->stem())
                ->orderBy('version')->orderBy('id')->lockForUpdate()->first();
            if ($anchor === null) {
                throw ValidationException::withMessages(['collection' => 'Unique Collection writes require a registered database schema anchor on the record connection; a filesystem-only schema cannot serialize writes.']);
            }

            foreach ($definition->uniqueFields() as $field) {
                $value = Arr::get($payload, $field);
                // Missing and null do not claim a unique value.
                if ($value === null) {
                    continue;
                }
                $query = $this->records->query($connection->getName())
                    ->whereIn('schema_ref', $refs)
                    ->where('payload->'.str_replace('.', '->', $field), $value);
                if ($model->exists) {
                    $query->whereKeyNot($model->getKey());
                }
                if ($query->exists()) {
                    throw ValidationException::withMessages([$field => 'This value is already used by another record in the Collection.']);
                }
            }

            return $persist();
        });
    }
}
