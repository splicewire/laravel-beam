<?php

namespace Splicewire\Beam\Doctor;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Rendering\Http\RenderingsController;

/**
 * **The instrument that has to exist before `/op/` can be dropped** (particle-operation-surface 05).
 *
 * `Route::particleOp()` mounts `{uri}/{id}/op/{name}`. Ticket 05 removes that `op` segment, which lands
 * every operation in the slot `{resource}/{id}/{segment}` — a slot the estate is already using. The
 * ticket's own framing was *renderings versus operations*; measured across twenty booted hosts, the slot
 * has **three** claimant classes, and only one instrument can see all three:
 *
 * - **Renderings** — `Route::resourceRenderings()` mounts `GET|POST {at}/{id}/{rendering}`, keyed under
 *   the popcorn root `beam.renderings`.
 * - **Operations** — keyed under `beam.particle.operations`. A *different* root, so `OnDuplicate` is
 *   structurally blind to a rendering with the same name, whatever either registry declares.
 * - **Hand-written routes, in no registry at all.** This is the class the ticket does not name and the
 *   one that decides the design. At `~/Herd/splicewire-app`, `POST api/v1/circuits/{id}/intake` sits in
 *   the same slot as the `circuits.export` rendering and the `circuits.run` operation — three claimants,
 *   one of them unknown to every registry. Merging the two registries (ticket 06) would still not see it.
 *
 * So the check is over the **real route table**, not over any registry: the collision is created by the
 * MOUNT, and the route table is the only place all three mounts are visible at once.
 *
 * ## Why it reports the collision that WOULD happen, not one that has
 *
 * The audit normalises every route to its post-drop spelling — `/op/` collapsed out of the URI, `.op.`
 * out of the route name — and looks for duplicates in *that* keyspace. It is therefore useful while
 * `/op/` is still mounted, which is the only time it can be: once the drop lands, a collision is a live
 * outage rather than a work-list line. Measured 2026-08-27 across every bootable host in the estate
 * (`~/Herd/*` on disk, twenty route tables, 2,997 routes, 67 of them under `/op/`): **zero collisions on
 * either axis.** The drop is safe today; this is what keeps it safe tomorrow.
 *
 * ## The two axes are different failures, and the name axis is the quieter one
 *
 * A **URI** collision resolves by registration order — Laravel matches the first route registered, so the
 * second claimant simply stops answering, with a 200 on the wrong handler rather than an error.
 *
 * A **name** collision is worse, because the two lookups disagree. `RouteCollection::addLookups()`
 * overwrites the name map, so `route('circuits.run')` generates the URL of the LAST route registered
 * while a request to that URL matches the FIRST. Laravel does catch it — in
 * `AbstractRouteCollection::addToSymfonyRoutesCollection()` — but only when the collection is dumped, i.e.
 * at `route:cache`. That is a deploy-time guard on a host that caches routes, and no guard at all on one
 * that does not.
 *
 * ## Why advisory, and why it stays at zero
 *
 * Whether two mounts land in the same slot is a fact about the **host** — a package declares an
 * operation, a package declares a rendering, and the host decides whether to expose both at the same
 * `at`. The estate's rule is that such a check reports rather than throws (`EventCatalogPrefixAudit` is
 * the instance that took `~/Herd/tower` off the air by getting this backwards).
 *
 * It is scoped to collisions where **at least one claimant is a particle operation**, which is the
 * discriminator that keeps the normal reading empty. Two hand-written routes sharing a slot is a
 * pre-existing host decision that this ticket did not create and cannot judge — `prognosix-api`'s
 * `OPTIONS` catch-all and `prognosix-web-app`'s `settings` redirect are both legitimate, and both are
 * correctly invisible here.
 */
class ParticleSlotCollisionAudit implements DoctorAudit
{
    public const CHECK = 'particle.slot-collision';

    public function __construct(private Router $router) {}

    /** @return list<Finding> */
    public function run(): array
    {
        $operations = 0;
        $byUri = [];
        $byName = [];

        foreach ($this->router->getRoutes() as $route) {
            /** @var Route $route */
            if ($this->isOperation($route)) {
                $operations++;
            }

            $uri = $this->collapseUri($route->uri());
            $domain = $route->getDomain() ?? '';

            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }

                $byUri[$domain.'|'.$method.' /'.$uri][] = $route;
            }

            $name = $route->getName();

            if (is_string($name) && $name !== '') {
                $byName[$this->collapseName($name)][] = $route;
            }
        }

        if ($operations === 0) {
            return [Finding::pass(self::CHECK, 'No particle operation is mounted on this host — the slot `{resource}/{id}/{name}` has nothing to collide over.')];
        }

        $rows = array_merge(
            $this->collisions($byUri, 'URI'),
            $this->collisions($byName, 'route name'),
        );

        if ($rows === []) {
            return [Finding::pass(self::CHECK, sprintf(
                '%d mounted particle operation%s; dropping the `/op/` segment collides with no rendering, CRUD verb or hand-written route on this host, on either the URI or the route-name axis.',
                $operations,
                $operations === 1 ? '' : 's',
            ))];
        }

        return [Finding::warn(self::CHECK, sprintf(
            '%d slot collision%s would be created by dropping the `/op/` segment: %s. A URI collision resolves '
                .'by registration order — the second claimant silently stops answering; a route-name collision '
                .'generates one URL and matches the other, and Laravel only catches it at `route:cache`. Rename '
                .'one claimant, or expose them at different `at` prefixes.',
            count($rows),
            count($rows) === 1 ? '' : 's',
            implode('; ', $rows),
        ))];
    }

    /**
     * @param  array<string, list<Route>>  $groups
     * @return list<string>
     */
    private function collisions(array $groups, string $axis): array
    {
        $rows = [];

        foreach ($groups as $key => $routes) {
            $distinct = [];

            foreach ($routes as $route) {
                $distinct[$this->identity($route)] = $route;
            }

            if (count($distinct) < 2) {
                continue;
            }

            // The discriminator. A slot shared by two routes neither of which is an operation is a
            // host decision the `/op/` drop neither creates nor disturbs.
            if (! array_filter($distinct, fn (Route $route) => $this->isOperation($route))) {
                continue;
            }

            $rows[] = sprintf('%s [%s] claimed by %s', $axis, $key, implode(' + ', array_map(
                fn (Route $route) => sprintf('%s (%s)', $this->label($route), $this->claimant($route)),
                array_values($distinct),
            )));
        }

        return $rows;
    }

    private function isOperation(Route $route): bool
    {
        return isset($route->defaults[ParticleOperationController::RESOURCE])
            && isset($route->defaults[ParticleOperationController::NAME]);
    }

    /**
     * Which of the three claimant classes this route belongs to. `hand-written` is not a fallback for
     * "unrecognised" — it is the third class, and naming it is half the finding's value.
     */
    private function claimant(Route $route): string
    {
        return match (true) {
            $this->isOperation($route) => 'operation',
            isset($route->defaults[RenderingsController::CONFIG]) => 'rendering',
            isset($route->defaults[ParticleController::RESOURCE]) => 'particle CRUD',
            default => 'hand-written',
        };
    }

    private function collapseUri(string $uri): string
    {
        return preg_replace('#/op/#', '/', $uri, 1) ?? $uri;
    }

    private function collapseName(string $name): string
    {
        return str_replace('.op.', '.', $name);
    }

    private function identity(Route $route): string
    {
        return implode(' ', $route->methods()).' '.$route->uri().' '.($route->getName() ?? '-');
    }

    private function label(Route $route): string
    {
        return sprintf('%s %s', $route->methods()[0] ?? 'GET', $route->uri());
    }
}
