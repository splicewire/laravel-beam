<?php

namespace Splicewire\Beam\Surgeon;

use Illuminate\Routing\Route as RouteInstance;
use Illuminate\Support\Facades\Route;
use ReflectionClass;
use ReflectionMethod;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Source\RouteManifestSource;

/**
 * The **negative-space detector**: the full live surface MINUS the declared set.
 *
 * The signal is deliberately a SET DIFFERENCE, not generated output. Generated output is a whitelist and is
 * structurally incapable of reporting absence — it can only describe entries it already contains, so "we
 * generated nothing for this" and "this does not exist" look identical from inside it. A set difference
 * always reports absence; a whitelist never can.
 *
 * That is why this walks `Route::getRoutes()` — the real router — rather than a
 * {@see RouteManifestSource}. Note that {@see SdkReturnsCoverageAudit} does NOT walk
 * the full table despite being the obvious seed: its `forApp()` scopes to the two bound manifest-source
 * realms, deliberately and correctly for its own purpose (finding routes plausibly eligible for
 * `->returns()`). Reusing that scoping here would reproduce the exact defect this audit exists to remove.
 *
 * A route counts as DECLARED when any of the invariant's legal declaration sites answers for it:
 *   - an explicit `->returns()` / `->streams()` on the route;
 *   - a particle RESOURCE route whose resource declares a read Data class;
 *   - a particle OPERATION route whose operation declares an `output:` slot;
 *   - a controller method carrying `#[ResponseFromData]`.
 *
 * Everything else is negative space, and each finding names the surface AND its location so the report is a
 * work-list rather than a score.
 *
 * **Tiering is by how mechanically convertible the surface is**, which is what decides whether a human is
 * needed:
 *   - {@see TIER_MECHANICAL} — already on the particle pipeline, just missing its declaration. Adding the
 *     slot is a local, decision-free edit.
 *   - {@see TIER_GUIDED} — a resolvable controller action off the pipeline. A human adds `#[ResponseFromData]`,
 *     which is a real API-contract commitment, so it is not automatable.
 *   - {@see TIER_MANUAL} — a closure or an unresolvable action. There is no method to annotate; a controller
 *     has to exist first.
 *
 * Route closures are REPORTED rather than skipped. A closure has no resolvable action, so every static
 * analysis in the pipeline silently passes over it — which made closures the least visible surface in the
 * estate despite being counted in the baseline (10 in numero, 15 in audiostud, 30 in splicewire-app).
 */
class UndeclaredSurfaceAudit implements DoctorAudit
{
    public const CHECK = 'particle.undeclared-surface';

    public const TIER_MECHANICAL = 'mechanical';

    public const TIER_GUIDED = 'guided';

    public const TIER_MANUAL = 'manual';

    /**
     * URI prefixes outside the doctrine's extension, or exempt at the site.
     *
     * The first two are NOT exceptions to the invariant — they sit outside its extension entirely (a health
     * probe and the broadcast auth handshake are not boundary-crossing application data shapes). The rest are
     * the four argued exceptions: the RFC 8628 device-authorization grant (IETF-frozen wire format the spec
     * forbids evolving), the vendor-mounted MCP OAuth discovery routes (you cannot annotate routes you did
     * not declare), and Sanctum/tenancy vendor internals (declaring auth into a registry that needs auth to
     * boot is circular).
     *
     * @var list<string>
     */
    public const DEFAULT_EXEMPT_URIS = [
        'up',
        'health',
        'broadcasting/auth',
        'oauth/device',
        'oauth/token',
        'oauth/authorize',
        '.well-known/oauth-authorization-server',
        '.well-known/oauth-protected-resource',
        'sanctum/',
    ];

    /**
     * Namespace prefixes whose controllers are exempt: vendor-owned actions we did not declare, plus beam's
     * own pre-boot installer path — narrowly, because only code executing before registries exist can be
     * excused from registering into them.
     *
     * @var list<string>
     */
    public const DEFAULT_EXEMPT_NAMESPACES = [
        'Laravel\\Sanctum\\',
        'Stancl\\Tenancy\\',
        'Laravel\\Mcp\\',
        'Splicewire\\Beam\\Install\\',
    ];

    public function __construct(
        private readonly ParticleResourceRegistry $resources,
        private readonly ParticleOperationRegistry $operations,
        /** @var list<string> */
        private readonly array $exemptUris = self::DEFAULT_EXEMPT_URIS,
        /** @var list<string> */
        private readonly array $exemptNamespaces = self::DEFAULT_EXEMPT_NAMESPACES,
    ) {}

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        $rows = $this->undeclared();

        if ($rows === []) {
            return [Finding::pass(self::CHECK, 'Every live HTTP surface declares its shape.')];
        }

        return array_map(
            fn (array $row) => Finding::warn(self::CHECK, sprintf(
                '[%s] %s %s — undeclared shape at %s',
                $row['tier'],
                implode('|', $row['methods']),
                $row['uri'],
                $row['location'],
            )),
            $rows,
        );
    }

    /**
     * The negative space itself, as sorted rows — the artifact payload and the work-list.
     *
     * Sorted by uri then methods so a re-run with no code change produces a byte-identical artifact. Router
     * registration order is not stable enough to commit against; without this the artifact would churn in
     * review and its "the number only goes down" contract would be unreadable.
     *
     * @return list<array{uri: string, methods: list<string>, name: string|null, action: string, tier: string, location: string}>
     */
    public function undeclared(): array
    {
        $rows = [];

        foreach (Route::getRoutes() as $route) {
            if ($this->isExempt($route) || $this->isDeclared($route)) {
                continue;
            }

            $rows[] = [
                'uri' => $route->uri(),
                'methods' => $this->methods($route),
                'name' => $route->getName(),
                'action' => $this->actionLabel($route),
                'tier' => $this->tierFor($route),
                'location' => $this->locate($route),
            ];
        }

        usort($rows, fn (array $a, array $b) => [$a['uri'], $a['methods']] <=> [$b['uri'], $b['methods']]);

        return $rows;
    }

    /** Whether any of the invariant's legal declaration sites answers for this route. */
    private function isDeclared(RouteInstance $route): bool
    {
        if ($route->getAction('returns') || $route->getAction('streams')) {
            return true;
        }

        $resourceKey = $route->defaults[ParticleController::RESOURCE] ?? null;

        if (is_string($resourceKey) && $this->resources->has($resourceKey) && $this->resources->get($resourceKey)->data) {
            return true;
        }

        $opResource = $route->defaults[ParticleOperationController::RESOURCE] ?? null;
        $opName = $route->defaults[ParticleOperationController::NAME] ?? null;

        if (is_string($opResource) && is_string($opName)
            && $this->operations->find($opResource, $opName)?->output !== null) {
            return true;
        }

        $method = $this->method($route);

        return $method !== null && $method->getAttributes(ResponseFromData::class) !== [];
    }

    /**
     * A surface already on the particle pipeline only lacks its declaration, so converting it is a local
     * decision-free edit; a resolvable controller action needs a human to commit to an API contract; a
     * closure has no method to annotate at all.
     */
    private function tierFor(RouteInstance $route): string
    {
        $onPipeline = isset($route->defaults[ParticleController::RESOURCE])
            || isset($route->defaults[ParticleOperationController::NAME]);

        if ($onPipeline) {
            return self::TIER_MECHANICAL;
        }

        return $this->method($route) === null ? self::TIER_MANUAL : self::TIER_GUIDED;
    }

    private function isExempt(RouteInstance $route): bool
    {
        $uri = ltrim($route->uri(), '/');

        foreach ($this->exemptUris as $prefix) {
            if ($uri === $prefix || str_starts_with($uri, rtrim($prefix, '/').'/')) {
                return true;
            }
        }

        $action = $route->getActionName();

        if (is_string($action)) {
            // A route may be declared with a leading-backslash action string, which reaches us verbatim —
            // normalize before matching or a vendor namespace slips past the exemption.
            $action = ltrim($action, '\\');

            foreach ($this->exemptNamespaces as $namespace) {
                if (str_starts_with($action, $namespace)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** The route's controller method, or null for a closure / invokable / vanished action. */
    private function method(RouteInstance $route): ?ReflectionMethod
    {
        $action = $route->getAction('controller');

        if (! is_string($action) || ! str_contains($action, '@')) {
            return null;
        }

        [$class, $method] = explode('@', $action, 2);

        if (! class_exists($class) || ! method_exists($class, $method)) {
            return null;
        }

        return new ReflectionMethod($class, $method);
    }

    /**
     * Where to go to fix it: the controller file and line for a resolvable action, else the route's own
     * identity. A finding without a location is a score, not a work-list.
     */
    private function locate(RouteInstance $route): string
    {
        $method = $this->method($route);

        if ($method === null) {
            return sprintf('closure route [%s]', $route->getName() ?? $route->uri());
        }

        $file = (new ReflectionClass($method->class))->getFileName();

        return $file === false
            ? $method->class.'::'.$method->name
            : sprintf('%s:%d', $file, $method->getStartLine());
    }

    private function actionLabel(RouteInstance $route): string
    {
        $action = $route->getActionName();

        return is_string($action) ? $action : 'Closure';
    }

    /** @return list<string> */
    private function methods(RouteInstance $route): array
    {
        return array_values(array_filter($route->methods(), fn (string $m) => $m !== 'HEAD'));
    }
}
