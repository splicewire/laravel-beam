<?php

namespace Splicewire\Beam\Data;

use Illuminate\Http\JsonResponse;
use Spatie\LaravelData\Data as SpatieData;

/**
 * Beam-base DTO with response helpers.
 *
 * A generic {@see SpatieData} that also renders itself as a JSON response
 * (toResponseArray/toResponse). It lives in beam-base so every downstream
 * package (beam-commerce, tower, hosts) can extend it via a legal DOWN edge —
 * no package needs to reach UP to a sibling for a shared DTO parent.
 */
class Data extends SpatieData
{
    public function toResponseArray(): array
    {
        return $this->toArray();
    }

    public function toResponse($request): JsonResponse
    {
        return new JsonResponse($this->toResponseArray());
    }
}
