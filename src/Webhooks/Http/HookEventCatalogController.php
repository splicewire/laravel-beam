<?php

namespace Splicewire\Beam\Webhooks\Http;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData;
use Splicewire\Beam\Data\ResponseBody;
use Splicewire\Beam\Events\EventTypeRegistry;
use Splicewire\Beam\Routing\RouteMetadataReader;
use Splicewire\Beam\Webhooks\Data\EventCatalogData;
use Splicewire\Beam\Webhooks\Data\EventTypeDescriptorData;

/**
 * `GET /hooks/events` and `GET /{resource}/hooks/events` — the vocabulary a subscription may name
 * (api-surface-coherence ticket 38, decided by 12 §3).
 *
 * **One route class, every exposure.** The scoped mounts carry a resource and this filters; the root
 * mount does not and this does not. 12 §3 is explicit that it is one route, and the reason is the
 * failure mode of the alternative: two endpoints reading one registry drift the moment somebody fixes
 * a projection in only one of them. That still holds with N scoped mounts instead of one — they are N
 * routes at one action, which is the shape `ParticleController` already has.
 *
 * ## The resource comes from the STAMP, not from the URL (api-surface-coherence 106)
 *
 * The scoped exposure used to be a single wildcard `GET /{resource}/hooks/events` reading its resource
 * off a request-time path parameter. That made the route unanswerable to
 * {@see RouteMetadataReader::resourceKey()} — a route-LEVEL reader that grouping and doc extraction consume
 * — and unfixably so: a wildcard route has no single key by construction, it has 39. Ticket 41 D7 split
 * it into concrete per-resource mounts off the same `_particle` stamp the filter sub-surface uses, so
 * the resource is a fact about the ROUTE and this reads it the same way every other particle surface
 * does.
 *
 * The root mount declares its unscoped-ness explicitly, as {@see CONFIG}, rather than being recognised
 * by the absence of a stamp — the same spelling the Frame resource root's filter mount uses for the same
 * reason. An unstamped, undeclared sub-surface route is now a conformance failure, and a surface that
 * is legitimately resource-less has to say so.
 *
 * ## It READS the catalog; it does not keep a second list
 *
 * Ticket 40 built {@see EventTypeRegistry} and made every arm attach its own registrar. The whole
 * value of that is destroyed by any surface that enumerates event names for itself, so this endpoint
 * has no list, no config key and no allowlist — it projects `all()` or `withPrefix()` and nothing
 * else.
 *
 * ## Nothing here throws on a host-dependent fact (ticket 91)
 *
 * The catalog's prefix check went advisory after a fatal host-dependent check took `~/Herd/tower`
 * off the air, and this endpoint is exactly that shape one layer up: `{resource}` arrives from a URL
 * and may name a resource this host does not have. That answers with an EMPTY catalog, not a 404 and
 * not a 500 — `withPrefix()` on an unknown key is already a legal empty read, and a subscription
 * surface that 500s because a client asked about a resource the host did not install would be the
 * same defect wearing a different hat.
 *
 * @group Platform
 */
class HookEventCatalogController extends Controller
{
    /**
     * The route default an UNSCOPED mount declares: `['resource' => null]`.
     *
     * Not decoration. It is what lets a conformance test be total over the sub-surface routes without
     * carrying an exemption list — the two legitimate resource-less shapes in the estate (this root
     * catalog, and the Frame resource root's filter mount) each SAY so at the mount, and everything
     * else must resolve a resource key.
     */
    public const CONFIG = '_hook_event_catalog';

    public function __construct(
        private EventTypeRegistry $registry,
        private RouteMetadataReader $meta,
    ) {}

    /**
     * Subscribable event types
     *
     * Every event name a hook's `events` array may legally contain, with the resource it hangs off
     * and one line of prose about when it fires. Filtered to a single resource when reached through
     * the scoped exposure.
     */
    #[ResponseFromData(EventCatalogData::class)]
    public function index(Request $request)
    {
        $resource = $this->resourceFromRoute($request);

        $types = $resource === null
            ? $this->registry->all()
            : $this->registry->withPrefix($resource);

        return ResponseBody::from(['data' => new EventCatalogData(
            resource: $resource,
            events: array_values(array_map(
                fn ($type) => EventTypeDescriptorData::fromEventType($type),
                $types,
            )),
        )]);
    }

    /**
     * The resource key this request is scoped to, or null at the root exposure.
     *
     * Read off the route's `_particle` STAMP — the same default every other particle surface is keyed
     * by — rather than off a path parameter or the query string. Ticket 106: the mount decides which
     * resource an exposure is for, at mount time; a request cannot widen or redirect its own scope.
     */
    protected function resourceFromRoute(Request $request): ?string
    {
        $route = $request->route();

        return $route === null ? null : $this->meta->resourceKey($route);
    }
}
