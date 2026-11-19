<?php

namespace Splicewire\Beam\Routing;

use Closure;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use LogicException;

/** Package-owned mount roots; ADR-0243. Resolving a root never registers routes. */
final class ApiRoot
{
    public static function for(string $configKey, string $domain, string $tier = 'beam'): string
    {
        $configured = config($configKey);

        return is_string($configured) && trim($configured, '/') !== ''
            ? trim($configured, '/') : trim($tier, '/').'/'.trim($domain, '/');
    }

    /** Refuse an earlier site/SPA catch-all that can shadow this package's group. */
    public static function mount(Router $router, string $configKey, string $domain, Closure $routes, string $tier = 'beam'): void
    {
        $root = self::for($configKey, $domain, $tier);
        $groups = $router->getGroupStack();
        $prefix = $groups === [] ? '' : ($groups[array_key_last($groups)]['prefix'] ?? '');
        $path = '/'.trim($prefix.'/'.$root, '/');
        $earlier = $router->getRoutes()->getRoutes();
        $before = array_fill_keys(array_map('spl_object_id', $earlier), true);
        $router->prefix($root)->group($routes);
        $mounted = array_filter($router->getRoutes()->getRoutes(), fn (Route $route) => ! isset($before[spl_object_id($route)]));

        foreach ($earlier as $route) {
            // Laravel tries fallback routes last, regardless of registration order.
            if ($route->isFallback || $route->parameterNames() === []) {
                continue;
            }
            $regex = $route->toSymfonyRoute()->compile()->getRegex();
            if (preg_match($regex, $path.'/__beam_mount_probe__') !== 1
                || preg_match($regex, $path.'/__beam_mount_probe__/child') !== 1) {
                continue;
            }
            foreach ($mounted as $packageRoute) {
                if (array_intersect($route->methods(), $packageRoute->methods()) === []
                    || ! self::domainsOverlap($route, $packageRoute)) {
                    continue;
                }
                $name = $route->getName() ?? $route->uri();
                throw new LogicException("API root [{$root}] is shadowed by earlier catch-all [{$name}]. Mount package roots before site/SPA catch-alls, or exclude the tier from their constraints.");
            }
        }
    }

    /**
     * Whether the earlier route can answer on a host the package route is served on.
     *
     * An UNDOMAINED package route answers on every host, but it is served where the app is: the app's own host and the
     * tenant hosts under it. An earlier catch-all constrained to a LITERAL domain only shadows it on that one host, so
     * it overlaps only when that host IS the app's own host (a constraint to the app's own host constrains nothing).
     * A dedicated host, such as data-schemas' `schemas.<host>` door, therefore does not refuse the mount. This mirrors
     * `Schemastud\DataSchemas\Http\SchemaDoorMount::claimsTheSite()`, which judges the same door from the other side.
     * A PATTERNED earlier domain can match tenant hosts, so it stays guarded. An app URL that names no host cannot be
     * judged, so the guard fails closed and refuses.
     */
    private static function domainsOverlap(Route $earlier, Route $mounted): bool
    {
        $left = $earlier->getDomain();
        $right = $mounted->getDomain();
        if ($right === null && $left !== null && ! str_contains($left, '{')) {
            return self::isAppHost($left);
        }
        if ($left === null || $right === null || $left === $right) {
            return true;
        }
        if (! str_contains($right, '{')) {
            return preg_match($earlier->toSymfonyRoute()->compile()->getHostRegex(), $right) === 1;
        }
        if (! str_contains($left, '{')) {
            return preg_match($mounted->toSymfonyRoute()->compile()->getHostRegex(), $left) === 1;
        }

        // Two domain patterns can intersect; keep their shared namespace guarded.
        return true;
    }

    /** Whether a literal domain is the app's own host (`app.url`), compared as the domain constraint matches. */
    private static function isAppHost(string $domain): bool
    {
        $appUrl = config('app.url');
        $appHost = is_string($appUrl) ? parse_url(trim($appUrl), PHP_URL_HOST) : null;
        if (! is_string($appHost) || $appHost === '') {
            return true;
        }

        return strcasecmp(self::bareHost($domain), self::bareHost($appHost)) === 0;
    }

    /** A host without its port or trailing dot (a bracketed IPv6 literal keeps its colons). */
    private static function bareHost(string $host): string
    {
        $host = trim($host);
        if (! str_starts_with($host, '[')) {
            $host = preg_replace('/:\d+$/', '', $host) ?? $host;
        }

        return rtrim($host, '.');
    }
}
