<?php

declare(strict_types=1);

namespace Splicewire\Beam\Http\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Support\Responsable;
use Splicewire\Beam\Http\ArrayResponseEnvelope;
use Splicewire\Beam\Http\Particle\ParticleController;

/**
 * The beam response seam — the ONE app-coupling the promoted {@see ParticleController}
 * used to carry (the app's `App\Data\ResponseBody`) hoisted behind a port so beam-core owes nothing upward.
 *
 * The generic particle controllers speak only this interface to build their `{ data: … }` JSON. A headless
 * beam app gets the neutral {@see ArrayResponseEnvelope} default (a plain
 * `{ data: … }` / `{ data, limit, offset, total }` `JsonResponse`); a richer host (splicewire-app) BINDS
 * its own adapter over its response DTO — `App\Data\ResponseBody` — so the existing responses stay
 * byte-compatible (port-in-base / binding-in-host, the same shape the write/read seams already use).
 *
 * Each method returns a {@see Responsable} so a controller can hand it straight back as its return value.
 */
interface ResponseEnvelope
{
    /**
     * Wrap a single projected record (or `null`) as `{ data: … }`, HTTP 200.
     */
    public function item(mixed $data): Responsable;

    /**
     * Wrap a single projected record as `{ data: … }`, HTTP 201 — the `store()` created variant.
     */
    public function created(mixed $data): Responsable;

    /**
     * Wrap a paginated set as `{ data, limit, offset, total }`, HTTP 200. The paginator's items are the
     * already-projected records (the controller runs `->through()` before handing the page here).
     */
    public function paginated(LengthAwarePaginator $paginator): Responsable;
}
