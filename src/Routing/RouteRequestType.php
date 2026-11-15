<?php

namespace Splicewire\Beam\Routing;

use Illuminate\Routing\Route;
use ReflectionMethod;
use Rushing\LaravelDataSchemasScribe\Attributes\RequestFromData;

/** The request-body declaration on a controller method, shared by client projection and type discovery. */
final class RouteRequestType
{
    /** @var array<string, class-string|null> */
    private array $declarations = [];

    /** @return class-string|null */
    public function fromRequestAttribute(Route $route): ?string
    {
        $action = $route->getAction('controller');

        if (! is_string($action) || ! str_contains($action, '@')) {
            return null;
        }

        if (array_key_exists($action, $this->declarations)) {
            return $this->declarations[$action];
        }

        [$class, $method] = explode('@', $action, 2);

        if (! class_exists($class) || ! method_exists($class, $method)) {
            return $this->declarations[$action] = null;
        }

        $attributes = (new ReflectionMethod($class, $method))->getAttributes(RequestFromData::class);

        return $this->declarations[$action] = ($attributes[0] ?? null)?->newInstance()->dataClass;
    }
}
