<?php

namespace Splicewire\Beam\Routing;

use Closure;
use Illuminate\Routing\Router;
use LogicException;

/** Package-owned mount roots; ADR-0243. Resolving a root never registers routes. */
final class ApiRoot
{
    public static function for(string $configKey, string $domain, string $tier = 'beam'): string
    {
        return trim(config($configKey, trim($tier, '/').'/'.trim($domain, '/')), '/');
    }

    /** Refuse an earlier site/SPA catch-all before registering the package's group. */
    public static function mount(Router $router, string $configKey, string $domain, Closure $routes, string $tier = 'beam'): void
    {
        $root = self::for($configKey, $domain, $tier);
        $groups = $router->getGroupStack();
        $prefix = $groups === [] ? '' : ($groups[array_key_last($groups)]['prefix'] ?? '');
        $path = '/'.trim($prefix.'/'.$root, '/');

        foreach ($router->getRoutes() as $route) {
            if ($route->parameterNames() === []) {
                continue;
            }

            // Use Laravel/Symfony's compiled constraints, so a catch-all that excludes
            // this tier is allowed. A normal single-segment parameter is not a catch-all.
            $regex = $route->toSymfonyRoute()->compile()->getRegex();
            if (preg_match($regex, $path.'/__beam_mount_probe__') === 1
                && preg_match($regex, $path.'/__beam_mount_probe__/child') === 1) {
                $name = $route->getName() ?? $route->uri();
                throw new LogicException("API root [{$root}] is shadowed by earlier catch-all [{$name}]. Mount package roots before site/SPA catch-alls, or exclude the tier from their constraints.");
            }
        }

        $router->prefix($root)->group($routes);
    }
}
