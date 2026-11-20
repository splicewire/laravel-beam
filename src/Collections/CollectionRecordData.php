<?php

namespace Splicewire\Beam\Collections;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Data;

/**
 * One declared envelope; tenant JSON Schema types fields, not a new resource.
 *
 * Each property declares its title (localization 72), so a record form reads a keyed title
 * (`beam::data.<this class>.properties.<p>.title`, ADR-0244) instead of humanizing the property name. The English is the
 * text the form showed before.
 */
class CollectionRecordData extends Data
{
    /** @param array<string, mixed> $fields */
    public function __construct(
        #[Title('Id')]
        public string $id,
        #[Title('Collection')]
        public string $collection,
        #[Title('Schema ref')]
        public string $schemaRef,
        #[Title('Migration status')]
        public string $migrationStatus,
        #[Title('Fields')]
        public array $fields,
        #[Title('Schema')]
        public array $schema = [],
        #[Title('Relations')]
        public array $relations = [],
    ) {}
}
