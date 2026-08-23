<?php

namespace Splicewire\Beam\Particle\Backing;

use Spatie\LaravelData\Data;

/**
 * ONE record resolved by {@see ResolvesRecord::resolve()} — the record's envelope plus the (nullable)
 * schema `$ref` its payload resolves through.
 *
 * beam's home for what frame shipped as `Schemastud\Frame\Contracts\ResolvedUnionItem`, unchanged in
 * shape: ticket 10 returned the backing contract to beam, so the value object it traffics in comes with
 * it.
 *
 * `schemaRef` is **nullable by design**: a backing's records may have no published contract, so a record
 * MAY legitimately have no ref (tower's `tenants` returns null — tenants carry no payload contract). It
 * is a **per-record** axis and does not duplicate ticket 09's `SchemaBindingIndex`, which is the
 * per-RESOURCE declared binding: a backing spanning two record types resolves a different ref per row,
 * which a resource-level binding cannot express.
 *
 * `record` is typed to the spatie `Data` base so any envelope satisfies it.
 */
class ResolvedRecord extends Data
{
    public function __construct(
        public Data $record,
        public ?string $schemaRef = null,
    ) {}
}
