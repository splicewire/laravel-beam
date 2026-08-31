<?php

namespace Splicewire\Beam\Discovery\Http;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Routing\Route;
use Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData;
use Splicewire\Beam\Data\ResponseBody;
use Splicewire\Beam\Discovery\Data\ResourceDiscoveryData;
use Splicewire\Beam\Discovery\Data\ResourceDiscoveryEntryData;
use Splicewire\Beam\Discovery\ResourceMount;
use Splicewire\Beam\Discovery\ResourceMountMap;
use Splicewire\Beam\Discovery\RouteReachability;
use Splicewire\Beam\Discovery\SubSurface;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Particle\Delivery\DeliveryResolvers;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Routing\RouteMetadataReader;
use Splicewire\Beam\Webhooks\Http\HookEventCatalogController;

/**
 * `GET /{resource}/discovery` — the converged per-resource listing (api-surface-coherence 105).
 *
 * ## One route class, every mount — and the mount is a fact about the ROUTE
 *
 * The mount this request is for is read off the route's own `_particle_discovery` default, the same way
 * {@see HookEventCatalogController} reads its resource off `_particle`.
 * Nothing is parsed out of the URL and nothing is accepted from the query string: a caller cannot widen
 * its own listing to a mount it did not reach.
 *
 * ## It is a route-table READ — there is no registry union here
 *
 * 41's re-appraisal priced this as a union over N registries and found it blocked by the registry
 * kernel's no-wildcard-root constraint. The constraint is real and irrelevant: every sub-surface stamps
 * its resource onto its routes, so the join has already happened in the route table.
 * {@see ResourceMountMap} does the read; this class projects it.
 *
 * ## ⚠️ Existence, not invocability
 *
 * An entry says the operation is THERE and that this caller passes the route's own gate. It does not
 * say the caller may run it against any particular record. `/circuits/{id}/op/run` authorizes against a
 * specific circuit and this listing has no `{id}`, so an instance-scoped refusal still happens at
 * invoke time, as a 403. 41 D6 made that the stated contract rather than a surprise.
 *
 * @group Platform
 */
class ResourceDiscoveryController extends Controller
{
    /**
     * The route default a discovery mount carries: `['resource' => …, 'mount' => …]`.
     *
     * Two facts rather than one, because the resource alone does not identify the mount — `hooks` is
     * live at `api/v1/hooks` and at `api/operator/hooks`, and the whole point of 41 D5 is that those are
     * two listings.
     */
    public const CONFIG = '_particle_discovery';

    public function __construct(
        protected ResourceMountMap $map,
        protected RouteReachability $reachability,
        protected RouteMetadataReader $meta,
    ) {}

    /**
     * What this caller can reach on this resource
     *
     * Every route mounted on this resource at this exposure — its CRUD surface, its named operations,
     * its filter sub-surface and its subscribable events — filtered to what the authenticated caller's
     * own abilities admit. An operation that declares a `delivery:` also carries what it puts on the
     * wire, which is where the dissolved `GET {resource}/renderings` catalog's facts now live
     * (particle-operation-surface 13).
     *
     * The reference documents what exists; this documents what YOU can reach, which is the one question
     * a build-time artifact structurally cannot answer.
     *
     * <aside class="warning">An entry publishes an operation's <b>existence</b>. Routes that authorize
     * against a specific record cannot be judged by a listing that carries no record id, so such a route
     * is listed and the invoke may still be refused with a 403.</aside>
     */
    #[ResponseFromData(ResourceDiscoveryData::class)]
    public function index(Request $request)
    {
        $route = $request->route();
        $config = ($route instanceof Route ? $route->defaults[self::CONFIG] ?? null : null);
        $resource = is_array($config) ? (string) ($config['resource'] ?? '') : '';
        $root = is_array($config) ? (string) ($config['mount'] ?? '') : '';

        $mount = $this->mountFor($resource, $root);
        $user = $request->user();

        $entries = [];

        foreach ($mount?->routes ?? [] as $mounted) {
            if (! $this->reachability->allows($mounted, $user)) {
                continue;
            }

            $entries[] = $this->entry($mounted);
        }

        // The listing lists itself. It is a route on the mount like any other, and a client walking the
        // surface should not have to special-case the door it came through.
        if ($mount !== null && $route instanceof Route) {
            $entries[] = $this->entry($route);
        }

        $present = array_map(fn (ResourceDiscoveryEntryData $entry) => $entry->subSurface, $entries);

        return ResponseBody::from(['data' => new ResourceDiscoveryData(
            resource: $resource,
            mount: $root,
            entries: $entries,
            subSurfaces: array_values(array_filter(
                SubSurface::ALL,
                fn (string $surface) => in_array($surface, $present, true),
            )),
        )]);
    }

    /**
     * The mount named by the route's own default, re-derived from the live route table.
     *
     * Re-derived rather than cached at mount time on purpose: a host that adds routes after boot (a
     * package registering from a later provider, a test binding a route mid-run) gets an honest listing
     * instead of a snapshot taken before its routes existed.
     */
    protected function mountFor(string $resource, string $root): ?ResourceMount
    {
        foreach ($this->map->mounts() as $mount) {
            if ($mount->resource === $resource && $mount->root === $root) {
                return $mount;
            }
        }

        return null;
    }

    protected function entry(Route $route): ResourceDiscoveryEntryData
    {
        $operation = $route->defaults[ParticleOperationController::NAME] ?? null;
        $delivery = $this->delivery($route);

        return new ResourceDiscoveryEntryData(
            subSurface: SubSurface::of($route),
            methods: array_values(array_diff($route->methods(), ['HEAD'])),
            uri: $route->uri(),
            name: $route->getName(),
            operation: is_string($operation) ? $operation : null,
            operationId: $this->meta->operationId($route),
            returns: $this->meta->returns($route),
            returnsMany: $this->meta->returnsMany($route),
            declaresDelivery: $delivery !== null,
            formats: $delivery['formats'] ?? [],
            mediaTypes: $delivery['mediaTypes'] ?? [],
            deliveryHeaders: $delivery['headers'] ?? [],
            defaultFormat: $delivery['default'] ?? null,
        );
    }

    /**
     * What an operation route says it puts on the wire, or null for anything that is not a declaring
     * operation (particle-operation-surface 13).
     *
     * This is the rendering catalog's job, inherited: `GET {resource}/renderings` published the same
     * four facts for the three endpoints that registry mounted, and 13 turned those three into
     * operations. Resolved through {@see DeliveryResolvers} — the one reader of the slot — rather than
     * by touching `DeclaresDelivery` here, so the enforced set, the published set and this listing are
     * all the same `formats()` expression.
     *
     * `find()` rather than `get()`: an operation route mounted by bare name against a registry this
     * host never filled is a listing entry with no delivery, not a 500 on a discovery read.
     *
     * @return array{mediaTypes: list<string>, headers: array<string, string>, default: ?string, formats: list<string>}|null
     */
    protected function delivery(Route $route): ?array
    {
        $resource = $route->defaults[ParticleOperationController::RESOURCE] ?? null;
        $name = $route->defaults[ParticleOperationController::NAME] ?? null;

        if (! is_string($resource) || ! is_string($name)) {
            return null;
        }

        $operation = app(ParticleOperationRegistry::class)->find($resource, $name);

        return $operation === null ? null : DeliveryResolvers::contract($operation);
    }
}
