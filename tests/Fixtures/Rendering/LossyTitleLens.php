<?php

namespace Splicewire\Beam\Tests\Fixtures\Rendering;

use Schemastud\DataSchemas\Overlay\Lens\DirectedLens;

/**
 * A law-BREAKING lens: `put` writes the rendering's title but drops the canonical's private state
 * instead of reconstructing it, so `put(get(S), S) !== S` and GetPut fails. Paired with a
 * `LosslessEligible` claim it is exactly the dishonest case the certifier must refuse — the round trip
 * would silently discard `private`.
 */
class LossyTitleLens implements DirectedLens
{
    public function get(mixed $canonical): mixed
    {
        return ['title' => $canonical['title'] ?? null];
    }

    public function put(mixed $rendering, mixed $priorCanonical, mixed $complement = null): mixed
    {
        return ['title' => $rendering['title'] ?? null];
    }
}
