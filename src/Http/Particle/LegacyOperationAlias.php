<?php

namespace Splicewire\Beam\Http\Particle;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Splicewire\Beam\Doctor\ParticleSlotCollisionAudit;
use Symfony\Component\HttpFoundation\Response;

/**
 * The measurement half of the `/op/` drop (particle-operation-surface 12).
 *
 * `ParticleMounter::op()` mounts every operation twice — the primary `{uri}/{id}/{op}` and the
 * deprecated `{uri}/{id}/op/{op}` alias the estate shipped first. This middleware rides the alias only,
 * and exists to answer the one question the ticket said had to be settled before an alias can ever be
 * removed: **is anything still calling it?**
 *
 * The estate had no way to answer that. A deprecated route that answers 200 is indistinguishable from a
 * dead one, which is the same shape as every other trap in this codebase — the instrument reports
 * success by not running. So the alias reports itself, two ways, aimed at two different readers:
 *
 * - **`Deprecation: true` + `Link: <successor>; rel="successor-version"`** (RFC 8594) so an integrator's
 *   own client can see it without anyone telling them. The successor URL is derived from the request's
 *   own path rather than regenerated from a route name, because the route name is exactly the thing that
 *   differs between the pair and re-deriving it here would restate the mount's naming rule in a second
 *   place.
 * - **One `Log::notice` per call**, so the HOST can answer the question without instrumenting anything
 *   or reading an access log it may not keep. Deliberately `notice` and not `warning`: calling a
 *   deprecated-but-supported URL is not a fault, and a level that pages someone would get silenced,
 *   which would delete the measurement.
 *
 * ⚠️ **It records the call, it does not refuse it.** Whether a host has finished migrating is a fact
 * about that host, so by this estate's own throw/advise rule this cannot be a rejection — it reports.
 * The removal decision is a reading of these lines, not a date.
 *
 * The headers are added AFTER the response is resolved rather than inside the controller because
 * `ParticleOperationController::invoke()` returns `mixed` — a Data object, a raw array, or a
 * `StreamedResponse` for `media.download`. Only here is there one prepared `Response` to stamp.
 */
class LegacyOperationAlias
{
    public const HEADER = 'Deprecation';

    public function handle(Request $request, Closure $next): Response
    {
        $successor = $this->successor($request);

        Log::notice('[beam] Deprecated particle-operation URL called: '.$request->method().' /'.ltrim($request->path(), '/').'. The supported spelling is /'.ltrim($successor, '/').' — the `/op/` segment was dropped by particle-operation-surface 12 and this alias exists only so shipped callers keep working.');

        $response = $next($request);

        if ($response instanceof Response) {
            $response->headers->set(self::HEADER, 'true');
            $response->headers->set('Link', '<'.$request->getSchemeAndHttpHost().'/'.ltrim($successor, '/').'>; rel="successor-version"');
        }

        return $response;
    }

    /**
     * The primary spelling of the URL that was called — this path with the FIRST `/op/` collapsed out.
     *
     * `str_replace` would be wrong here and the difference is reachable: an operation named `op` mounted
     * on a resource exposed at a path already containing `/op/` would have both occurrences rewritten,
     * and the successor would point at a URL that does not exist. One replacement, leftmost, matching
     * {@see ParticleSlotCollisionAudit}'s own collapse.
     */
    protected function successor(Request $request): string
    {
        $path = '/'.ltrim($request->path(), '/');

        return preg_replace('#/op/#', '/', $path, 1) ?? $path;
    }
}
