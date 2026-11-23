<?php

namespace Splicewire\Beam\Agreements;

use Closure;
use Illuminate\Container\Container;
use LogicException;
use WeakMap;
use WeakReference;

/**
 * The per-request memo of the ROUTE half of {@see SlotRequirementDeclarations} (credential-scope 18): each named route's
 * `agreement:` declarations, walked AT MOST ONCE per request, queue job or console command, never kept across them.
 *
 * Why it exists: frame's manifest asks {@see SlotRequirementDeclarations::requirementsFor()} twice per resource
 * projection (about 3700 calls on the flagship's Root manifest), and a walk of every route on each call (1432 routes,
 * each rebuilt from the compiled cache when routes are cached) made the manifest about 4x slower.
 *
 * Scopes are a STACK of frames per container. Each frame holds its own map and is filled by its first read; a read
 * answers from the top frame. The lifecycle, wired by beam's provider:
 *
 *  - a REQUEST opens a top-level frame at its start (`Illuminate\Routing\Events\Routing`, before route middleware; and
 *    Octane's `RequestReceived`) and closes it when it is handled (`RequestHandled`; and Octane's `RequestTerminated`).
 *    A top-level open CLEARS the stack, so successive requests that share one route collection (an Octane worker keeps
 *    its router and routes across requests) each walk the routes again, and a frame a crashed unit left behind never
 *    reaches the next one;
 *  - opening is IDEMPOTENT PER REQUEST OBJECT: when the top frame was opened for the same request, a second open keeps
 *    it. Octane fires `RequestReceived` and then `Routing` for one request, and that pair gives ONE frame (a read made
 *    between them stays memoised), and `RequestTerminated` after `RequestHandled` closes nothing more;
 *  - a queue JOB opens at `JobProcessing`: NESTED (pushed on top) when it runs on the `sync` connection (it runs
 *    inside whatever unit dispatched it: a request, a console command, another job) or when a request frame encloses
 *    it; TOP-LEVEL otherwise (a worker's job), so a worker never carries one job's map into the next. It closes at
 *    `JobProcessed`, `JobFailed` or `JobExceptionOccurred` (a worker's retried job fires only the last; a job that
 *    fires two of them closes once, the second finding no frame of its own);
 *  - a console COMMAND opens a NESTED frame at `CommandStarting` (on an empty stack that is the only frame) and closes
 *    it at `CommandFinished`, which Symfony fires even when the command throws. Laravel fires those two only outside
 *    `runningUnitTests()`;
 *  - a close pops its OWN frame (matched by the event's request, job or input object, held weakly) and every frame
 *    above it, and the enclosing frame answers again with its map intact: a nested unit never discards its parent's
 *    memo, and a nested unit whose close never fired cannot outlive its parent's close. A close whose unit has no
 *    frame (its frame was already cleared or popped) does nothing;
 *  - a new frame always starts EMPTY: nothing read in one unit answers another, except the enclosing frame answering
 *    its own unit after a nested one closes;
 *  - outside any frame (provider boot, a test body between requests) nothing is memoised: every read walks the routes,
 *    exactly as agreements 07 did;
 *  - a hit makes no route enumeration at all (no `getRoutes()`, no `count()`).
 *
 * The contract inside a frame: routes do not change after its first read. In testing (`runningUnitTests()`) closing a
 * frame walks the routes once more for each popped frame that read, and throws a {@see LogicException} when the map
 * changed, so a route registered or edited mid-request after a read is refused loudly rather than answered stale.
 * {@see $verifiesInTests} switches that extra walk off, for a test that counts route enumerations.
 *
 * Stacks are keyed on the current container (`Container::getInstance()`, the Octane sandbox during a request), in a
 * WeakMap, so a discarded sandbox or test application frees its entry.
 *
 * @phpstan-type Frame array{owner: WeakReference<object>|null, request: bool, map: array<string, list<Requires>>|null, walk: (Closure(): array<string, list<Requires>>)|null}
 */
final class RouteRequirementIndex
{
    /** Whether closing a frame under `runningUnitTests()` re-walks and verifies (a test that counts walks turns it off). */
    public static bool $verifiesInTests = true;

    /** @var WeakMap<Container, list<Frame>>|null */
    private static ?WeakMap $stacks = null;

    /**
     * A request starts: a TOP-LEVEL frame, which clears the stack. Idempotent per request object: when the top frame was
     * opened for this request (Octane's `RequestReceived`, then `Routing`), it is kept.
     */
    public static function openRequest(object $request, ?Container $container = null): void
    {
        $container ??= Container::getInstance();
        $stack = self::stack($container);
        $top = $stack === [] ? null : $stack[array_key_last($stack)];

        if ($top !== null && $top['request'] && self::owns($top, $request)) {
            return;
        }

        self::restart($container, self::frame($request, request: true));
    }

    /**
     * A queue job starts: nested when it runs on the `sync` connection (inside the unit that dispatched it: a request, a
     * console command, another job) or when a request frame encloses it; top-level otherwise (a worker's job).
     */
    public static function openJob(object $job, ?Container $container = null, ?string $connection = null): void
    {
        $container ??= Container::getInstance();

        if ($connection === 'sync') {
            self::open($job, $container);

            return;
        }

        foreach (self::stack($container) as $frame) {
            if ($frame['request']) {
                self::open($job, $container);

                return;
            }
        }

        self::restart($container, self::frame($job, request: false));
    }

    /** Push an empty NESTED frame (a console command; or a scope a test opens), owned by `$owner` when given. */
    public static function open(?object $owner = null, ?Container $container = null): void
    {
        $container ??= Container::getInstance();
        $stack = self::stack($container);
        $stack[] = self::frame($owner, request: false);
        self::store($container, $stack);
    }

    /**
     * Close `$owner`'s frame, and every frame above it, restoring the enclosing frame. Without an owner, the top frame.
     * A close whose owner has no frame does nothing. In testing, verify first that the routes still declare what each
     * popped frame read.
     *
     * @throws LogicException when a route's `agreement:` declarations changed after they were read in a popped frame
     */
    public static function close(?object $owner = null, ?Container $container = null): void
    {
        $container ??= Container::getInstance();
        $stack = self::stack($container);

        if ($stack === []) {
            return;
        }

        $at = $owner === null ? array_key_last($stack) : null;
        for ($i = array_key_last($stack); $at === null && $i >= 0; $i--) {
            if (self::owns($stack[$i], $owner)) {
                $at = $i;
            }
        }

        if ($at === null) {
            return;
        }

        $popped = array_slice($stack, $at);
        $remaining = array_slice($stack, 0, $at);

        self::store($container, $remaining);

        if (! self::verifies($container)) {
            return;
        }

        foreach (array_reverse($popped) as $frame) {
            if ($frame['map'] !== null && $frame['walk'] !== null && ($frame['walk'])() != $frame['map']) {
                throw new LogicException(
                    'A route\'s agreement declarations changed after they were read in this request, job or command (credential-scope 18): '
                    .'routes are fixed once a request reads its requirements. Register routes before the request, or read after.'
                );
            }
        }
    }

    /**
     * The route declarations: memoised in the top frame while one is open, walked on every call otherwise.
     *
     * @param  Closure(): array<string, list<Requires>>  $walk
     * @return array<string, list<Requires>>
     */
    public static function read(Closure $walk): array
    {
        $container = Container::getInstance();
        $stack = self::stack($container);

        if ($stack === []) {
            return $walk();
        }

        $top = array_key_last($stack);

        if ($stack[$top]['map'] === null) {
            $stack[$top]['map'] = $walk();
            $stack[$top]['walk'] = $walk;
            self::store($container, $stack);
        }

        return $stack[$top]['map'];
    }

    /** Whether the current container has an open frame (for tests and diagnostics). */
    public static function isOpen(?Container $container = null): bool
    {
        return self::depth($container) > 0;
    }

    /** How many frames the current container has open (for tests and diagnostics). */
    public static function depth(?Container $container = null): int
    {
        return count(self::stack($container ?? Container::getInstance()));
    }

    /** @return list<Frame> */
    private static function stack(Container $container): array
    {
        return self::$stacks !== null && isset(self::$stacks[$container]) ? self::$stacks[$container] : [];
    }

    /** @param  list<Frame>  $stack  an empty stack removes the container's entry */
    private static function store(Container $container, array $stack): void
    {
        $stacks = self::$stacks ??= new WeakMap;

        if ($stack === []) {
            unset($stacks[$container]);
        } else {
            $stacks[$container] = $stack; // a WeakMap is an object: this writes the shared map
        }
    }

    /**
     * A top-level open: the stack holds only the new frame, so no frame left by an earlier unit survives.
     *
     * @param  Frame  $frame
     */
    private static function restart(Container $container, array $frame): void
    {
        self::store($container, [$frame]);
    }

    /** @return Frame */
    private static function frame(?object $owner, bool $request): array
    {
        return ['owner' => $owner === null ? null : WeakReference::create($owner), 'request' => $request, 'map' => null, 'walk' => null];
    }

    /**
     * Whether the frame was opened for `$owner` (held weakly, so a freed owner matches nothing).
     *
     * @param  Frame  $frame
     */
    private static function owns(array $frame, object $owner): bool
    {
        return $frame['owner'] !== null && $frame['owner']->get() === $owner;
    }

    private static function verifies(Container $container): bool
    {
        return self::$verifiesInTests && method_exists($container, 'runningUnitTests') && $container->runningUnitTests();
    }
}
