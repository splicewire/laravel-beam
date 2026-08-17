<?php

namespace Splicewire\Beam\Tests\Fixtures\Rendering;

use Schemastud\DataSchemas\Overlay\Lens\DirectedLens;

/**
 * A well-behaved lens: the rendering exposes only `title`, and `put` reconstructs everything else from
 * the retained prior canonical — so GetPut and PutGet both hold and the association survives
 * certification.
 */
class TitleLens implements DirectedLens
{
    public function get(mixed $canonical): mixed
    {
        return ['title' => $canonical['title'] ?? null];
    }

    public function put(mixed $rendering, mixed $priorCanonical, mixed $complement = null): mixed
    {
        return array_merge((array) $priorCanonical, ['title' => $rendering['title'] ?? null]);
    }
}
