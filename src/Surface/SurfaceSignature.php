<?php

namespace Splicewire\Beam\Surface;

/**
 * Normalizes a `METHOD /path` signature so a *document's* wording of a path and a *router's* wording of
 * the same path compare equal.
 *
 * The two disagree in ways that carry no meaning: a router stores `api/v1/specs/{spec}` where a spec
 * writes `/api/v1/specs/{id}`, and a route may mark a segment optional (`{id?}`) or constrain it
 * inline (`{id:uuid}`). Matching on the raw strings would report every parameterized route as
 * undocumented — a wall of false findings that would bury the real ones.
 *
 * So a placeholder collapses to a bare `{}`: **position is compared, the parameter's name is not.** The
 * trade is deliberate and worth stating, because it is the one way this can be wrong: two sibling routes
 * that differ only in a placeholder's name (`/x/{id}` and `/x/{slug}`) become one signature. Nothing in
 * this estate mounts such a pair, and the alternative — a false "undocumented surface" on every route
 * with a parameter — is much the worse failure.
 */
class SurfaceSignature
{
    public static function normalize(string $signature): string
    {
        [$method, $path] = array_pad(explode(' ', trim($signature), 2), 2, '');

        return strtoupper($method).' '.self::normalizePath($path);
    }

    public static function normalizePath(string $path): string
    {
        $path = '/'.ltrim(trim($path), '/');
        $path = rtrim($path, '/');

        return preg_replace('/\{[^}]*\}/', '{}', $path === '' ? '/' : $path) ?? $path;
    }
}
