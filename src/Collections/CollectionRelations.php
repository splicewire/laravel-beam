<?php

namespace Splicewire\Beam\Collections;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** One-hop expansion batches by target stem in the same realm-scoped reader. */
final class CollectionRelations
{
    public function __construct(private CollectionSchemaResolver $schemas, private CollectionRead $reader) {}

    /**
     * Inputs are already projected for this actor; a hidden relation field is never traversed.
     *
     * @param  list<CollectionRecordData>  $records
     * @return array<string, array<string, CollectionRecordData|list<CollectionRecordData>|null>>
     */
    public function resolve(array $records, ?Authenticatable $actor): array
    {
        $edges = $targets = $result = [];
        foreach ($records as $record) {
            $result[$record->id] = [];
            foreach ($this->schemas->resolve($record->collection)->relations() as $field => $relation) {
                if (! Arr::has($record->fields, $field)) {
                    continue;
                }
                $value = Arr::get($record->fields, $field);
                $ids = $relation['many'] ? $value : [$value];
                if (! is_array($ids) || ! array_is_list($ids) || array_filter($ids, fn ($id) => ! is_string($id)) !== []) {
                    throw ValidationException::withMessages([$field => 'A Collection relation must contain record ids.']);
                }
                $stem = $relation['collection'];
                $targets[$stem] = [...($targets[$stem] ?? []), ...$ids];
                $edges[] = [$record->id, $field, $stem, $ids, $relation['many']];
            }
        }
        $resolved = [];
        foreach ($targets as $stem => $ids) {
            // This resolves the target's latest declaration and applies its own public rules.
            try {
                $resolved[$stem] = $this->reader->many($stem, array_values(array_unique($ids)), $actor);
            } catch (NotFoundHttpException) {
                // Missing/unmarked targets reveal no more than an absent record.
                $resolved[$stem] = [];
            }
        }
        foreach ($edges as [$recordId, $field, $stem, $ids, $many]) {
            $items = [];
            foreach ($ids as $id) {
                if (isset($resolved[$stem][$id])) {
                    $items[] = $resolved[$stem][$id];
                }
            }
            $result[$recordId][$field] = $many ? $items : ($items[0] ?? null);
        }

        return $result;
    }
}
