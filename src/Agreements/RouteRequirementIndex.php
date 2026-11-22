<?php

namespace Splicewire\Beam\Agreements;

use Closure;
use Illuminate\Container\Container;
use LogicException;
use WeakMap;

/**
 * The per-request memo of the ROUTE half of {@see SlotRequirementDeclarations} (credential-scope 18): each named route's
 * `agreement:` declarations, walked AT MOST ONCE per request, queue job or console command, never kept across them.
 *
 * Why it exists: frame's manifest asks {@see SlotRequirementDeclarations::requirementsFor()} twice per resource
 * projection (about 3700 calls on the flagship's Root manifest), and a walk of every route on each call (1432 routes,
 * each rebuilt from the compiled cache when routes are cached) made the manifest about 4x slower.
 *
 * The lifecycle, wired by beam's provider:
 *
 *  - a request OPENS a scope at its start (`Illuminate\Routing\Events\Routing`, before route middleware; and Octane's
 *    `RequestReceived`) and CLOSES it when it is handled (`RequestHandled`; and Octane's `RequestTerminated`);
 *  - a queue job opens one at `JobProcessing` and closes it at `JobProcessed` or `JobFailed`, so a worker never carries
 *    one job's map into the next; a console command opens one at `CommandStarting` and closes it at `CommandFinished`
 *    (Laravel fires those two only outside `runningUnitTests()`);
 *  - opening always starts EMPTY, so successive requests that share one route collection (an Octane worker keeps its
 *    router and routes across requests) each walk the routes again: nothing read in one request answers another;
 *  - outside an open scope (provider boot, a test body between requests) nothing is memoised: every read
 *    walks the routes, exactly as agreements 07 did;
 *  - a hit makes no route enumeration at all (no `getRoutes()`, no `count()`).
 *
 * The contract inside a scope: routes do not change after the first read. In testing (`runningUnitTests()`) closing a
 * scope walks the routes once more and throws a {@see LogicException} when the map changed, so a route registered or
 * edited mid-request after a read is refused loudly rather than answered stale. {@see $verifiesInTests} switches that
 * one extra walk off, for a test that counts route enumerations.
 *
 * Scopes are keyed on the current container (`Container::getInstance()`, the Octane sandbox during a request), in a
 * WeakMap, so a discarded sandbox or test application frees its entry.
 */
final class RouteRequirementIndex
{
    /** Whether closing a scope under `runningUnitTests()` re-walks and verifies (a test that counts walks turns it off). */
    public static bool $verifiesInTests = true;

    /** @var WeakMap<Container, array{map: array<string, list<Requires>>|null, walk: Closure|null}>|null */
    private static ?WeakMap $scopes = null;

    /** Open (or restart, empty) the current container's scope. */
    public static function open(?Container $container = null): void
    {
        self::$scopes ??= new WeakMap;
        self::$scopes[$container ?? Container::getInstance()] = ['map' => null, 'walk' => null];
    }

    /**
     * Close the current container's scope. In testing, verify first that the routes still declare what was read.
     *
     * @throws LogicException when a route's `agreement:` declarations changed after they were read in this scope
     */
    public static function close(?Container $container = null): void
    {
        $container ??= Container::getInstance();

        if (self::$scopes === null || ! isset(self::$scopes[$container])) {
            return;
        }

        ['map' => $map, 'walk' => $walk] = self::$scopes[$container];
        unset(self::$scopes[$container]);

        if ($map !== null && $walk !== null && self::verifies($container) && $walk() != $map) {
            throw new LogicException(
                'A route\'s agreement declarations changed after they were read in this request or command (credential-scope 18): '
                .'routes are fixed once a request reads its requirements. Register routes before the request, or read after.'
            );
        }
    }

    /**
     * The route declarations: memoised while a scope is open, walked on every call otherwise.
     *
     * @param  Closure(): array<string, list<Requires>>  $walk
     * @return array<string, list<Requires>>
     */
    public static function read(Closure $walk): array
    {
        $container = Container::getInstance();
        $scopes = self::$scopes;

        if ($scopes === null || ! isset($scopes[$container])) {
            return $walk();
        }

        $scope = $scopes[$container];

        if ($scope['map'] === null) {
            $scope = ['map' => $walk(), 'walk' => $walk];
            $scopes[$container] = $scope; // a WeakMap is an object: this writes the shared map
        }

        return $scope['map'];
    }

    /** Whether the current container has an open scope (for tests and diagnostics). */
    public static function isOpen(?Container $container = null): bool
    {
        return self::$scopes !== null && isset(self::$scopes[$container ?? Container::getInstance()]);
    }

    private static function verifies(Container $container): bool
    {
        return self::$verifiesInTests && method_exists($container, 'runningUnitTests') && $container->runningUnitTests();
    }
}
