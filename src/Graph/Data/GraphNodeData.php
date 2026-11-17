<?php

namespace Splicewire\Beam\Graph\Data;

use Spatie\LaravelData\Data;

/**
 * One node on the wire — a REFERENCE, not a payload (D4, ruled 2026-11-12).
 *
 * `{ id, type, resource, label }`: enough to address the record (its `id`), say what kind it is
 * (`type`), name the beam resource it belongs to (`resource`), and show it (`label`). The row itself is
 * read through that resource's own declared projection, never inlined here — a generic `properties` bag
 * would be the untyped-array problem again, so it is excluded on every graph shape (ticket 01, scope 4).
 */
class GraphNodeData extends Data
{
    public function __construct(
        public string $id,
        public string $type,
        public string $resource,
        public string $label,
    ) {}
}
