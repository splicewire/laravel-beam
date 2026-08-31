<?php

namespace Splicewire\Beam\Surgeon;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Particle\Mount\PendingParticleMount;

/**
 * Two routes claiming one name. Laravel's name table is **last-wins** and says nothing, and `route:cache`
 * turns the same condition into a hard `LogicException` at deploy time.
 *
 * ## This audit is a prescription already written down in this package
 *
 * {@see PendingParticleMount}'s docblock records two measured route-name collisions and concludes that
 * *"the thing that makes the collision class impossible is a duplicate-route-name guard on the assembled
 * table — not a builder."* The reason is in the second of its two instances. One was a real
 * `Route::particleOps()` call whose derived names carried no owning namespace — a front door can see that,
 * because the name is its own output. The other was a hand-written `Route::get()` whose `->name('search')`
 * won the flat table while its closure never served a request. **No builder can see that half.** Deleting
 * every particle macro in the estate would not have prevented it. Only the assembled table can.
 *
 * Nothing in `src/Surgeon/` inspected the route table for this before. {@see SdkNameConventionAudit} walks
 * it to check name SHAPE against the generated client, and {@see TypeScriptShortNameCollisionAudit} checks
 * emitted TypeScript symbols — neither asks whether two routes claim one name.
 *
 * ## The two consequences, and only one of them is loud
 *
 * **Silent:** `RouteCollection::addLookups()` does `$this->nameList[$name] = $route`, so the LAST route
 * registered under a name wins every `route()` / `URL::route()` / Wayfinder lookup and the earlier one
 * becomes unreachable by name. It still serves its URI, so nothing 404s and no test fails — the symptom is
 * a link pointing somewhere unexpected.
 *
 * **Loud, but only at deploy:** `AbstractRouteCollection::addToSymfonyRoutesCollection()` throws
 * `LogicException: Unable to prepare route [x] for serialization. Another route has already been assigned
 * name [y]` — so a host in this state **cannot `route:cache` at all**. api-surface-coherence ticket 51 §3
 * recorded six of these making the command fatal estate-wide. Note what the exception is: it names ONE
 * route and stops, so a host with several collisions discovers them one deploy attempt at a time. This
 * audit reports all of them in a single pass.
 *
 * ## Measured across the estate, 2026-08-30
 *
 * Every `~/Herd` root carrying an `artisan` (readlink-resolved — `~/Herd/{beam,satellite,tower}` ARE the
 * starters, counted once), via `route:list --json`:
 *
 * ```
 * host                routes   named   collisions   route:cache
 * splicewire-app         915     890            0   ✅ cached successfully
 * audiostud              204     196            1   ❌ LogicException [songs.index]
 * prahsys-gateway        231     205           80   ❌ LogicException [n1.status]
 * prognosix-api          164     148            2   ❌ LogicException [dicom.proxy]
 * fable-legacy           448     144            3   (beam not installed)
 * 15 other roots           —       —            0   —
 * ```
 *
 * (`numero-legacy` is the twenty-first root and does not boot, independently of anything here.) The
 * `routes` and `named` columns are `route:list` readings and this audit's own census agrees with them
 * host for host — verified at the flagship, which reports `890 named routes across 915 assembled`.
 *
 * **The flagship is clean and the estate is not**, which is the exact shape this package keeps relearning.
 * A route-table audit verified only at `~/Herd/splicewire-app` would have measured zero and shipped.
 *
 * `~/Herd/audiostud` is the first real finding and it is textbook: an Inertia page route
 * `GET account/songs` and a beam particle mount `GET resources/songs` both claim `songs.index`, the Inertia
 * one registers second and wins, and the particle listing is unreachable by name. That is the
 * hand-written-route-steals-a-derived-name shape `PendingParticleMount` describes, found by the instrument
 * it asked for.
 *
 * ## Legitimately-aliased pairs are NOT excluded, deliberately
 *
 * `~/Herd/prahsys-gateway` mounts the same 80 controller actions under two prefixes (`api/n1/…` and
 * `payments/n1/…`) with one `->name()` each, which looks like an intentional alias and is the obvious
 * candidate for an exemption. It gets none, because the exemption would be wrong on both counts: the
 * `api/` mount really is unreachable by name, and the host really cannot `route:cache` (measured, above).
 * An audit that suppressed 80 rows here would suppress a live deploy blocker to look tidy. What the
 * findings do instead is print each claimant's ACTION, so a dual-prefix pattern is recognisable at a
 * glance from the report rather than by re-deriving it.
 *
 * ## A name ending in `.` is a different defect, and Laravel says so
 *
 * `addToSymfonyRoutesCollection()` special-cases it:
 *
 * ```php
 * if (! is_null($name) && str_ends_with($name, '.') && ! is_null($symfonyRoutes->get($name))) {
 *     $name = null;   // then a generated name is assigned
 * }
 * ```
 *
 * So a trailing-dot name is treated as ABSENT on collision, gets an auto-generated name, and is **not**
 * `route:cache`-fatal. That is why `~/Herd/prognosix-api` — where ten routes share the name `n1.` — fails
 * `route:cache` on `dicom.proxy` and never mentions `n1.` at all. The cause is a route group's
 * `->name('n1.')` prefix with no leaf `->name()` on the routes beneath it, and the repair is to name the
 * routes, not to rename one of them. Reported as {@see CHECK_PREFIX_ONLY} so the two do not get one
 * verdict and one repair between them.
 *
 * ## Advisory, and computed on read
 *
 * Which routes are assembled is the definitive fact about the HOST — its own route files, every package it
 * composes, and any provider that mounts at boot. The same beam mount collides at `~/Herd/audiostud` and
 * not at `~/Herd/splicewire-app`, and neither package could have known. A gate here would also fail four
 * roots on day one over route files beam does not own. Read fresh on every run, never memoised, so a route
 * mounted after beam's boot is in the population and a collision repaired mid-session clears.
 */
class DuplicateRouteNameAudit implements DoctorAudit
{
    /** Two or more routes claiming one name: last-wins silently, and `route:cache` refuses. */
    public const CHECK_DUPLICATE = 'route.name.duplicate';

    /** Routes carrying only a group's `.`-terminated name prefix: auto-renamed, unreachable by name. */
    public const CHECK_PREFIX_ONLY = 'route.name.prefix-only';

    /** The census line, emitted whether or not anything warned. */
    public const CHECK_CENSUS = 'route.name';

    public function __construct(
        protected Router $router,
    ) {}

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        $claims = $this->claimsByName();

        // The population gate. A table with no named routes has nothing that could collide — there is no
        // name for two routes to share — so this reports the empty population rather than a clean bill it
        // did not earn.
        if ($claims === []) {
            return [Finding::pass(
                self::CHECK_CENSUS,
                sprintf(
                    'None of this host\'s %d assembled routes carries a name, so no two routes can claim '
                    .'one and there is nothing for the last-wins name table to shadow. Nothing was '
                    .'measured.',
                    $this->routeCount(),
                ),
            )];
        }

        $findings = [];
        $duplicates = 0;
        $prefixOnly = 0;

        foreach ($claims as $name => $routes) {
            if (count($routes) < 2) {
                continue;
            }

            $name = (string) $name;

            if (str_ends_with($name, '.')) {
                $prefixOnly++;
                $findings[] = Finding::warn(self::CHECK_PREFIX_ONLY, $this->prefixOnlyDetail($name, $routes));

                continue;
            }

            $duplicates++;
            $findings[] = Finding::warn(self::CHECK_DUPLICATE, $this->duplicateDetail($name, $routes));
        }

        $named = array_sum(array_map('count', $claims));

        $findings[] = Finding::pass(
            self::CHECK_CENSUS,
            sprintf(
                '%d named route%s across %d assembled, claiming %d distinct name%s: %d name%s claimed '
                .'twice or more%s.',
                $named,
                $named === 1 ? '' : 's',
                $this->routeCount(),
                count($claims),
                count($claims) === 1 ? '' : 's',
                $duplicates,
                $duplicates === 1 ? '' : 's',
                $prefixOnly === 0
                    ? ''
                    : sprintf(', plus %d group-prefix-only name%s', $prefixOnly, $prefixOnly === 1 ? '' : 's'),
            ),
        );

        return $findings;
    }

    /**
     * Every claimant of a duplicated name, the winner named, and both consequences stated — because the
     * silent one and the loud one have different audiences and a reader who knows only about `route:cache`
     * will "fix" it by deleting the wrong route.
     *
     * @param  list<Route>  $routes
     */
    protected function duplicateDetail(string $name, array $routes): string
    {
        $winner = $routes[count($routes) - 1];
        $shadowed = array_slice($routes, 0, -1);

        return sprintf(
            '[%s] is claimed by %d routes: %s. Laravel\'s name table is last-wins '
            .'(RouteCollection::addLookups overwrites), so route(\'%s\') resolves to %s and %s '
            .'unreachable by name — the URI still serves, so nothing 404s and no test fails; the symptom '
            .'is a link pointing somewhere unexpected. This host also cannot `route:cache`: '
            .'AbstractRouteCollection::addToSymfonyRoutesCollection() throws LogicException on the second '
            .'claimant, one collision per attempt. Give every claimant but one an owning prefix.',
            $name,
            count($routes),
            implode('; ', array_map(fn (Route $route) => $this->describe($route), $routes)),
            $name,
            $this->describe($winner),
            count($shadowed) === 1
                ? $this->describe($shadowed[0]).' is'
                : implode(' and ', array_map(fn (Route $r) => $this->describe($r), $shadowed)).' are',
        );
    }

    /**
     * The trailing-dot case. Stated separately because the consequence and the repair both differ, and
     * because a reader who has just read the duplicate finding will otherwise assume this one blocks
     * `route:cache` too. It does not — Laravel auto-renames instead.
     *
     * @param  list<Route>  $routes
     */
    protected function prefixOnlyDetail(string $name, array $routes): string
    {
        return sprintf(
            '[%s] is carried by %d routes and is a group NAME PREFIX with no leaf name under it: %s. '
            .'Laravel special-cases a `.`-terminated duplicate — addToSymfonyRoutesCollection() treats it '
            .'as absent and assigns a generated name — so this does NOT break `route:cache`, and route(\'%s\') '
            .'is not a name any of them can be reached by. The repair is a ->name() on each route in the '
            .'group, not a rename of one of them.',
            $name,
            count($routes),
            implode('; ', array_map(fn (Route $route) => $this->describe($route), $routes)),
            $name,
        );
    }

    /**
     * The assembled table grouped by name, unnamed routes dropped, registration order preserved within
     * each name — order is what decides the winner, so it is load-bearing, not incidental.
     *
     * Read fresh from the router on every call. Nothing is memoised: a host's table is still being
     * assembled while providers boot, and a stamped copy would report the table as it stood at first
     * resolve.
     *
     * @return array<string, list<Route>>
     */
    protected function claimsByName(): array
    {
        $claims = [];

        foreach ($this->router->getRoutes() as $route) {
            $name = $route->getName();

            if ($name === null || $name === '') {
                continue;
            }

            $claims[$name][] = $route;
        }

        return $claims;
    }

    /** The whole assembled table, named or not — the denominator the census reports against. */
    protected function routeCount(): int
    {
        return count($this->router->getRoutes());
    }

    /** `METHODS uri → action`: the address a reader needs to go decide which claimant should be renamed. */
    protected function describe(Route $route): string
    {
        $methods = array_values(array_diff($route->methods(), ['HEAD']));

        return sprintf(
            '%s /%s → %s',
            implode('|', $methods === [] ? $route->methods() : $methods),
            ltrim($route->uri(), '/'),
            $this->actionLabel($route),
        );
    }

    /**
     * A `Controller@method` where there is one, `Closure` where there is not.
     *
     * `uses` is the only slot read, because it is the only one that can answer: Laravel normalises an
     * invokable controller string to `Controller@__invoke` on registration, so the action is either a
     * string or a Closure and a `controller` fallback would be unreachable.
     */
    protected function actionLabel(Route $route): string
    {
        $action = $route->getAction('uses');

        return is_string($action) && $action !== '' ? $action : 'Closure';
    }
}
