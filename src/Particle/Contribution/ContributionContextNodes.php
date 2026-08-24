<?php

namespace Splicewire\Beam\Particle\Contribution;

use ReflectionClass;
use Schemastud\Frame\Contracts\ResourceContextContributor;
use Schemastud\Frame\Registry\ContextManifest;
use Schemastud\Frame\Registry\WidgetContextProjector;

/**
 * Beam's implementation of frame's optional {@see ResourceContextContributor} plug: it projects
 * each registered {@see ResourceContribution}'s OWN slice Data class into the extra `byNode`
 * entries a resource's context block needs for its contributed list columns to render.
 *
 * ## Why this class exists at all
 *
 * {@see ContextManifest::forResource()} reflects exactly one Data class, and a contributed slice
 * is a DIFFERENT Data class hanging off a sub-projection key — so a contributed `#[Column]` is
 * structurally invisible to the manifest, and frame's JS treats the manifest as authoritative
 * (`resolveColumns` throws in dev for a host column with no `list-column` participation). That is
 * why the destination's *"a beam host with commerce gets the commerce columns"* was false before
 * this ticket, and it is a RUNTIME-REFLECTION gap, distinct from the accepted laravel-data
 * CODEGEN gap that {@see ContributionProjector} documents (particle-contribution-seam 15 §A5).
 *
 * ## The dotted pointer, and why no schema change was needed
 *
 * A slice lands nested under its `as` (`row.commerce.plan`), so its pointer is `as.prop`. `byNode`
 * is a plain string map, so `'commerce.plan'` is a legal key today — the server half is the whole
 * of the keying, and frame's JS learns to resolve the PATH when it reads the value.
 *
 * ## Direction
 *
 * The reflection runs on the CONTRIBUTOR's class, from beam, and frame is handed a finished map. It
 * never learns that a contribution exists — see {@see ResourceContextContributor}. `laravel-beam`
 * requires `schemastud/laravel-frame` directly, so naming frame's port and projector here rides the
 * sanctioned splicewire→schemastud direction; nothing here reverses it.
 */
class ContributionContextNodes implements ResourceContextContributor
{
    public function __construct(
        protected ResourceContributionRegistry $contributions,
    ) {}

    /**
     * @return array<string, array<string, mixed>>
     */
    public function nodesFor(string $key): array
    {
        // Inert by default: the overwhelmingly common case is a resource nobody contributes to,
        // and it costs one array lookup.
        if (! $this->contributions->has($key)) {
            return [];
        }

        $projector = new WidgetContextProjector;
        $nodes = [];

        foreach ($this->contributions->for($key) as $contribution) {
            // Direct properties only — the same top-level-resource-scoped rule the manifest's own
            // reflection follows. A slice is one level deep by construction; nesting a slice inside
            // a slice is not something the seam can express.
            foreach ((new ReflectionClass($contribution->data))->getProperties() as $property) {
                $map = $projector->forProperty($property);

                if ($map !== []) {
                    $nodes[$contribution->as.'.'.$property->getName()] = $map;
                }
            }
        }

        return $nodes;
    }
}
