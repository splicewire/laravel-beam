<?php

namespace Splicewire\Beam\Frame;

use Schemastud\Frame\Contracts\FrameResourceHandler;
use Schemastud\Frame\Contracts\FrameResourceHandlerResolver;
use Splicewire\Beam\Particle\ParticleFrameResourceHandler;

/**
 * The OOTB default for Frame's resource-handler seam (beam-ux-uplift ticket 09) — promoted UP from the
 * host (audiostud's former `App\Frame\BeamFrameResourceHandlerResolver`, whose docblock said "neither
 * laravel-frame nor laravel-beam binds this — the host does"). Now beam binds it so a fresh host gets a
 * working operator area with no `app/Frame/` glue.
 *
 * Frame's resource socket calls {@see handlerFor()} at request time; every beam-served resource rides the
 * ONE beam-native handler ({@see ParticleFrameResourceHandler}), which runs the canonical CRUD through the
 * beam seams and applies a registered {@see \Splicewire\Beam\Particle\ParticleResource}'s enrichment
 * (scope/project/includes) when one exists under the key — so this resolver is a constant map: every key →
 * the beam handler.
 *
 * Overridable: a host that maps some keys to bespoke handlers binds its own
 * {@see FrameResourceHandlerResolver} from an app provider (registered after beam-core's, so it wins).
 */
class DefaultParticleResourceHandlerResolver implements FrameResourceHandlerResolver
{
    public function __construct(private ParticleFrameResourceHandler $handler) {}

    public function handlerFor(string $resource): FrameResourceHandler
    {
        return $this->handler;
    }
}
