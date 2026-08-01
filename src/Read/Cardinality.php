<?php

namespace Splicewire\Beam\Read;

/**
 * The read mode of a {@see ReadContext} (beam-write-pipeline ticket 13). Cardinality is a MODE, not a
 * separate type — a detail read and a list read share one hydrator + one row-mapper; only the shape of
 * the return differs. There is deliberately no `RecordCollectionHydrator` (DESIGN §9c): a `Many` read
 * yields `Data::collect($paginator)` (a `PaginatedDataCollection`), a `One` read a single `Data`.
 */
enum Cardinality
{
    case One;
    case Many;
}
