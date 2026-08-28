<?php

namespace Splicewire\Beam\Frame;

use RuntimeException;
use Schemastud\Frame\Contracts\FrameResourceHandlerResolver;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * Thrown by {@see DefaultParticleResourceHandlerResolver::handlerFor()} for a key that is not a
 * registered `#[ParticleResource]` on this host — the resolver's way of saying ABSENT rather than
 * quietly handing back a default.
 *
 * This exception exists because its absence was a defect. The resolver it replaces could not miss:
 * every read returned a handler, so "this key is not registered here" and "this key wants the generic
 * handler" were the same answer. The host-side table this seam supersedes had the identical shape
 * (`$this->handlers[$key] ?? DefaultResourceHandler::class`) and it cost real time — an attempt to
 * count the flagship's unmapped resources returned zero, because the instrument answered instead of
 * failing, while nine resources were in fact unmapped and one of them was throwing on every read.
 *
 * A caller that genuinely wants a fallback asks for one AT THE CALL SITE, via
 * {@see DefaultParticleResourceHandlerResolver::handlerIfDeclared()}, which returns null. That is the
 * `get()`/`find()` pair {@see ParticleResourceRegistry} already ships,
 * mirrored one tier out.
 */
class UnknownFrameResource extends RuntimeException
{
    public function __construct(public readonly string $resource)
    {
        parent::__construct(
            "No Frame resource handler for [{$resource}]: it is not a registered #[ParticleResource] on this host. "
            .'Declare it, or ask '.FrameResourceHandlerResolver::class.'::handlerIfDeclared() if absence is acceptable here.'
        );
    }
}
