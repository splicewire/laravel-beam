<?php

namespace Splicewire\Beam\Http\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Support\Responsable;
use Splicewire\Beam\Http\ArrayResponseEnvelope;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Http\ResponseBodyEnvelope;
use Splicewire\Beam\Surgeon\ResponseEnvelopeAudit;

/**
 * The beam response seam — the ONE app-coupling the promoted {@see ParticleController}
 * used to carry, hoisted behind a port so beam-core owes nothing upward.
 *
 * The generic particle controllers speak only this interface to build their `{ data: … }` JSON. Beam ships
 * TWO implementations and DECLARES which one is served at `beam.core.http.envelope`:
 * {@see ArrayResponseEnvelope} (a plain `{ data: … }` / `{ data, limit, offset, total }` `JsonResponse`)
 * is the shipped default every headless host gets, and {@see ResponseBodyEnvelope} is the richer
 * `{ success, message, … }` shape over beam's `ResponseBody`.
 *
 * ⚠️ **The choice is a config key rather than a host bind, and that was a repair**
 * (particle-manifest-repatriation ticket 04). It used to be reachable only by a HOST re-binding this
 * port, and measured across `~/Herd/*` on 2026-09-01 exactly one host did — so `~/Herd/splicewire-app`
 * answered `{ success, message, data }` and `~/Herd/tower` answered `{ data }` off the same package and
 * the same routes, and nothing could report it, because a container bind has no declaration site an
 * instrument can read. `splicewire/tower` now sets the key for every tower-backed host, and
 * {@see ResponseEnvelopeAudit} reports the resolved shape per host.
 *
 * A host may still bind an adapter of its own instead, and an explicit bind outranks the key —
 * port-in-base / binding-in-host, the same shape the write/read seams already use. The audit reports that
 * case rather than treating it as an error.
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
