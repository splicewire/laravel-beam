<?php

namespace Splicewire\Beam\Webhooks\Http;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData;
use Splicewire\Beam\Data\ResponseBody;
use Splicewire\Beam\Events\EventTypeRegistry;
use Splicewire\Beam\Webhooks\Data\EventCatalogData;
use Splicewire\Beam\Webhooks\Data\EventTypeDescriptorData;

/**
 * `GET /hooks/events` and `GET /{resource}/hooks/events` — the vocabulary a subscription may name
 * (api-surface-coherence ticket 38, decided by 12 §3).
 *
 * **One route class, both exposures.** The scoped mount passes `{resource}` and this filters; the
 * root mount does not and this does not. 12 §3 is explicit that it is one route, and the reason is
 * the failure mode of the alternative: two endpoints reading one registry drift the moment somebody
 * fixes a projection in only one of them.
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
    public function __construct(private EventTypeRegistry $registry) {}

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
     * Read off the route PARAMETER rather than the query string: the scoped exposure is
     * `/{resource}/hooks/events`, a path segment, and reading it from `?resource=` too would give
     * the root exposure a second, unmounted way to be scoped that no route declares.
     */
    protected function resourceFromRoute(Request $request): ?string
    {
        $resource = $request->route()?->parameter('resource');

        return is_string($resource) && $resource !== '' ? $resource : null;
    }
}
