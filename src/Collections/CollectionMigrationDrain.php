<?php

namespace Splicewire\Beam\Collections;

/** Eager registration drain, including old rows that have not yet been read and marked pending. */
final class CollectionMigrationDrain
{
    public function __construct(private CollectionSchemaResolver $schemas, private StandaloneRecordQuery $records) {}

    public function run(string $stem, bool $allowLlm = false): int
    {
        $refs = $this->schemas->recordRefs($stem);
        $count = 0;
        $this->records->query()->whereIn('schema_ref', $refs)->chunkById(100, function ($records) use ($allowLlm, &$count): void {
            foreach ($records as $record) {
                $record->migratePayloadEager($allowLlm);
                $count++;
            }
        });

        return $count;
    }
}
