<?php

declare(strict_types=1);

namespace Splicewire\Beam\Read\Contracts;

use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Read\PayloadParticleReader;
use Splicewire\Beam\Read\ReadContext;
use Splicewire\Beam\Write\ParticleWriter;

/**
 * The read seam that mirrors the write pipeline's {@see ParticleWriter}
 * (beam-write-pipeline ticket 13, DESIGN §9). Where the writer collapses validate→authorize→persist→emit,
 * the hydrator collapses resolve→(reconcile)→assemble a typed `Data` from scattered sources — and
 * compiles ONE {@see ReadContext::$includes} list into BOTH the eager-load axis AND the serialization
 * partial, killing the double-declaration.
 *
 * Two implementations, port-in-base / binding-in-host (DESIGN §9d):
 *  - the degenerate {@see PayloadParticleReader} in beam-core — direct-from-source
 *    (`Data::from(reconcile($payload))`), needing NO `rushing/laravel-data-filters` dependency;
 *  - the query-composing `DataFilterRecordHydrator` bound in a host where data-filters IS available —
 *    its {@see self::query()} returns the composed data-filters builder so pagination / saved filters /
 *    further `filter[...]` ride it.
 */
interface ParticleHydrator
{
    /**
     * Assemble a single typed `Data` for `$source` under `$ctx` — the detail read, and the degenerate
     * reader's whole job. `$source` is a persisted model, a raw payload array, or a record id/ref.
     */
    public function hydrate(Model|array|string $source, ReadContext $ctx): Data;

    /**
     * A list read: return the composed query builder for `$recordType` (the data-filters `QueryBuilder`
     * in the host binding — an Eloquent builder pagination + `DataFilter::applySaved` + further
     * `filter[...]` ride). The degenerate reader does not compose queries and throws.
     */
    public function query(string $recordType, ReadContext $ctx): object;

    /**
     * The shared row-mapper both the detail read and each list row call: project one loaded record to a
     * typed `Data`, applying `$ctx->includes` as the serialization partial.
     */
    public function project(Model $record, ReadContext $ctx): Data;
}
