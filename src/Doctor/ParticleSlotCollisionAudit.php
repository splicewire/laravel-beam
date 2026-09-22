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
 * Reports actual URI and route-name collisions involving a mounted particle operation.
 *
 * The route table includes CRUD and hand-written claimants that no operation registry can see.
 * URI slots include domain and method; route names share one namespace. Literal `op` segments
 * and name components are ordinary parts of those keys and are never normalized away.
 * Host collisions are advisory; routes marked Deprecated retain the generic visibility exclusion.
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
            if ($this->meta->visibility($route) === RouteVisibility::Deprecated) {
                continue;
            }

            if ($this->isOperation($route)) {
                $operations++;
            }

            $uri = $route->uri();
            $domain = $route->getDomain() ?? '';

            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }

                $byUri[$domain.'|'.$method.' /'.$uri][] = $route;
            }

            $name = $route->getName();

            if (is_string($name) && $name !== '') {
                $byName[$name][] = $route;
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
                '%d mounted particle operation%s at `{resource}/{id}/{name}`; none collides with a CRUD verb or hand-written route on this host, on either the URI or the route-name axis.',
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
            // host decision outside this audit's operation scope.
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

    private function identity(Route $route): string
    {
        return implode(' ', $route->methods()).' '.$route->uri().' '.($route->getName() ?? '-');
    }

    private function label(Route $route): string
    {
        return sprintf('%s %s', $route->methods()[0] ?? 'GET', $route->uri());
    }
}
