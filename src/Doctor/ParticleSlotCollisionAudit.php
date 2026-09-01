<?php

namespace Splicewire\Beam\Doctor;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Routing\RouteMetadataReader;
use Splicewire\Beam\Routing\RouteVisibility;

/**
 * **The instrument that has to exist before `/op/` can be dropped** (particle-operation-surface 05).
 *
 * `Route::particleOp()` mounts `{uri}/{id}/op/{name}`. Ticket 05 removes that `op` segment, which lands
 * every operation in the slot `{resource}/{id}/{segment}` — a slot the estate is already using. The
 * ticket's own framing was *renderings versus operations*; measured across twenty booted hosts, the slot
 * has **three** claimant classes, and only one instrument can see all three:
 *
 * - **Renderings** — `Particle::renderings()` mounted `GET|POST {at}/{id}/{rendering}`, keyed under the
 *   popcorn root `beam.renderings`. ⚠️ **Dissolved by particle-operation-surface 13**, which re-declared
 *   the estate's three renderings as operations; the class survives here as the reason the audit reads
 *   the ROUTE TABLE rather than a registry, and because a host may still hand-write the same slot.
 * - **Operations** — keyed under `beam.particle.operations`. A *different* root from the rendering one,
 *   so `OnDuplicate` was structurally blind to a rendering with the same name, whatever either registry
 *   declared.
 * - **Hand-written routes, in no registry at all.** This is the class the ticket does not name and the
 *   one that decides the design. At `~/Herd/splicewire-app`, `POST api/v1/circuits/{id}/intake` sits in
 *   the same slot as the `circuits.export` rendering and the `circuits.run` operation — three claimants,
 *   one of them unknown to every registry. Merging the two registries (ticket 06) would still not see it.
 *
 * So the check is over the **real route table**, not over any registry: the collision is created by the
 * MOUNT, and the route table is the only place all three mounts are visible at once.
 *
 * ## It reported the collision that WOULD happen; since the drop landed it reports one that HAS
 *
 * ⚠️ **The tense changed on 2026-08-29 and the code did not have to.** Ticket 12 executed the drop, so
 * the primary mount is now `{uri}/{id}/{op}` and the normalisation below is a no-op on it. What the
 * audit watches is unchanged — the slot `{uri}/{id}/{segment}` — but a finding here is now a live
 * outage rather than a work-list line, which is what the paragraph below always said would happen.
 *
 * The one code change the drop forced is the `Deprecated` skip in {@see run()}: the legacy `/op/` alias
 * is the same operation mounted a second time, and collapsing it would make every operation in the
 * estate collide with itself.
 *
 * The audit normalises every route to its post-drop spelling — `/op/` collapsed out of the URI, `.op.`
 * out of the route name — and looks for duplicates in *that* keyspace. Measured 2026-08-27 across the
 * estate before the drop (twenty route tables, 2,997 routes, 67 under `/op/`) and RE-measured 2026-08-29
 * immediately before executing it (`~/Herd/*` enumerated on disk with symlinks resolved, **21 bootable
 * hosts, 3,135 routes, 61 under `/op/`**, the one unbootable root being `numero-legacy`): **zero
 * collisions on either axis, both times.** Neither figure was carried forward; the second is not a
 * refinement of the first but a different estate, which is why the ticket refused to key an acceptance
 * criterion to the integer.
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

    public function __construct(
        private Router $router,
        private RouteMetadataReader $meta,
    ) {}

    /** @return list<Finding> */
    public function run(): array
    {
        $operations = 0;
        $byUri = [];
        $byName = [];

        foreach ($this->router->getRoutes() as $route) {
            /** @var Route $route */
            // ⚠️ The deprecated `/op/` alias is skipped ENTIRELY, and this line is the difference between
            // this audit staying useful and it reporting a permanent false positive at every host in the
            // estate. Since particle-operation-surface 12, `ParticleMounter::op()` mounts each operation
            // twice — `{uri}/{id}/{op}` and the legacy `{uri}/{id}/op/{op}`. Collapsed into this audit's
            // post-drop keyspace those two are the SAME uri and (once `.op.` collapses) the same name, so
            // without this skip every operation in the estate would report as colliding with itself, on
            // both axes at once. That is not the collision this audit is for: an alias is one operation
            // spelled twice on purpose, not two claimants to one slot.
            if ($this->meta->visibility($route) === RouteVisibility::Deprecated) {
                continue;
            }

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
            return [Finding::inconclusive(self::CHECK, 'No particle operation is mounted on this host — the slot `{resource}/{id}/{name}` has nothing to collide over.')];
        }

        $rows = array_merge(
            $this->collisions($byUri, 'URI'),
            $this->collisions($byName, 'route name'),
        );

        if ($rows === []) {
            return [Finding::pass(self::CHECK, sprintf(
                '%d mounted particle operation%s at `{resource}/{id}/{name}`; none collides with a rendering, CRUD verb or hand-written route on this host, on either the URI or the route-name axis. (Deprecated `/op/` aliases are excluded — an alias is one operation spelled twice, not two claimants.)',
                $operations,
                $operations === 1 ? '' : 's',
            ))];
        }

        return [Finding::warn(self::CHECK, sprintf(
            '%d slot collision%s in the particle-operation slot `{resource}/{id}/{name}`: %s. A URI collision resolves '
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
     * Which claimant class this route belongs to. `hand-written` is not a fallback for "unrecognised"
     * — it is a real class, and naming it is half the finding's value. The `rendering` claimant went
     * with the rendering subsystem (particle-operation-surface 13); such a route now reads as
     * `operation` or, if hand-written, as itself.
     */
    private function claimant(Route $route): string
    {
        return match (true) {
            $this->isOperation($route) => 'operation',
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
