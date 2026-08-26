<?php

namespace Splicewire\Beam\Filters\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Splicewire\Beam\Data\Data;

/**
 * One addressable filter vocabulary for a resource — the unit `GET /{resource}/filters/variants`
 * enumerates.
 *
 * The variant axis is not new machinery. `#[ResourceFilter]` is `IS_REPEATABLE` and carries two key
 * fields: `key` (what the registry registers under, and what goes in the variant path segment) and
 * `resource` (the canonical key it belongs to, defaulting to `key`). Two declarations on DIFFERENT
 * Data classes sharing one `resource` are one resource with two filter schemas; two declarations on the
 * SAME class are an alias. Ticket 10 §4 found the substrate already had this and was exercising only
 * the alias case.
 *
 * `canonical` is what tells the two apart from outside: the variant whose key equals the resource key
 * is the one `GET /{resource}/filters/schema` answers with. `sameAsCanonical` is the alias tell — it is
 * true when a non-canonical variant resolves to the identical Data class, which today is every one of
 * them. Enumerating that honestly is the point: ticket 10 §4 made variants non-optional precisely so
 * this does not become an address space nobody can discover.
 */
class ResourceFilterVariantData extends Data
{
    public function __construct(
        #[Description('The registry key. Use it as the `{variant}` segment: `GET /{resource}/filters/{variant}/schema`.')]
        public string $key,

        #[Description('The canonical resource key this variant belongs to — the `{resource}` path segment.')]
        public string $resource,

        #[Description('True for the variant whose key IS the resource key. Its schema is what the bare `/filters/schema` returns.')]
        public bool $canonical,

        #[Description('True when this variant resolves to the same Filter Data class as the canonical one — i.e. it is a legacy alias rather than a genuinely different vocabulary.')]
        public bool $sameAsCanonical,
    ) {}
}
