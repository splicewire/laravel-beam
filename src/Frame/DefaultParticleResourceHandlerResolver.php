<?php

namespace Splicewire\Beam\Frame;

use Illuminate\Contracts\Container\Container;
use Schemastud\Frame\Contracts\FrameResourceHandler;
use Schemastud\Frame\Contracts\FrameResourceHandlerResolver;
use Splicewire\Beam\Particle\ParticleFrameResourceHandler;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * The OOTB implementation of Frame's resource-handler seam (beam-ux-uplift ticket 09) — promoted UP from
 * the host (audiostud's former `App\Frame\BeamFrameResourceHandlerResolver`, whose docblock said "neither
 * laravel-frame nor laravel-beam binds this — the host does"). Beam binds it, so a fresh host gets a
 * working operator area with no `app/Frame/` glue.
 *
 * ## It reads the DECLARATION; it is no longer a constant map
 *
 * This used to return one injected handler for any key whatsoever. That was right about the common case —
 * every beam-served resource rides {@see ParticleFrameResourceHandler} — and wrong about two things, both
 * of which a host then had to solve by binding its own resolver over the top of it.
 *
 * It could not serve a resource whose persistence is genuinely bespoke, so the flagship carried
 * `App\Frame\FrameResourceRegistry`: a constructor-seeded `key => handler` table, 19 rows, 18 of which
 * named the generic handler and one of which named the single exception. That table is now dissolved —
 * a resource that needs a bespoke handler names it on its own `#[ParticleResource]` via
 * {@see ParticleResource::$handler}, and everything else gets the generic one by omission. The full
 * argument for putting it on the declaration rather than in a host table is on that property.
 *
 * And it could not MISS. See {@see handlerFor()}.
 *
 * Still overridable: a host binds its own {@see FrameResourceHandlerResolver} from an app provider
 * (registered after beam-core's, so it wins) — `numero` and `schemastud` both do.
 */
class DefaultParticleResourceHandlerResolver implements FrameResourceHandlerResolver
{
    public function __construct(
        private ParticleResourceRegistry $resources,
        private Container $container,
    ) {}

    /**
     * The handler serving `$resource` — its declared {@see ParticleResource::$handler}, or the generic
     * {@see ParticleFrameResourceHandler} when it declares none.
     *
     * **Throws for a key that is not declared here, and that is the point.** The predecessor answered for
     * every key, so a caller could not tell "not registered on this host" from "registered, wants the
     * generic handler" — and neither could any audit. That is the estate's recurring defect class, an
     * instrument that reports success by not running: the host table this replaces had the same
     * `?? DefaultHandler` tail, and under it nine of the flagship's resources rode the wrong handler
     * undetected for months.
     *
     * **Why throwing is safe here, measured rather than assumed** — because the next reader will not have
     * the sweep. Frame's transport resolves a `ResourceDefinition` BEFORE it asks for a handler, and an
     * unknown key 404s at that step (the flagship's controller checks `keysForRealm()` explicitly and
     * throws `NotFoundHttpException`), so no HTTP request can reach this throw. And every Frame manifest
     * key IS a registered particle everywhere in the estate: swept 2026-08-28 across the flagship plus 11
     * `~/Herd` hosts on disk — audiostud, fable, calcucrypt, entreport, splicewire, standwell,
     * stephenrushing, thingsontv, and the beam/satellite/tower starter symlinks — comparing each host's
     * `ResourceRegistry` keys against its `ParticleResourceRegistry` keys. Set difference was EMPTY at
     * every one. 13 hosts ride this default resolver; only `numero` and `schemastud` bind their own, and
     * both are unaffected.
     *
     * So this throw is reachable only by programmatic misuse — which is exactly the caller that should
     * hear about it. This mirrors {@see ParticleResourceRegistry::get()}, whose docblock states the same
     * rule for the REST tier: a request that reached the controller for an unregistered key cannot be
     * served, and failing is the honest answer.
     *
     * A reader that merely wants to DESCRIBE a key — an audit, a spec build, a doctor — must use
     * {@see handlerIfDeclared()} instead. Whether a key is registered on this host is a fact about the
     * HOST, and per AGENTS.md a check whose answer depends on the host must not throw; that is why the
     * nullable half exists rather than every caller wrapping this in a try/catch.
     *
     * @throws UnknownFrameResource
     */
    public function handlerFor(string $resource): FrameResourceHandler
    {
        return $this->handlerIfDeclared($resource)
            ?? throw new UnknownFrameResource($resource);
    }

    /**
     * The nullable half of the miss pair — {@see handlerFor()} without the throw, returning null for a
     * key this host does not declare.
     *
     * This is the seam that makes absence EXPRESSIBLE: a caller that wants a default supplies it here,
     * visibly, at its own call site (`handlerIfDeclared($k) ?? $mine`) rather than having one silently
     * supplied underneath it. It is the same `get()`/`find()` pair
     * {@see ParticleResourceRegistry} already ships one tier in.
     */
    public function handlerIfDeclared(string $resource): ?FrameResourceHandler
    {
        $declaration = $this->resources->find($resource);

        if ($declaration === null) {
            return null;
        }

        // Resolved through the container, per call, so a bespoke handler may take constructor injection
        // (its alias registrar, its tenant connection) exactly as the generic one does. The generic
        // handler stays a container singleton, so the common path is a resolve, not a construction.
        return $this->container->make($declaration->handler ?? ParticleFrameResourceHandler::class);
    }
}
