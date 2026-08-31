<?php

namespace Splicewire\Beam\Data;

use Illuminate\Http\JsonResponse;
use Schemastud\DataSchemas\StudData;

/**
 * Beam-base DTO with response helpers.
 *
 * A {@see StudData} that also renders itself as a JSON response
 * (toResponseArray/toResponse). It lives in beam-base so every downstream
 * package (beam-commerce, tower, hosts) can extend it via a legal DOWN edge —
 * no package needs to reach UP to a sibling for a shared DTO parent.
 *
 * Parented on StudData rather than Spatie's Data directly, so every DTO whose chain reaches this
 * class answers `::jsonSchema()` through the host's CONFIGURED generator — 140-odd extend it
 * directly, and another 240-odd arrive through `Tower\Data\Data`, tower's own DTO vocabulary, which
 * is a one-line subclass of this one. That is the whole opt-in: no subclass changes. Beam already
 * requires schemastud/laravel-data-schemas, so this adds no dependency.
 *
 * The bare-`new JsonSchemaGenerator` problem this parenting was chosen against is now argued rather
 * than counted. The fleet-wide census lives in
 * {@see \Schemastud\DataSchemas\Concerns\DerivesJsonSchema} and belongs there; restated here it goes
 * stale, because here it has already been paid off. Across the beam family, tower and the flagship no
 * `src/` builds a bare generator — the dominant grep hit for the class is prose explaining why a site
 * stopped — and the two bare constructions left are both in tests, one of them a negative control
 * that asserts the bare generator THROWS.
 */
class BeamData extends StudData
{
    use RendersJsonSafely;

    public function toResponseArray(): array
    {
        return $this->toArray();
    }

    /**
     * Same defence as {@see ResponseBody::toResponse()} — see {@see RendersJsonSafely}. Every
     * subclass estate-wide inherits it, which is the point of the seam living here.
     *
     * The projection is handed in as a closure, not as an already-evaluated array: `toArray()` is a
     * transformer pipeline over live property values and can throw for the same reasons the encoder
     * can, so evaluating it here would put it outside the very guarantee this call exists to give.
     */
    public function toResponse($request): JsonResponse
    {
        return $this->jsonResponseThatCannotThrow(fn () => $this->toResponseArray(), 200);
    }
}
