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

    private static function domainsOverlap(Route $earlier, Route $mounted): bool
    {
        $left = $earlier->getDomain();
        $right = $mounted->getDomain();
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
}
