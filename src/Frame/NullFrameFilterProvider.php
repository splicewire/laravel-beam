<?php

namespace Splicewire\Beam\Frame;

use Schemastud\Frame\Contracts\FrameFilterProvider;

/**
 * The OOTB default for Frame's facet seam (beam-ux-uplift ticket 09) — promoted UP from the host
 * (audiostud's former `App\Frame\NullFrameFilterProvider`, whose docblock said "neither laravel-frame nor
 * laravel-beam binds this — the host does"). Now beam binds it so a fresh host's operator ListShell mounts
 * without a hand-written filter provider.
 *
 * Frame's resource socket exposes the facets bar at `{prefix}/filter-schema/{resource}` +
 * `{prefix}/filter-options/{ref}` and resolves the schema through a bound {@see FrameFilterProvider}. A
 * particle resource declaring no data-filters `query` has no facets to render — this provider answers an
 * empty schema/option set so the socket mounts without erroring.
 *
 * Overridable: a resource that wants a faceted list binds a real {@see FrameFilterProvider} from an app
 * provider (or one derived from a data-filters query class), registered after beam-core's so it wins.
 */
class NullFrameFilterProvider implements FrameFilterProvider
{
    public function for(string $resource): array
    {
        return ['properties' => []];
    }

    public function options(string $ref): array
    {
        return [];
    }
}
