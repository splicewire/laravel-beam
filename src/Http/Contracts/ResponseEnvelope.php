<?php

namespace Splicewire\Beam\Http\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Support\Responsable;
use Splicewire\Beam\Http\ArrayResponseEnvelope;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Http\ResponseBodyEnvelope;

/**
 * The beam response seam — the ONE app-coupling the promoted {@see ParticleController}
 * used to carry, hoisted behind a port so beam-core owes nothing upward.
 *
 * The generic particle controllers speak only this interface to build their `{ data: … }` JSON. Beam ships
 * TWO bindings and binds the neutral one: {@see ArrayResponseEnvelope} (a plain `{ data: … }` /
 * `{ data, limit, offset, total }` `JsonResponse`) is the default every headless host gets, and
 * {@see ResponseBodyEnvelope} is the richer `{ success, message, … }` shape over beam's `ResponseBody`,
 * which a host binds in its own provider when it wants that contract.
 *
 * A host may of course bind an adapter of its own instead — port-in-base / binding-in-host, the same shape
 * the write/read seams already use.
 *
 * Note the prose this replaces called `ResponseBody` "the app's" DTO while spelling it `Splicewire\Beam\…`;
 * it was promoted to beam and the sentence never caught up, which is what kept its adapter stranded in
 * `splicewire/tower` until api-surface-coherence 24.
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
