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
 * is a **per-record** axis and would not duplicate ticket 09's planned `SchemaBindingIndex`, which is
 * the per-RESOURCE declared binding: a backing spanning two record types resolves a different ref per
 * row, which a resource-level binding cannot express.
 *
 * ⚠️ **`SchemaBindingIndex` DOES NOT EXIST.** It is ticket 09's named answer and was never built — verified
 * 2026-08-29 by three differently-shaped instruments: no `class`/`interface` declaration anywhere under the
 * family package roots or any `~/Herd/<host>/app` (its only five occurrences estate-wide are prose, four of them
 * in this package); `git log -S` in beam and at the flagship names only the commits that wrote that prose;
 * and a booted `class_exists()` plus a composer classmap scan at `~/Herd/splicewire-app` resolve nothing,
 * with `BeamData` as a working control. Kept as a DESIGN NOTE because the reasoning is sound and someone
 * will build it; the sentence above no longer says it is here.
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
