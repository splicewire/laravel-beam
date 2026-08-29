<?php

namespace Splicewire\Beam\Doctor;

use Illuminate\Routing\Router;
use Rushing\DataFilters\Registry\ResourceRegistry as FilterResourceRegistry;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Discovery\ResourceMountMap;
use Splicewire\Beam\Discovery\SubSurface;
use Splicewire\Beam\Filters\Http\ResourceFiltersController;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Particle\Attributes\ParticleResource as ParticleResourceAttribute;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Routing\BeamRouteAction;

/**
 * **A `filterable` resource with no data-filters resource behind it** — the promise made by *not opting
 * out*.
 *
 * {@see ParticleResourceAttribute::__construct()} defaults `$filterable` to **true**. A filterable
 * resource's index does not compose its own query:
 *
 * ```php
 * // ParticleController::index()
 * $query = $resource->filterable
 *     ? $this->hydrator->query($resource->key, $ctx)     // ← data-filters, or BadMethodCallException
 *     : ($relativeQuery ?? $this->defaultSortedQuery($resource, $facets));
 * ```
 *
 * `hydrator->query()` looks the key up in `rushing/laravel-data-filters`' own `ResourceRegistry` and
 * raises on a miss:
 *
 * ```
 * No data-filters resource is registered under [<key>], so no list query can be composed for it.
 * ```
 *
 * The `filters/*` sub-surface resolves the same definition ({@see ResourceFiltersController::definition()}),
 * so it raises on exactly the same keys.
 *
 * ## Why this is worth an audit rather than four more fixes
 *
 * Three live 500s were repaired by hand on 2026-08-29 — `calendars`, `calendar-events`, `calendar-series`
 * in `splicewire/laravel-beam-calendars` (b1a9cd9), and `hooks` in this package (9717817). **Not one of
 * the four declarations said `filterable: true`.** They made the promise by omission, which means a
 * reviewer reading `#[ParticleResource(key: 'hooks', backing: Hook::class)]` has no token on the line to
 * notice. A source grep is worse than useless here: the defect is a *default*, so the thing to grep for
 * is not written down, and the counterpart registration may arrive from host config or from
 * `#[ResourceFilter]` discovery rather than from any file a grep would reach. Both halves of the question
 * are answerable only against the **booted registries**, which is what this class reads.
 *
 * ## Advisory, permanently — the textbook case
 *
 * Both halves of the population are facts about the **host**. Which particle resources exist here is
 * whatever packages this composition installs; which data-filters resources exist here is
 * `config('data-filters.resources')` plus `config('data-filters.discover')` plus whatever each installed
 * provider registered. The declaring package cannot know either. That is
 * `rushing/laravel-doctor/docs/agents/gate-or-advisory.convention.md`'s named advisory case, and
 * AGENTS.md's standing rule — *"a check whose answer depends on the host must not throw"* — with
 * {@see EventCatalogPrefixAudit} as the instance that took `~/Herd/tower` off the air by getting it
 * backwards. A host that wants this to block registers the class in its own manifest with `gate: true`.
 *
 * ## Latent vs. live, and why the obvious split is wrong
 *
 * An unregistered key only raises when something actually *routes* through the lookup, so the finding
 * splits the population. The split is **not** "does the resource have any route" — that reading was
 * measured and it is wrong in the alarming direction. On the flagship, `schemas`, `ingest-runs` and
 * `plans` all carry stamped routes ({@see ResourceMountMap} reports mounts for
 * all three) and **none of them can raise**: `ingest-runs` mounts `show` + `hooks/events` + `discovery`,
 * `plans` mounts one operation + `discovery`, and `schemas` is served by Tower's own controller and never
 * touches {@see ParticleController} at all. A mount-map answer would have reported three live 500s that
 * do not exist.
 *
 * So the predicate is narrow and names the two throwing paths directly: a route bound to
 * `ParticleController@index`, or a route in {@see SubSurface::FILTERS}. Both are read off the route table,
 * which is the same join `ResourceMountMap`'s docblock argues for — the mount is not stored anywhere, the
 * routes are.
 *
 * ⚠️ **The route table this reads is the one THIS process built.** A host that registers routes
 * conditionally — per tenant, per realm, behind a feature flag — can have a route in an HTTP request that
 * a CLI `artisan` run never mounts, and this audit would call such a key latent. The finding says so in
 * its own text rather than leaving the reader to assume the split is absolute.
 *
 * ## What a zero means
 *
 * A pass says every filterable resource registered on this host has a data-filters resource under the
 * same key — nothing more. It is **not** a claim that the filters are correct, that their `Query` class
 * scopes anything, or that a resource this host does not install is safe elsewhere. The census is true
 * for this composition on the commit it was taken at, and both registries move: the flagship read 44/32
 * on 2026-08-29 morning, 47/33 an hour later and 50/36 that afternoon, purely from a concurrent change to
 * beam's own particle discovery.
 *
 * @see ParticleResourceAttribute::$filterable the default that makes this a promise by omission
 * @see RelativeEdgeIntegrityAudit the sibling detector for the OTHER consequence of that same default
 */
class FilterablePromiseAudit implements DoctorAudit
{
    public const CHECK = 'particle.filterable-promise';

    public function __construct(
        private ParticleResourceRegistry $resources,
        private FilterResourceRegistry $filters,
        private Router $router,
    ) {}

    /** @return list<Finding> */
    public function run(): array
    {
        $filterable = array_values(array_filter(
            $this->resources->all(),
            fn (ParticleResource $resource) => $resource->filterable,
        ));

        $total = count($this->resources->all());

        if ($filterable === []) {
            return [Finding::pass(self::CHECK, sprintf(
                '%d particle resource%s registered on this host, none of them filterable — no resource is '
                    .'promising a data-filters query.',
                $total,
                $total === 1 ? '' : 's',
            ))];
        }

        $unregistered = [];

        foreach ($filterable as $resource) {
            if (! $this->filters->has($resource->key)) {
                $unregistered[] = $resource->key;
            }
        }

        if ($unregistered === []) {
            return [Finding::pass(self::CHECK, sprintf(
                '%d of %d registered particle resource%s %s filterable, and every one has a data-filters '
                    .'resource under the same key. This does not claim those filters are CORRECT — only that '
                    .'the lookup `ParticleController::index()` performs will not raise.',
                count($filterable),
                $total,
                $total === 1 ? '' : 's',
                count($filterable) === 1 ? 'is' : 'are',
            ))];
        }

        $throwing = $this->throwingRoutes();

        $live = [];
        $latent = [];

        foreach ($unregistered as $key) {
            if (isset($throwing[$key])) {
                $live[] = sprintf('%s (%s)', $key, implode(', ', $throwing[$key]));
            } else {
                $latent[] = $key;
            }
        }

        return [Finding::warn(self::CHECK, sprintf(
            '%d of %d filterable particle resource%s ha%s no data-filters resource registered under %s key, '
                .'so `ParticleController::index()` would raise "No data-filters resource is registered under '
                .'[<key>], so no list query can be composed for it." ⚠️ None of these declarations SAYS '
                .'`filterable: true` — `ParticleResource::$filterable` defaults to true, so the promise is '
                .'made by not opting out. %s%sThe fix per key is a '
                .'`Rushing\DataFilters\Registry\ResourceDefinition` registered from the DECLARING '
                .'package\'s provider, guarded by '
                .'`has()` so a host that seeded its own key is not stomped (see '
                .'`BeamServiceProvider::declareFilterResources()`); `filterable: false` is the other repair, '
                .'and it demotes the resource to an unfiltered, request-blind list. Latency is measured from '
                .'THIS process\'s route table — a host that mounts routes conditionally may serve a route '
                .'over HTTP that an `artisan` run never registers, which would read here as latent.',
            count($unregistered),
            count($filterable),
            count($filterable) === 1 ? '' : 's',
            count($unregistered) === 1 ? 's' : 've',
            count($unregistered) === 1 ? 'its' : 'their',
            $live === []
                ? 'None is reachable on this host today: no route is bound to `ParticleController@index` '
                    .'or to the `filters` sub-surface for any of them, so all are LATENT — each 500s the '
                    .'moment anything mounts it. '
                : sprintf('LIVE (%d) — a route already routes through the throwing lookup: %s. ', count($live), implode('; ', $live)),
            $latent === [] ? '' : sprintf('LATENT (%d): %s. ', count($latent), implode(', ', $latent)),
        ))];
    }

    /**
     * Resource key => the URIs of its routes that actually perform the data-filters lookup.
     *
     * Exactly two paths reach it, and both are recognised off the route rather than off its URI, for the
     * reason {@see SubSurface}'s docblock gives: the sub-surface stamps itself at mount time, so this is a
     * lookup and not a parse. `ParticleController@index` is matched by action name because the CRUD
     * sub-surface has no stamp of its own — it is the fallback — and `crud` alone would sweep in `show`,
     * `store`, `update` and `destroy`, none of which touch the filter registry.
     *
     * @return array<string, list<string>>
     */
    private function throwingRoutes(): array
    {
        $found = [];

        foreach ($this->router->getRoutes() as $route) {
            $key = BeamRouteAction::resourceKey($route);

            if ($key === null) {
                continue;
            }

            $reaches = $route->getActionName() === ParticleController::class.'@index'
                || SubSurface::of($route) === SubSurface::FILTERS;

            if (! $reaches) {
                continue;
            }

            $found[$key][] = $route->uri();
        }

        foreach ($found as $key => $uris) {
            $found[$key] = array_values(array_unique($uris));
        }

        return $found;
    }
}
