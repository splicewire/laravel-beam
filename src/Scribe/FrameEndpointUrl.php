<?php

namespace Splicewire\Beam\Scribe;

use Illuminate\Routing\Route;
use Knuckles\Scribe\Extracting\Shared\UrlParamsNormalizer;
use Knuckles\Scribe\Scribe;
use Knuckles\Scribe\Tools\Globals;
use ReflectionClass;
use ReflectionFunctionAbstract;
use Schemastud\Frame\Http\Controllers\FrameResourceController;

/** Preserve the generic Frame socket's selector before Scribe builds path-parameter metadata. */
class FrameEndpointUrl
{
    public static function register(): void
    {
        // Scribe is optional at runtime. A host's explicit normalizer always takes precedence.
        if (! class_exists(Scribe::class) || is_callable(Globals::$__normalizeEndpointUrlUsing)) {
            return;
        }

        Scribe::normalizeEndpointUrlUsing(static function (
            string $uri,
            Route $route,
            ReflectionFunctionAbstract $method,
            ?ReflectionClass $controller,
        ): string {
            // `resource` is a registry selector, not an Eloquent resource ID. Stock normalization
            // changes it to `id`, collapsing two independent inputs on records/{id} routes.
            if ($controller !== null && is_a($controller->getName(), FrameResourceController::class, true)) {
                return $uri;
            }

            return UrlParamsNormalizer::normalizeParameterNamesInRouteUri($route, $method);
        });
    }
}
