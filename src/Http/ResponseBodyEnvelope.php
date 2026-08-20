<?php

namespace Splicewire\Beam\Http;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Support\Responsable;
use Splicewire\Beam\Data\ResponseBody;
use Splicewire\Beam\Http\Contracts\ResponseEnvelope;

/**
 * The SECOND {@see ResponseEnvelope} beam ships (ADR-0116) — the richer of the two wire shapes a host may
 * pick, over beam's own {@see ResponseBody} DTO.
 *
 * Beam binds {@see ArrayResponseEnvelope} by default: a bare `{ data: … }` / `{ data, limit, offset, total }`.
 * This one emits `ResponseBody`'s envelope instead — `{ success, message, limit, offset, total, data, … }` —
 * so a host that wants `success`/`message` on every particle response binds this in its own provider rather
 * than writing a twenty-line adapter of its own.
 *
 * NOT bound here. Beam's default stays the neutral one: swapping it would move the wire contract under every
 * headless beam host, which is a bigger act than offering the alternative.
 *
 * Descended from `splicewire/tower` by api-surface-coherence 24. It sat up there calling itself "the app's
 * binding" over "its own DTO" — but the port, the default, AND `ResponseBody` are all beam-core, so the only
 * thing above beam was the adapter joining two beam classes. That is the same stale-reason anchor the three
 * `Tower\Particle\*` shims died of in the same ticket.
 */
class ResponseBodyEnvelope implements ResponseEnvelope
{
    public function item(mixed $data): Responsable
    {
        return ResponseBody::from(['data' => $data]);
    }

    public function created(mixed $data): Responsable
    {
        return ResponseBody::from(['data' => $data])->created();
    }

    public function paginated(LengthAwarePaginator $paginator): Responsable
    {
        return ResponseBody::paginated($paginator);
    }
}
