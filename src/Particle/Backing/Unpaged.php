<?php

namespace Splicewire\Beam\Particle\Backing;

use Splicewire\Beam\Particle\ParticleFrameResourceHandler;

/**
 * Marker: this {@see StreamsRecords} backing yields its WHOLE population as one page, whatever page
 * size the request asks for.
 *
 * ## Why a marker and not a magic number
 *
 * A list read is paged twice: beam's handler hands the request's `perPage` to the backing, and frame's
 * `FrameResourceController::paginate()` then re-slices the handler's flat list by `per_page` (default
 * 25, max 100). A backing that ignores the first cap is still cut by the second — a realm dashboard with
 * 26 cards would page, and nothing on the client could know to ask for `per_page=26`. Frame's controller
 * returns a result UNTOUCHED when the handler has already enveloped it (`data` + `total`), so a backing
 * that declares itself unpaged is enveloped by {@see ParticleFrameResourceHandler}
 * itself, in the controller's own shape, and every row lands in one page.
 *
 * Declare it only for a population that is bounded by construction (a realm's resource count, a
 * registry's row count), never for a record stream — an unpaged stream is an unbounded response.
 */
interface Unpaged extends StreamsRecords {}
