<?php

namespace Splicewire\Beam\Http;

use Closure;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * The write routes nothing gates (app-walkthrough APP-08, APP-11). A hand-written route has no `ability:` slot, unlike
 * a particle operation, so who may call it is declared only by the route or the handler. Every POST/PUT/PATCH/DELETE
 * route with a controller or closure handler is in scope, except:
 *
 * - particle routes ({@see PARTICLE}): their operations and resources declare `ability:` and are audited by
 *   `particle.ungated-op`;
 * - the framework and vendor-auth namespaces in {@see FRAMEWORK}: their doors are the framework's own (sign-in,
 *   OAuth, passkeys, signed webhooks), named here so the exemption is a reviewed list rather than a guess.
 *
 * A route in scope counts as GATED when any of these holds:
 *
 * - its middleware includes a `can:` or `require.*` gate;
 * - its handler's code (comments stripped) calls the authorization API (`->authorize(`,
 *   `Gate::authorize|allows|denies|check|inspect(`, `->can(`, `->cannot(`);
 * - its request Data (`#[RequestFromData]`) or a FormRequest parameter declares `authorize()`.
 *
 * Everything else is listed. A route with no authentication middleware (`auth`, `auth:*` or an Authenticate class;
 * `AuthenticateSession` authenticates nobody) is marked PUBLIC: a door by design, or an open write. Ids are stable
 * across edits: `METHODS uri`.
 */
final class UngatedWrites
{
    public const ATTRIBUTE = 'Rushing\\LaravelDataSchemasScribe\\Attributes\\RequestFromData';

    public const PARTICLE = 'Splicewire\\Beam\\Http\\Particle\\';

    /** Namespaces whose write routes are the framework's own doors, not a host's hand-written writes. */
    public const FRAMEWORK = [
        'Illuminate\\',
        'Laravel\\Cashier\\',
        'Laravel\\Fortify\\',
        'Laravel\\Horizon\\',
        'Laravel\\Mcp\\',
        'Laravel\\Passkeys\\',
        'Laravel\\Passport\\',
        'Laravel\\Pulse\\',
        'Laravel\\Sanctum\\',
        'Laravel\\Telescope\\',
        'Barryvdh\\Debugbar\\',
        'Livewire\\',
        'Spatie\\LaravelIgnition\\',
    ];

    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    private const AUTHORIZES = '/->authorize\(|Gate::(authorize|allows|denies|check|inspect)\(|->can\(|->cannot\(/';

    /** @return array<string, array{handler: string, public: bool}> id => what it is, sorted by id */
    public function find(): array
    {
        $found = [];

        foreach (Router::getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            $methods = array_values(array_intersect($route->methods(), self::WRITE_METHODS));
            $handler = $this->handler($route);
            if ($methods === [] || $handler === null || $this->exempt($handler)) {
                continue;
            }

            $middleware = $this->middleware($route);
            if ($this->gatedByMiddleware($middleware) || $this->handlerAuthorizes($handler) || $this->requestAuthorizes($handler)) {
                continue;
            }

            $found[implode('|', $methods).' '.$route->uri()] = [
                'handler' => $this->describe($handler),
                'public' => ! $this->authenticated($middleware),
            ];
        }

        ksort($found);

        return $found;
    }

    private function handler(Route $route): ?ReflectionFunctionAbstract
    {
        $uses = $route->getAction('uses');
        if ($uses instanceof Closure) {
            return new ReflectionFunction($uses);
        }
        if (! is_string($uses)) {
            return null;
        }

        [$class, $method] = str_contains($uses, '@') ? explode('@', $uses, 2) : [$uses, '__invoke'];
        $class = ltrim($class, '\\');

        return method_exists($class, $method) ? new ReflectionMethod($class, $method) : null;
    }

    /** The namespace a handler is declared in: its class, or for a closure the class it was written in. */
    private function owner(ReflectionFunctionAbstract $handler): string
    {
        if ($handler instanceof ReflectionMethod) {
            return $handler->getDeclaringClass()->getName();
        }

        return $handler instanceof ReflectionFunction ? ($handler->getClosureScopeClass()?->getName() ?? '') : '';
    }

    private function exempt(ReflectionFunctionAbstract $handler): bool
    {
        $owner = $this->owner($handler);

        foreach ([self::PARTICLE, ...self::FRAMEWORK] as $prefix) {
            if (str_starts_with($owner, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function describe(ReflectionFunctionAbstract $handler): string
    {
        if ($handler instanceof ReflectionMethod) {
            return $handler->getDeclaringClass()->getName().'@'.$handler->getName();
        }

        $file = (string) $handler->getFileName();
        $base = rtrim(base_path(), '/').'/';

        return 'Closure '.(str_starts_with($file, $base) ? substr($file, strlen($base)) : $file).':'.$handler->getStartLine();
    }
    /**
     * The route's middleware names. `gatherMiddleware()` builds and caches the controller, so it is dropped again
     * unless the route already held one (the care ResourceMountMap::commonMiddleware takes).
     *
     * @return list<string>
     */
    private function middleware(Route $route): array
    {
        $held = $route->controller !== null;
        $middleware = array_values(array_filter($route->gatherMiddleware(), 'is_string'));
        if (! $held) {
            $route->controller = null;
        }

        return $middleware;
    }

    /** @param  list<string>  $middleware */
    private function gatedByMiddleware(array $middleware): bool
    {
        foreach ($middleware as $name) {
            if (str_starts_with($name, 'can:') || str_starts_with($name, 'require.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * `auth`, `auth:<guard>`, or a class that is (or extends) the framework's Authenticate. `AuthenticateSession` and
     * other names that merely contain the word authenticate nobody.
     *
     * @param  list<string>  $middleware
     */
    private function authenticated(array $middleware): bool
    {
        foreach ($middleware as $name) {
            $class = ltrim(explode(':', $name, 2)[0], '\\');
            if ($class === 'auth' || (class_exists($class) && is_a($class, Authenticate::class, true))) {
                return true;
            }
        }

        return false;
    }

    /** The handler's code with comments removed, so a commented-out gate does not count. */
    private function handlerAuthorizes(ReflectionFunctionAbstract $handler): bool
    {
        $file = $handler->getFileName();
        if ($file === false || ! is_readable($file)) {
            return false;
        }

        $body = implode('', array_slice(
            file($file) ?: [],
            $handler->getStartLine() - 1,
            $handler->getEndLine() - $handler->getStartLine() + 1,
        ));

        $code = '';
        foreach (token_get_all('<?php '.$body) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        return preg_match(self::AUTHORIZES, $code) === 1;
    }

    /** A `#[RequestFromData]` Data or a FormRequest parameter that declares `authorize()`. */
    private function requestAuthorizes(ReflectionFunctionAbstract $handler): bool
    {
        foreach ($handler->getAttributes(self::ATTRIBUTE) as $attribute) {
            $data = $attribute->getArguments()[0] ?? null;
            if (is_string($data) && method_exists($data, 'authorize')) {
                return true;
            }
        }

        foreach ($handler->getParameters() as $parameter) {
            $type = $parameter->getType();
            $class = $type instanceof ReflectionNamedType && ! $type->isBuiltin() ? $type->getName() : null;
            if ($class !== null && is_a($class, FormRequest::class, true) && method_exists($class, 'authorize')) {
                return true;
            }
        }

        return false;
    }
}
