<?php

namespace Splicewire\Beam\Http;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use ReflectionMethod;

/**
 * The hand-written write routes nothing gates (app-walkthrough APP-08, APP-11). A route described by the trio
 * (`#[RequestFromData]`) has no `ability:` slot, unlike a particle operation, so who may call it is declared only by
 * the route or the handler. A POST/PUT/PATCH/DELETE trio route counts as GATED when any of these holds:
 *
 * - its middleware includes a `can:` or `require.*` gate;
 * - its handler calls the authorization API (`authorize(`, `Gate::authorize|allows|denies|check|inspect(`, `->can(`);
 * - its request Data declares `authorize()`.
 *
 * Everything else is listed, and a route with no authentication middleware at all is marked PUBLIC (a door by
 * design, or an open write). Ids are stable across edits: `METHODS uri`.
 */
final class UngatedWrites
{
    public const ATTRIBUTE = 'Rushing\\LaravelDataSchemasScribe\\Attributes\\RequestFromData';

    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /** @return array<string, array{handler: string, public: bool}> id => what it is, sorted by id */
    public function find(): array
    {
        $found = [];

        foreach (Router::getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            $methods = array_values(array_intersect($route->methods(), self::WRITE_METHODS));
            $handler = $this->handler($route);
            if ($methods === [] || $handler === null) {
                continue;
            }

            $attributes = $handler->getAttributes(self::ATTRIBUTE);
            if ($attributes === []) {
                continue;
            }

            $middleware = $this->middleware($route);
            if ($this->gatedByMiddleware($middleware) || $this->handlerAuthorizes($handler) || $this->dataAuthorizes($attributes)) {
                continue;
            }

            $found[implode('|', $methods).' '.$route->uri()] = [
                'handler' => $handler->getDeclaringClass()->getName().'@'.$handler->getName(),
                'public' => ! $this->authenticated($middleware),
            ];
        }

        ksort($found);

        return $found;
    }

    private function handler(Route $route): ?ReflectionMethod
    {
        $uses = $route->getAction('uses');
        if (! is_string($uses) || ! str_contains($uses, '@')) {
            return null;
        }

        [$class, $method] = explode('@', $uses, 2);

        return method_exists($class, $method) ? new ReflectionMethod($class, $method) : null;
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

    /** @param  list<string>  $middleware */
    private function authenticated(array $middleware): bool
    {
        foreach ($middleware as $name) {
            if ($name === 'auth' || str_starts_with($name, 'auth:') || str_contains($name, 'Authenticate')) {
                return true;
            }
        }

        return false;
    }

    private function handlerAuthorizes(ReflectionMethod $handler): bool
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

        return preg_match('/->authorize\(|Gate::(authorize|allows|denies|check|inspect)\(|->can\(|->cannot\(/', $body) === 1;
    }

    /** @param  list<\ReflectionAttribute<object>>  $attributes */
    private function dataAuthorizes(array $attributes): bool
    {
        foreach ($attributes as $attribute) {
            $data = $attribute->getArguments()[0] ?? null;
            if (is_string($data) && method_exists($data, 'authorize')) {
                return true;
            }
        }

        return false;
    }
}
