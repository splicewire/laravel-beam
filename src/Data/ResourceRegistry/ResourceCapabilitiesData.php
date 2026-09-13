<?php

namespace Splicewire\Beam\Data\ResourceRegistry;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Particle\Backing\DeclaresFilterVocabulary;
use Splicewire\Beam\Particle\Backing\QueriesRecords;
use Splicewire\Beam\Particle\Backing\ResolvesRecord;
use Splicewire\Beam\Particle\Backing\StreamsRecords;
use Splicewire\Beam\Particle\Backing\WritesRecords;

/**
 * What a resource's BACKING can do — one boolean per capability interface, read by `instanceof` and never
 * copied from a declared flag, so it cannot drift from the class it describes.
 *
 * Part of {@see ResourceRegistryEntryData}; see that class for why the capability and the intent travel as
 * two separate objects rather than one merged set.
 */
#[TypeScript]
class ResourceCapabilitiesData extends BeamData
{
    public function __construct(
        /** The backing implements {@see StreamsRecords} — a paged list. */
        public bool $streams,
        /** The backing implements {@see QueriesRecords} — a composable Eloquent query. */
        public bool $queries,
        /** The backing implements {@see ResolvesRecord} — a projected one-record read. */
        public bool $resolves,
        /** The backing implements {@see WritesRecords} — create, update and delete. */
        public bool $writes,
        /** The backing implements {@see DeclaresFilterVocabulary} — it names its own facets. */
        public bool $vocabulary,
    ) {}
}
