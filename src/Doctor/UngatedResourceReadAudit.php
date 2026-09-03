<?php

namespace Splicewire\Beam\Doctor;

use Illuminate\Routing\Route as RouteInstance;
use Illuminate\Routing\Router;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Authorization\ResourceReadGuard;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * A particle resource whose LIST read is gated by nothing — no predicate in its base query, no policy on
 * its model, no tenancy on its mount (beam-docs-satellite 65).
 *
 * {@see ParticleController::denyUngatedRead()} fails that read closed at
 * REQUEST time — a caller gets 403, not every row. This audit is the advisory that tells a host which
 * mounts would answer 403 before a caller finds out: it walks the BOOTED registry and the FINISHED route
 * table, so it reaches every `filterable` resource that never spells `filterable: true` (the attribute
 * defaults to it — which is why 65's source grep undercounted, and why this reads the registry rather than
 * the code), and a hand-written index outside `Particle::mount()` alike.
 *
 * ## Why an advisory
 *
 * Whether a mount carries tenancy and whether a policy is bound are facts about the HOST. A package
 * declaring an unscoped resource is not wrong on its own — audiostud supplies the `ranks` scope in its
 * own `RanksQuery`; the flagship supplies `hooks`' in the tenant stack — so this is a work-list, never a
 * Fail (AGENTS.md: a check whose answer depends on the host must not throw).
 *
 * ## What it reports, in three kinds
 *
 * - **Live**, one row per route: an index route stamped for the resource, standalone (not under a
 *   relative edge), whose resolved middleware initializes no tenancy, over a resource that is unscoped
 *   AND policy-less. This is the 65 shape on the wire today.
 * - **Latent**, one row for all of them: unscoped, policy-less resources with no standalone index route
 *   on this host. "0 rows today" is why a finding is latent, never why it is absent — a bare mount of any
 *   of them answers 403 the day someone adds it, and a host should know that before that day.
 * - **Did not look**, one inconclusive row with the count: resources the guard could not read — a
 *   backing with no model, a filterable key with no data-filters registration, a base that cannot be
 *   built at audit time. Counted and named, so a clean pass over an unreadable population cannot pass
 *   as a measurement.
 */
class UngatedResourceReadAudit implements DoctorAudit
{
    public const CHECK = 'particle.ungated-read';

    public function __construct(
        private Router $router,
        private ParticleResourceRegistry $resources,
        private ResourceReadGuard $guard,
    ) {}

    public static function forApp(): self
    {
        return new self(app(Router::class), app(ParticleResourceRegistry::class), ResourceReadGuard::forApp());
    }

    /** @return list<Finding> */
    public function run(): array
    {
        $resources = $this->resources->all();

        if ($resources === []) {
            return [Finding::inconclusive(self::CHECK, 'No particle resource is registered on this host — nothing has a list read to gate.')];
        }

        $indexes = $this->standaloneIndexRoutes();

        $live = [];
        $latent = [];
        $unread = [];
        $scoped = 0;
        $policyBound = 0;
        $tenancyMounted = 0;

        foreach ($resources as $resource) {
            $isScoped = $this->guard->scoped($resource);
            $isPolicyBound = $this->guard->policyBound($resource);

            if ($isScoped === null || $isPolicyBound === null) {
                $unread[] = sprintf('[%s]: %s', $resource->key, $this->whyUnread($resource, $isScoped, $isPolicyBound));

                continue;
            }

            if ($isScoped) {
                $scoped++;
            }

            if ($isPolicyBound) {
                $policyBound++;
            }

            if ($isScoped || $isPolicyBound) {
                continue;
            }

            $mounts = $indexes[$resource->key] ?? [];

            if ($mounts === []) {
                $latent[] = $resource->key;

                continue;
            }

            foreach ($mounts as $route) {
                if (ResourceReadGuard::suppliesScope($this->resolvedMiddleware($route))) {
                    $tenancyMounted++;

                    continue;
                }

                $live[] = Finding::warn(self::CHECK, sprintf(
                    '%s (resource [%s]) is gated by nothing at GET %s: its list base carries no predicate, no policy '
                    .'is bound for [%s], and the route\'s middleware initializes no tenancy — so the index answers '
                    .'403 to every caller a `Gate::before` does not wave through (beam-docs-satellite 65). Scope the '
                    .'read (a predicate in its data-filters base query, or a `scope` closure on a non-filterable '
                    .'resource), bind a policy for the model, or mount it inside a tenancy group.',
                    $resource->data ?? '(no Data class)',
                    $resource->key,
                    $route->uri(),
                    $resource->modelClass() ?? '(no model)',
                ));
            }
        }

        $findings = $live;

        if ($latent !== []) {
            sort($latent);

            $findings[] = Finding::warn(self::CHECK, sprintf(
                '%d resource%s %s unscoped and policy-less with no standalone index mounted on this host — latent, '
                .'not absent: a bare `Particle::mount()` of any of them answers 403 until it is scoped, '
                .'policy-bound, or mounted under tenancy. %s',
                count($latent),
                count($latent) === 1 ? '' : 's',
                count($latent) === 1 ? 'is' : 'are',
                implode(', ', array_map(static fn (string $key): string => "[{$key}]", $latent)),
            ));
        }

        $unreadDetail = $unread === [] ? '' : sprintf(
            '%d resource%s could not be read — not passed, not warned, not looked at: %s',
            count($unread),
            count($unread) === 1 ? '' : 's',
            implode('; ', $unread),
        );

        if ($findings !== []) {
            if ($unread !== []) {
                $findings[] = Finding::inconclusive(self::CHECK, $unreadDetail);
            }

            return $findings;
        }

        $summary = sprintf(
            '%d particle resource%s read: %d scoped, %d policy-bound, %d ungated mount%s under tenancy; no list read is '
            .'gated by nothing.',
            count($resources) - count($unread),
            count($resources) - count($unread) === 1 ? '' : 's',
            $scoped,
            $policyBound,
            $tenancyMounted,
            $tenancyMounted === 1 ? '' : 's',
        );

        return [$unread === []
            ? Finding::pass(self::CHECK, $summary)
            : Finding::inconclusive(self::CHECK, $summary.' Partial: '.$unreadDetail)];
    }

    /**
     * Every `index` route stamped `_particle`, keyed by resource, EXCLUDING routes under a relative edge —
     * those list through a bound parent (`_particle_relative` on the route's defaults) and are not bare
     * reads.
     *
     * @return array<string, list<RouteInstance>>
     */
    private function standaloneIndexRoutes(): array
    {
        $indexes = [];

        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            $key = $route->defaults[ParticleController::RESOURCE] ?? null;

            if (! is_string($key) || ! in_array('GET', $route->methods(), true)) {
                continue;
            }

            if (isset($route->defaults[ParticleController::RELATIVE]) || isset($route->defaults[ParticleController::RELATIVE_MODEL])) {
                continue;
            }

            $action = $route->getActionMethod();

            if ($action !== 'index') {
                continue;
            }

            $indexes[$key][] = $route;
        }

        return $indexes;
    }

    /** @return list<string> */
    private function resolvedMiddleware(RouteInstance $route): array
    {
        $resolved = [];

        foreach ([...$route->middleware(), ...$this->router->gatherRouteMiddleware($route)] as $entry) {
            $resolved[] = is_string($entry) ? $entry : (is_object($entry) ? $entry::class : (string) json_encode($entry));
        }

        return array_values(array_unique($resolved));
    }

    private function whyUnread(ParticleResource $resource, ?bool $scoped, ?bool $policyBound): string
    {
        if ($policyBound === null) {
            return 'its backing names no Eloquent model, so neither a policy nor a row predicate applies';
        }

        if (! $resource->filterable) {
            return 'its scope could not be read';
        }

        return $this->guard->filterRegistered($resource)
            ? 'filterable, and its data-filters base query could not be built here (the base needs a request or a tenant connection)'
            : 'filterable with no data-filters registration under the key — its index throws before any gate is reached';
    }
}
