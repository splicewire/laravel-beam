<?php

namespace Splicewire\Beam\Particle\Backing;

use Illuminate\Database\Eloquent\Builder;

/**
 * Capability: this backing yields a COMPOSABLE Eloquent query rather than a finished page.
 *
 * ## Why this is separate from {@see StreamsRecords}, and narrower
 *
 * `StreamsRecords` is the general capability — filters in, a page of records out — and it is the one an
 * external backing can implement. This one is deliberately **Eloquent-specific**: it hands back a
 * `Builder` the caller may still compose, which is what the data-filters path (`filter[...]` operators,
 * saved filters, owner scoping, the declared `#[Sortable(default: true)]` order) needs in order to do
 * its work *on* the query rather than after it.
 *
 * A backing that has a real query to compose implements both, and gets `records()` for free from
 * {@see BacksEloquent}. A backing that does not — one fusing several services, or reading a remote API
 * — implements `StreamsRecords` alone and owns its own paging. Neither is the fallback for the other:
 * `StreamsRecords` is more general, `QueriesRecords` is more capable.
 *
 * ⚠️ Returning a `Builder` is what makes this the narrow one, and that is the trade being made
 * knowingly: a `Builder` is not substitutable across backings, so it may not appear on the general
 * capability. Anything the caller needs from EVERY backing has to be expressible through
 * `StreamsRecords` instead.
 */
interface QueriesRecords extends ResourceBacking
{
    /**
     * The base query for a list read, before pagination.
     *
     * @param  array<string, mixed>  $filters  the request's opaque `filter[...]` bag — the SAME argument
     *                                         shape {@see StreamsRecords::records()} takes, so a caller
     *                                         hands the two capabilities identical input
     */
    public function query(array $filters): Builder;
}
