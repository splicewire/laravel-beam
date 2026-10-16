<?php

namespace Splicewire\Beam\Collections;

use Spatie\LaravelData\Data;

/** One declared envelope; tenant JSON Schema types fields, not a new resource. */
class CollectionRecordData extends Data
{
    /** @param array<string, mixed> $fields */
    public function __construct(
        public string $id,
        public string $collection,
        public string $schemaRef,
        public string $migrationStatus,
        public array $fields,
        public array $schema = [],
        public array $relations = [],
    ) {}
}
