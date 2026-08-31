<?php

namespace Splicewire\Beam\Routing;

use Illuminate\Container\Container;
use Illuminate\Routing\Route;

/**
 * The static front door onto {@see RouteMetadataReader} — the read side of the `->beam()` route-metadata
 * namespace (api-surface-coherence tickets 15 and 126).
 *
 * **The contract, and every word of its prose, lives on {@see RouteMetadataReader}. Read it there.**
 * This class holds no logic: each method resolves the bound reader and forwards. The bodies moved to
 * {@see RouteActionMetadataReader}.
 *
 * Ticket 126's objection was that a `static` is testable but not SUBSTITUTABLE — a consumer whose body
 * says `BeamRouteAction::returns($route)` has no seam at which a test can hand it a different reader.
 * The eleven `laravel-beam` consumers now take a {@see RouteMetadataReader} as a constructor dependency
 * and this front door is what the rest of the estate keeps using.
 *
 * **Why it is retained rather than deleted.** Nineteen of the 37 estate-wide invocations live outside
 * this package: four in production — `splicewire/tower`'s `RouteManifest` and the flagship's
 * `ManifestDrivenTransformedProvider`, two apiece — and fifteen more across five of the flagship's own
 * test files. So deleting the statics turns a refactor that changes no behaviour into a three-repo flag
 * day. It is kept, and it is *itself* substitutable: {@see reader()} reads the container, so rebinding
 * `RouteMetadataReader` changes what these statics answer.
 *
 * ⚠️ **This class was PURE before 126 — no container touch — and the fallback in {@see reader()} is what
 * keeps that true.** Making the statics unconditionally container-dependent would newly make them
 * boot-order-sensitive, which is the estate's recurring defect class (api-surface-coherence 91: a
 * host-dependent check that threw at boot and took `~/Herd/tower` off the air). Unbound, they behave
 * exactly as they did; bound, they follow the binding.
 */
class BeamRouteAction
{
    /** The declared response DTO, or null when the route declares none. */
    public static function returns(Route $route): ?string
    {
        return static::reader()->returns($route);
    }

    /** Whether the declared response DTO is a list rather than a single instance. */
    public static function returnsMany(Route $route): bool
    {
        return static::reader()->returnsMany($route);
    }

    /**
     * The declared SSE event map, `event name => list of frame DTOs`.
     *
     * @return array<string, list<class-string>>
     */
    public static function streams(Route $route): array
    {
        return static::reader()->streams($route);
    }

    /** The declared exposure tier, or null when undeclared. */
    public static function visibility(Route $route): ?RouteVisibility
    {
        return static::reader()->visibility($route);
    }

    /** The OpenAPI `operationId` the MOUNT declared for this route, or null when it declared none. */
    public static function operationId(Route $route): ?string
    {
        return static::reader()->operationId($route);
    }

    /** The resource key this route belongs to, however it was stamped. */
    public static function resourceKey(Route $route): ?string
    {
        return static::reader()->resourceKey($route);
    }

    /**
     * The bound reader, or the default one when nothing is bound.
     *
     * `Container::getInstance()` rather than the `app()` helper because this class is reachable from
     * places the helper is not guaranteed to be (a package harness that has not booted the framework's
     * function file), and `bound()` rather than a bare `make()` because an unbound container must not
     * turn a pure read into a `BindingResolutionException`.
     */
    public static function reader(): RouteMetadataReader
    {
        $container = Container::getInstance();

        return $container->bound(RouteMetadataReader::class)
            ? $container->make(RouteMetadataReader::class)
            : new RouteActionMetadataReader;
    }
}
