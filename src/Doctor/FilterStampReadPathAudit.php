<?php

namespace Splicewire\Beam\Doctor;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use Rushing\DataFilters\Registry\ResourceRegistry as FilterResourceRegistry;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Discovery\SubSurface;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Routing\BeamRouteProxy;

/**
 * **A route that stamped `filters: true` and is served by a handler that never reads the vocabulary**
 * — the promise made by *saying so* (api-surface-coherence 101).
 *
 * {@see BeamRouteProxy::inResource()} takes `filters: true` and mounts the resource's whole filter
 * sub-surface — `filters`, `filters/schema`, `filters/options/{ref}`, `filters/variants`, plus the five
 * saved-filter write routes — beside a **hand-written** index. Nothing has ever checked that the index
 * underneath them reads through the engine those routes describe. Three did not, measured at the
 * flagship on 2026-08-29:
 *
 * | route | handler | what it does instead |
 * |---|---|---|
 * | `api/v1/beam/accounts/tokens` | `ApiTokenController@index` | `orderByDesc('created_at')`, unpaginated |
 * | `api/v1/circuits/{circuit}/guest-tokens` | `GuestTokenController@index` | hard-codes the scope its own `GuestLinkFilterData` declares |
 * | `api/v1/review-queue` | `ReviewQueueController@index` | filters an in-memory collection on bare `source`/`parent_id` |
 *
 * The second one is the sharpest: the *same controller's* `indexAll`, the flat exposure of the *same*
 * resource, does ride `DataFilter::query('guest-links')`. One resource, two exposures, two engines.
 *
 * ## Why this needs the stamp recorded, and why it is not `mountFilterSubSurface()`'s side effect
 *
 * `filters: true` left **no trace** until this audit: it is a constructor argument that runs one method
 * and is forgotten. Worse, {@see BeamRouteProxy::mountFilterSubSurface()} returns early when the key has
 * no `rushing/laravel-data-filters` resource, so a declaration naming a key no registry carries mounts
 * **nothing, silently** — indistinguishable, afterwards, from a route that never asked. Deriving the
 * population from the mounted sub-surface would therefore see only the promises that were *kept*, which
 * is the estate's *instrument-reports-success-by-not-running* shape. So `inResource()` now records the
 * ask ({@see BeamRouteProxy::FILTERS_PROMISE}) and this audit reads the ask, not the outcome.
 *
 * ## What the read-path test can and cannot see — state it before believing a zero
 *
 * For a route bound to {@see ParticleController}`@index` the answer is structural: that is the particle
 * read path and it composes its query from the resource declaration. For a hand-written controller
 * there is no runtime signal short of issuing a request, so this reads the **action method's source**
 * and looks for the engine — and then follows **one level of delegation**, because at the flagship the
 * majority of converged indexes do not name the engine themselves:
 *
 * ```php
 * // FragmentController::index()                       // FragmentQueryBuilder::forUser()
 * $qb = FragmentQueryBuilder::forUser(auth()->user(), $request);   →   DataFilter::query('fragments')->apply($request)
 * ```
 *
 * Three of the estate's twelve stamped routes read that way. A body-only test reported all three as
 * defects — measured, before this delegation step existed — which would have shipped an audit whose
 * finding was **half noise on its first run**. `$this->method()` and `Klass::method()` are both
 * followed, resolving the class through the calling file's own `use` imports.
 *
 * Two blind spots survive and are named in the finding rather than left for the reader to assume:
 *
 * - delegation **two** levels deep, or through an injected interface, still reads as a false alarm;
 * - a handler that *mentions* the engine on a branch it never takes reads as a false clean.
 *
 * The join none of this could be done without — *which controller actually serves the route that
 * carries the stamp* — exists only on the booted router; there is no static relationship between
 * `inResource('x')` and a controller body, which is why 101 required this to be a runtime instrument.
 *
 * ⚠️ It also does not claim the honoured routes honour every declared facet. `#[Sortable]` facets are a
 * separate axis from `#[Filterable]` ones and an index can ride the engine for one and not the other;
 * that question is `DeclaredFacetColumnParityTest`'s, at host tier, with data.
 *
 * ## Advisory, permanently
 *
 * Every input is a fact about the **host**: which routes this composition mounts, which data-filters
 * resources it registered, and which package's controller ends up bound. The declaring package cannot
 * know any of them — `rushing/laravel-doctor/docs/agents/gate-or-advisory.convention.md`'s named
 * advisory case, and AGENTS.md's standing rule that a check whose answer depends on the host must not
 * throw. A host that wants this to block registers the class in its own manifest with `gate: true`.
 *
 * @see FilterablePromiseAudit the sibling for the promise made by NOT opting out — that one reads the
 *                             particle registry against the filter registry; this one reads the ROUTE
 *                             stamp against the handler that serves it
 */
class FilterStampReadPathAudit implements DoctorAudit
{
    public const CHECK = 'particle.filter-stamp-read-path';

    /**
     * The engine a stamped index is claiming to ride. `DataFilter::query(...)` is the facade every
     * converged index in the estate goes through; `hydrator->query(` is {@see ParticleController}'s own
     * spelling, kept so a host controller composing the hydrator directly is not reported.
     */
    private const ENGINE = '/\bDataFilter::|hydrator->query\s*\(|FilterHydrator/';

    public function __construct(
        private FilterResourceRegistry $filters,
        private Router $router,
    ) {}

    /** @return list<Finding> */
    public function run(): array
    {
        $stamped = $this->stampedRoutes();

        if ($stamped === []) {
            return [Finding::inconclusive(self::CHECK, sprintf(
                'No route on this host carries the `%s` stamp, so no hand-written exposure is publishing '
                    .'a filter sub-surface. That is a statement about `->inResource(..., filters: true)` '
                    .'ONLY — a `Particle::mount()` resource mounts its filter sub-surface from the '
                    .'resource declaration and is served by `ParticleController`, so it is out of this '
                    .'audit\'s population by construction, not absent from this host.',
                BeamRouteProxy::FILTERS_PROMISE,
            ))];
        }

        $refused = [];
        $blind = [];
        $unreadable = [];
        $honoured = 0;

        foreach ($stamped as [$route, $key]) {
            $where = sprintf('%s (%s)', $key, $route->uri());

            if (! $this->filters->has($key)) {
                $refused[] = $where;

                continue;
            }

            $verdict = $this->readsThroughEngine($route);

            if ($verdict === null) {
                $unreadable[] = $where.' → '.$route->getActionName();

                continue;
            }

            if ($verdict) {
                $honoured++;

                continue;
            }

            $blind[] = $where.' → '.$route->getActionName();
        }

        if ($refused === [] && $blind === [] && $unreadable === []) {
            return [Finding::pass(self::CHECK, sprintf(
                'All %d route%s stamped `filters: true` on this host name%s a registered data-filters '
                    .'resource, and every handler serving one reads through the filter engine. This does '
                    .'NOT claim the handler honours every DECLARED facet — a route can ride the engine and '
                    .'still ignore the `#[Sortable]` half of its own `FilterData`. The read-path test is a '
                    .'one-level source read of the action method, so a handler that delegates the query to '
                    .'a service would read as honoured here without being verified.',
                count($stamped),
                count($stamped) === 1 ? '' : 's',
                count($stamped) === 1 ? 's' : '',
            ))];
        }

        $parts = [];

        if ($blind !== []) {
            $parts[] = sprintf(
                'PUBLISHED-BUT-NOT-SERVED (%d): %s. The filter sub-surface is mounted at these URIs and '
                    .'answers off the declared `FilterData`, while the index beneath it composes its own '
                    .'query — so a caller can read the vocabulary, save a filter against it, and change '
                    .'nothing. Converge the handler onto `DataFilter::query($key)`, or drop `filters: true` '
                    .'and stop publishing a contract the route will not serve; publishing and not serving '
                    .'is the one answer that is not available.',
                count($blind),
                implode('; ', $blind),
            );
        }

        if ($refused !== []) {
            $parts[] = sprintf(
                'STAMP REFUSED (%d): %s. These routes asked for a filter sub-surface and got none — '
                    .'`BeamRouteProxy::mountFilterSubSurface()` returns early when the key has no '
                    .'data-filters resource, so the declaration is inert and says so nowhere. The repair is '
                    .'a `Rushing\DataFilters\Registry\ResourceDefinition` under that key, or deleting the '
                    .'argument.',
                count($refused),
                implode('; ', $refused),
            );
        }

        if ($unreadable !== []) {
            $parts[] = sprintf(
                'NOT CLASSIFIED (%d): %s. The action is a closure, or its method could not be reflected, '
                    .'so this audit has no opinion either way — not a pass.',
                count($unreadable),
                implode('; ', $unreadable),
            );
        }

        return [Finding::warn(self::CHECK, sprintf(
            '%d of %d route%s stamped `filters: true` %s not verified to serve what the stamp publishes. %s '
                .'⚠️ Read-path is decided by a ONE-LEVEL source read of the action method: a handler that '
                .'delegates its query to a service reads as a false alarm, and one that mentions the engine '
                .'on a branch it never takes reads as a false clean. Confirm by issuing a declared facet '
                .'against the route before acting on a row.',
            count($blind) + count($refused) + count($unreadable),
            count($stamped),
            count($stamped) === 1 ? '' : 's',
            count($blind) + count($refused) + count($unreadable) === 1 ? 'is' : 'are',
            implode(' ', $parts),
        ))];
    }

    /**
     * Every route carrying the `filters: true` stamp, paired with the resource key it named.
     *
     * Read off the route's own DEFAULTS for {@see SubSurface}'s reason: the declaration stamps itself at
     * mount time, so this is a lookup and not a parse of a URI whose word diverges from its key at
     * roughly half the estate (`guest-links` is served at `/guest-tokens`).
     *
     * @return list<array{0: Route, 1: string}>
     */
    private function stampedRoutes(): array
    {
        $found = [];

        foreach ($this->router->getRoutes() as $route) {
            $key = $route->defaults[BeamRouteProxy::FILTERS_PROMISE] ?? null;

            if (is_string($key) && $key !== '') {
                $found[] = [$route, $key];
            }
        }

        return $found;
    }

    /**
     * True when the route's handler composes its list through the filter engine, false when its own body
     * shows no sign of it, null when there is nothing to read.
     *
     * `ParticleController@index` is answered structurally rather than by reading source: it IS the
     * particle read path, and its query comes from the resource declaration. Everything else is a
     * hand-written exposure, which is the whole population 101 was filed about.
     */
    private function readsThroughEngine(Route $route): ?bool
    {
        $action = $route->getActionName();

        if ($action === ParticleController::class.'@index') {
            return true;
        }

        if (! str_contains($action, '@')) {
            return null;
        }

        [$class, $method] = explode('@', $action, 2);

        $body = $this->methodBody($class, $method);

        if ($body === null) {
            return null;
        }

        if (preg_match(self::ENGINE, $body) === 1) {
            return true;
        }

        foreach ($this->callees($class, $body) as [$calleeClass, $calleeMethod]) {
            $delegate = $this->methodBody($calleeClass, $calleeMethod);

            if ($delegate !== null && preg_match(self::ENGINE, $delegate) === 1) {
                return true;
            }
        }

        return false;
    }

    /** The source text of one method, or null when there is nothing readable to look at. */
    private function methodBody(string $class, string $method): ?string
    {
        try {
            $reflection = new ReflectionMethod($class, $method);
        } catch (ReflectionException) {
            return null;
        }

        $file = $reflection->getFileName();

        if ($file === false || ! is_readable($file)) {
            return null;
        }

        $lines = file($file);

        if ($lines === false) {
            return null;
        }

        return implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        ));
    }

    /**
     * The `Klass::method()` and `$this->method()` calls one handler body makes, as resolved class/method
     * pairs — the one delegation hop this audit follows.
     *
     * A short name is resolved through the CALLING FILE's own `use` imports, then through its namespace.
     * That is the same resolution PHP itself performs, done by hand because reading a method body is not
     * compiling it; anything that does not resolve to a real class is dropped rather than guessed at.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function callees(string $class, string $body): array
    {
        preg_match_all('/(?:([A-Z][A-Za-z0-9_]*)::|\$this->)([a-zA-Z_][A-Za-z0-9_]*)\s*\(/', $body, $matches, PREG_SET_ORDER);

        $imports = $this->imports($class) + ['__namespace' => ''];
        $found = [];

        foreach ($matches as $match) {
            $target = $match[1] === ''
                ? $class
                : ($imports[$match[1]] ?? $imports['__namespace'].'\\'.$match[1]);

            if (! class_exists($target)) {
                continue;
            }

            $found[$target.'::'.$match[2]] = [$target, $match[2]];
        }

        return array_values($found);
    }

    /**
     * Short class name => FQCN for one class's file: its `use` imports plus its own namespace, which is
     * how an unimported sibling in the same namespace resolves.
     *
     * @return array<string, string>
     */
    private function imports(string $class): array
    {
        try {
            $reflection = new ReflectionClass($class);
        } catch (ReflectionException) {
            return [];
        }

        $file = $reflection->getFileName();

        if ($file === false || ! is_readable($file)) {
            return [];
        }

        $source = (string) file_get_contents($file);
        $map = [];

        if (preg_match_all('/^use\s+([^;\s(]+)(?:\s+as\s+([A-Za-z0-9_]+))?;/mi', $source, $uses, PREG_SET_ORDER)) {
            foreach ($uses as $use) {
                $fqcn = ltrim($use[1], '\\');
                $alias = $use[2] ?? substr((string) strrchr('\\'.$fqcn, '\\'), 1);
                $map[$alias] = $fqcn;
            }
        }

        // The namespace the file itself sits in, so an unimported sibling resolves the way PHP resolves
        // it. Keyed under a name no class can have, since the rest of this map is short class names.
        $map['__namespace'] = $reflection->getNamespaceName();

        return $map;
    }
}
