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
use Splicewire\Beam\Surface\RuntimeCorroborator;
use Splicewire\Beam\Surgeon\Support\PackageOrigin;

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
 * ## The population is code, never environment (beam-facade ticket 140)
 * Every row carries an `origin` — the composer package that ships its action, resolved through
 * {@see PackageOrigin} off composer's own manifest rather than off the path, because the estate's co-dev
 * overlay symlinks family packages and a path heuristic attributes 226 of the flagship's rows to nothing.
 *
 * **Dev-only packages are held OUT of the counted population, and named in the artifact.** They were the
 * defect that made the number unusable: `barryvdh/laravel-debugbar` and `spatie/laravel-ignition` are
 * `require-dev` at the flagship and mount routes, so the count moved with `--no-dev`, with `APP_ENV`, and
 * with whether a developer had debugbar switched on. A ratchet exists to be compared across two machines
 * and that is exactly what an environment-dependent number cannot be.
 *
 * **Production vendor routes stay IN, in their own origin bucket.** They ship, a caller can hit them, and
 * mounting them was the host's composition decision — see the map's standing "enforcement is a function of
 * host composition" question (ticket 119), of which this artifact is a measured specimen. Dropping them
 * would hide real surface; bucketing them means a delta names its own cause, which is what a bare scalar
 * could not do.
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
        // Beam's own OpenAPI artifact routes (ADR-0211). Outside the extension for the same reason `up`
        // is: the response body is an OpenAPI *document* — the description of the API contract — not a
        // boundary-crossing application data shape, and declaring it would mean modelling OpenAPI itself
        // as a Data class. They can also never appear in the document they serve: the published Scribe
        // stub extracts `api/*` only, so a `beam/*` route is excluded by the same rule that makes the
        // artifact safe to serve publicly. Both spellings, because the exemption matcher is
        // segment-based and these differ only by extension.
        'beam/openapi.yaml',
        'beam/openapi.json',
    ];

    /**
     * Namespace prefixes whose controllers are exempt: vendor-owned actions we did not declare, plus beam's
     * own pre-boot installer path — narrowly, because only code executing before registries exist can be
     * excused from registering into them.
     *
     * ## DESCRIPTION DOCUMENTS (beam-facade ticket 114)
     *
     * The last entry is not a vendor carve-out and not a pre-boot excuse. It is the class the
     * `beam/openapi.{yaml,json}` URI entries above already belong to, finally named: a surface whose
     * response body IS a contract-description document — an OpenAPI document, a JSON Schema document —
     * rather than a projection of application data crossing the boundary. Such a surface is **outside
     * the invariant's extension**, the way `up` and `broadcasting/auth` are, not an exception to it.
     *
     * The test is narrow and mechanical, which is what ticket 25 requires of any rule's subject: the
     * body is a **document in a schema/description media type** (`application/schema+json`,
     * `application/openapi+json`, `application/vnd.oai.openapi`), served as the artifact itself, whose
     * own vocabulary is defined by a specification this estate does not own. "Returns a document" is
     * NOT the test — it is the phrasing a future surface would abuse. `text/markdown` source bytes are
     * application data and stay in the population; so does a 204 with no body at all, which is
     * undeclared for a different reason (there is no shape, rather than an opaque one) and is the
     * `beam-mdx` `ContentAuthoringController` pair ticket 114 left open.
     *
     * Why exemption rather than a Data class: a JSON Schema document has no fixed properties, so the
     * generated TypeScript and OpenAPI shape would say approximately nothing — and modelling JSON
     * Schema (or OpenAPI) as a Data class means modelling someone else's specification, which is the
     * same reason the two artifact URIs above were carved out before this rule had a name.
     *
     * Listed by exact controller FQCN, not by package prefix. `Schemastud\DataSchemas\` is family code
     * and the family is exactly who should be declaring shapes — only THIS door is outside the
     * extension, and a second surface in that namespace must argue its own way onto this list.
     *
     * @var list<string>
     */
    public const DEFAULT_EXEMPT_NAMESPACES = [
        'Laravel\\Sanctum\\',
        'Stancl\\Tenancy\\',
        'Laravel\\Mcp\\',
        'Splicewire\\Beam\\Install\\',
        'Schemastud\\DataSchemas\\Http\\SchemaDocumentController',
    ];

    public function __construct(
        private readonly ParticleResourceRegistry $resources,
        private readonly ParticleOperationRegistry $operations,
        /** @var list<string> */
        private readonly array $exemptUris = self::DEFAULT_EXEMPT_URIS,
        /** @var list<string> */
        private readonly array $exemptNamespaces = self::DEFAULT_EXEMPT_NAMESPACES,
        protected ?PackageOrigin $origins = null,
    ) {}

    /**
     * Rows excluded from the counted population because the package that ships them is `require-dev`.
     *
     * Populated by {@see undeclared()}, so read it after. Kept as rows rather than a tally because the
     * artifact records the excluded packages by name — an exclusion nobody can see is indistinguishable
     * from a check that stopped looking.
     *
     * @var list<array<string, mixed>>
     */
    protected array $excludedDevOnly = [];

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
        $this->excludedDevOnly = [];

        foreach (Route::getRoutes() as $route) {
            if ($this->isExempt($route) || $this->isDeclared($route)) {
                continue;
            }

            $origin = $this->originFor($route);

            $row = [
                'uri' => $route->uri(),
                'methods' => $this->methods($route),
                'name' => $route->getName(),
                'action' => $this->actionLabel($route),
                'tier' => $this->tierFor($route),
                'origin' => $origin,
                'location' => $this->locate($route),
            ];

            if ($this->origins?->isDev($origin)) {
                $this->excludedDevOnly[] = $row;

                continue;
            }

            $rows[] = $row;
        }

        usort($rows, fn (array $a, array $b) => [$a['uri'], $a['methods']] <=> [$b['uri'], $b['methods']]);
        usort($this->excludedDevOnly, fn (array $a, array $b) => [$a['uri'], $a['methods']] <=> [$b['uri'], $b['methods']]);

        return $rows;
    }

    /**
     * The rows the last {@see undeclared()} call held out of the population as dev-only.
     *
     * @return list<array<string, mixed>>
     */
    public function excludedDevOnly(): array
    {
        return $this->excludedDevOnly;
    }

    /**
     * Which package ships this route's action — the field that makes a delta name its own cause.
     *
     * A scalar count is what let two sessions misattribute a 356 → 433 move to
     * `api/v1/beam/accounts/*`, when only 15 of the 433 route strings contained `beam/accounts` at all
     * and 226 of them came from `splicewire/tower`. The composition was always derivable from the rows;
     * nothing recorded it, so nobody derived it.
     */
    private function originFor(RouteInstance $route): string
    {
        if ($this->origins === null) {
            return PackageOrigin::UNKNOWN;
        }

        $method = $this->method($route);

        if ($method === null) {
            // A closure has no file of its own worth attributing — it was written in a route file, which
            // is the host's, or a package's `routes/`. Attributing it to the route file would be more
            // precise and is deliberately not done: `Route::getRoutes()` does not carry it, and
            // reflecting the closure to get one costs a `ReflectionFunction` per route for a field the
            // burn-down never sorts on.
            return PackageOrigin::APP;
        }

        $file = (new ReflectionClass($method->class))->getFileName();

        return $file === false ? PackageOrigin::UNKNOWN : $this->origins->packageFor($file);
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

    /**
     * Whether a URI sits outside the invariant's extension (or is exempt at the site).
     *
     * Public because the corroboration side needs the SAME answer: a surface that owes no data-shape
     * declaration also owes no appearance in the OpenAPI document, and computing that twice is how the two
     * directions drift apart. {@see RuntimeCorroborator::posture()} reads it.
     */
    public function exemptsUri(string $uri): bool
    {
        $uri = ltrim($uri, '/');

        foreach ($this->exemptUris as $prefix) {
            if ($uri === $prefix || str_starts_with($uri, rtrim($prefix, '/').'/')) {
                return true;
            }
        }

        return false;
    }

    private function isExempt(RouteInstance $route): bool
    {
        if ($this->exemptsUri($route->uri())) {
            return true;
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

    /**
     * The route's controller method, or null for a closure / vanished action.
     *
     * An INVOKABLE controller resolves here too (beam-facade ticket 124). Laravel records it as a bare
     * class string with no `@method` segment, so the obvious `str_contains('@')` test reads it as a
     * closure — and an invokable controller then landed in {@see TIER_MANUAL} ("there is no method to
     * annotate; a controller has to exist first"), which is the one thing that is certainly false about
     * it. That mis-tiering was not cosmetic: `__invoke`'s own `#[ResponseFromData]` was structurally
     * invisible to {@see isDeclared()}, so beam's OWN public intake door counted as negative space no
     * matter what it declared.
     */
    private function method(RouteInstance $route): ?ReflectionMethod
    {
        $action = $route->getAction('controller');

        if (! is_string($action)) {
            return null;
        }

        [$class, $method] = str_contains($action, '@')
            ? explode('@', $action, 2)
            : [ltrim($action, '\\'), '__invoke'];

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

        if ($file === false) {
            return $method->class.'::'.$method->name;
        }

        // Relativized when an origin resolver is available: an absolute path is per-machine, and the
        // estate's co-dev overlay makes most of them per-machine in a way that does not even mention
        // `vendor/`. An artifact that names the author's home directory is not committable.
        return sprintf('%s:%d', $this->origins?->relativize($file) ?? $file, $method->getStartLine());
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
