<?php

namespace Splicewire\Beam\Particle\Backing;

/**
 * Capability: this backing can resolve ONE record by id, projected, for a detail read.
 *
 * Absorbs `Schemastud\Frame\Contracts\UnionSource::find()`. Two changes came with the move, both to
 * make the capability substitutable:
 *
 * 1. **The discriminator rides `$filters`.** `find()` took a separate `string $source` first argument —
 *    the arm of a union a record belongs to, since the same row id can exist under either arm. That is
 *    a filter by any other name, and giving it a dedicated positional parameter meant every backing had
 *    to accept an argument only unions use. It now travels in the same opaque `$filters` bag
 *    {@see StreamsRecords::stream()} and {@see QueriesRecords::query()} take, so **a caller hands every
 *    capability the identical argument shape** whatever backs the resource.
 * 2. **`ResolvedUnionItem` became {@see ResolvedRecord}** in beam's namespace, unchanged in shape.
 *
 * ## Not the same job as record RESOLUTION for a write
 *
 * This yields a projected {@see ResolvedRecord} for a detail READ. A backing that also writes resolves
 * its subject through {@see WritesRecords}, because a write needs the mutable record itself, not a
 * projection of it. A backing may implement either, both, or neither.
 */
interface ResolvesRecord extends ResourceBacking
{
    /**
     * Resolve one record by id, or null when it does not exist.
     *
     * @param  array<string, mixed>  $filters  the request's opaque bag — carries the union-arm
     *                                         discriminator (`source`) where a backing needs one
     */
    public function resolve(string $id, array $filters): ?ResolvedRecord;
}
