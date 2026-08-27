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
 * Parented on StudData rather than Spatie's Data directly, so every one of the 85 DTOs
 * extending this class answers `::jsonSchema()` through the host's CONFIGURED generator.
 * That is the whole opt-in: no subclass changes, and the 26 bare `new JsonSchemaGenerator`
 * call sites this estate carries have one less reason to exist. Beam already requires
 * schemastud/laravel-data-schemas, so this adds no dependency.
 */
class Data extends StudData
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
