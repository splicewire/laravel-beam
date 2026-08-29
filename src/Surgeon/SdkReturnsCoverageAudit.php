<?php

namespace Splicewire\Beam\Surgeon;

use Illuminate\Support\Facades\Route;
use PhpParser\Node;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData;
use Rushing\Surgeon\Operation\FixableFinding;
use Rushing\Surgeon\Operation\OperationSuggestion;
use Rushing\Surgeon\Operation\SuggestsOperations;
use Rushing\Surgeon\Rewrite\LiteralRewriteOperation;
use Splicewire\Beam\Console\GenerateClientSdkCommand;
use Splicewire\Beam\Source\RouteManifestSource;

/**
 * `->returns()` coverage audit (ticket 25, `/grill-me` follow-on to {@see SdkHookMigrationAudit}). 324 of
 * ~346 routes lack `->returns(DtoClass::class)` — the macro (`ParticleServiceProvider::returns()`) gating
 * whether `splicewire:beam:generate:client` emits a hook. This is the upstream half {@see SdkHookMigrationAudit}
 * deliberately deferred (decision 2 there): finding routes plausibly eligible for the annotation.
 *
 * **Audit-only — no {@see SuggestsOperations}.** Adding `->returns()` is a real
 * API-contract commitment (the route becomes typed SDK surface); the filter-detection leg below has a
 * genuine false-positive mode (a route filtered via a provider-registered `ParticleResource`, invisible
 * from the controller body). A human adds the annotation; this audit only tells them where.
 *
 * **Three explicit tiers, all surfaced.** No reusable FIT-detection logic exists anywhere in the codegen
 * pipeline (`TsClientGenerator::typedEntries()` only checks for a `returns` key) — this had to be built
 * fresh. Per action, in order:
 *   - a `DataFilter::query(...)` call found anywhere in the body → **not a finding at all** (confidently
 *     unfit — the same epistemic status as an already-`returns()`-annotated route, not worth a human's
 *     review time);
 *   - else `#[ResponseFromData(DataClass::class)]` present → **High**;
 *   - else a DTO construction found (`SomeData::from(...)`, `::fromModel(...)`, `new SomeData(...)`,
 *     matching this app's `*Data` DTO-suffix convention) → **Medium**;
 *   - else → **Low/needs-review** — genuinely can't tell (no DTO signal, and an absent filter idiom here
 *     does NOT prove unfiltered — filtering can be wired invisibly in a service provider's
 *     `ParticleResource` registration). Reported, not dropped — the exact "ledger drift" failure mode
 *     ticket 23 already hit once with a stale doc.
 *
 * **Orthogonal to the route-visibility macro** (ticket 24, built independently/in parallel). `->returns()`
 * serves this app's own first-party SPA; visibility is a separate, later, public-OpenAPI-spec concern. No
 * dependency either direction.
 *
 * **A STREAMING route is not undeclared surface** (overnight-alignment Wave 1 item 3). `returns` is one of
 * the manifest's two shape slots; the other is `streams`, and {@see \Splicewire\Beam\Routing\RouteReturnType}
 * says in its own docblock that *"a stream has no single response type — it emits discrete typed events
 * under distinct wire names — so it deliberately resolves nothing from `for()`"*. A route carrying a
 * populated `streams` map has therefore declared its wire shape at the only site that can express it, and
 * asking it for a `->returns()` is a category error, not a gap. {@see declaresShape()} reads BOTH slots.
 *
 * Measured at the flagship 2026-08-29 before the fix: 3 of 231 findings were streaming routes
 * (`circuits.op.run`, `circuits.runs.resume`, `thread.completions.stream`), and `thread.completions.stream`
 * was one of only TWO findings the tiering rated `medium` — i.e. the audit's highest-confidence
 * recommendation was to add a single response type to an SSE emitter. This is the estate's recurring
 * defect class: the instrument counts `returns:` slots, the question is *"does this surface declare a
 * shape?"*, and the answer to the first was being written down as the answer to the second.
 *
 * **Step 2 (call-chain following).** Most controllers delegate DTO construction to a service/trait method
 * rather than building the DTO inline, which the direct-body checks above can't see. When the direct body
 * resolves nothing, {@see classifyViaCallChain()} follows exactly ONE level into any `$this->method()` or
 * `$this->property->method()` (an injected dependency, resolved via constructor parameter types) reachable
 * from the action, and re-runs the same three checks against that callee. Bounded to one level — does not
 * recurse into what the callee itself calls.
 */
class SdkReturnsCoverageAudit implements DoctorAudit, SuggestsOperations
{
    public const CHECK = 'sdk.returns-coverage';

    public const TIER_HIGH = 'high';

    public const TIER_MEDIUM = 'medium';

    public const TIER_LOW = 'low';

    /**
     * @param  list<array{routeName: ?string, uri: string, controllerFile: ?string, controllerClass: ?string, actionMethod: ?string}>  $routes
     * @param  array<string, string>  $routeFiles  route files searched to locate a literal `->name(...)`
     *                                             call site for the apply half (ticket 26), keyed by absolute path to the BASE name prefix
     *                                             `RouteServiceProvider`/`TenancyServiceProvider` wraps that file's `Route::group(...)` mount
     *                                             with (e.g. `routes/api.php` → `'api.v1.'`) — a route's full resolved name is this prefix plus
     *                                             whatever `->name(...)` prefix nesting is found inside the file itself.
     * @param  list<array{key: string, class: string}>  $unresolvableBindings  config-sourced realm bindings whose class does not
     *                                                                         exist (particle-doctrine-followups #04) — previously
     *                                                                         swallowed by a silent `continue` in {@see forApp()};
     *                                                                         each now surfaces as a Fail finding.
     */
    public function __construct(
        protected array $routes,
        protected array $routeFiles = [],
        protected array $unresolvableBindings = [],
    ) {}

    /**
     * The default host wiring: every route-name the app's bound {@see RouteManifestSource}s (the SAME
     * `beam.client.sources.{defaults,admin}` realms {@see GenerateClientSdkCommand}
     * targets) enumerate lacking `returns` — NOT every route in the router. Raw `Route::getRoutes()` (as
     * {@see SdkEndpointDriftAudit} uses) pulls in web/dev/mcp/federation routes that were never eligible
     * for a generated hook in the first place; scoping to the two realms actually keeps this audit's
     * candidates real. Each in-scope name is then resolved back to its live {@see \Illuminate\Routing\Route}
     * (for the controller/action) via `Route::getRoutes()->getByName()`.
     *
     * Kept off the constructor so the class is pure-unit testable with in-memory rows — no router, no
     * filesystem, no config.
     */
    public static function forApp(): self
    {
        $rows = [];
        /** @var array<string, true> $seen */
        $seen = [];

        $unresolvableBindings = [];

        foreach (['defaults', 'operator'] as $realm) {
            $binding = config("beam.client.sources.{$realm}");
            if (! is_string($binding) || $binding === '') {
                continue;
            }
            if (! class_exists($binding)) {
                // The signal an outage taught us never to swallow (particle-doctrine-followups #04):
                // this binding is KNOWN unresolvable — a stale FQN in config, two hops from any
                // binding call site — so it becomes a finding instead of a silent continue.
                $unresolvableBindings[] = ['key' => "beam.client.sources.{$realm}", 'class' => $binding];

                continue;
            }
            $source = app($binding);
            if (! $source instanceof RouteManifestSource) {
                continue;
            }

            foreach ($source->toArray() as $name => $entry) {
                if (self::declaresShape($entry) || isset($seen[$name])) {
                    continue;
                }
                $seen[$name] = true;

                $route = Route::getRoutes()->getByName($name);
                $controllerFile = null;
                $controllerClass = null;
                $actionMethod = null;

                if ($route !== null) {
                    $action = $route->getActionName();
                    if (is_string($action) && str_contains($action, '@')) {
                        [$controllerClass, $actionMethod] = explode('@', $action, 2);
                        if (class_exists($controllerClass)) {
                            $reflection = new \ReflectionClass($controllerClass);
                            $file = $reflection->getFileName();
                            $controllerFile = $file === false ? null : $file;
                        } else {
                            $controllerClass = null;
                        }
                    }
                }

                $rows[] = [
                    'routeName' => $name,
                    'uri' => $entry['path'],
                    'controllerFile' => $controllerFile,
                    'controllerClass' => $controllerClass,
                    'actionMethod' => $actionMethod,
                ];
            }
        }

        return new self($rows, self::defaultRouteFiles(), $unresolvableBindings);
    }

    /**
     * Whether one {@see RouteManifestSource} entry already declares its wire shape — at EITHER of the
     * manifest's two shape slots, not just `returns`.
     *
     * A `streams` map is the declaration site a streaming route has; there is no `returns` it could
     * legally carry, because an SSE endpoint emits several typed payloads under distinct event names
     * rather than one body. Reading only `returns` therefore turns every correctly-declared stream into
     * a coverage gap, and the tiering then rates some of them *above* the genuinely undeclared ones
     * (a stream handler that constructs its event DTOs inline scores `medium`).
     *
     * Kept as a static predicate rather than inlined so it is unit-testable without a router, a
     * manifest source, or an app boot — the same reason {@see forApp()} is kept off the constructor.
     *
     * @param  array<string, mixed>  $entry  one route-manifest entry
     */
    public static function declaresShape(array $entry): bool
    {
        if (isset($entry['returns'])) {
            return true;
        }

        return isset($entry['streams']) && $entry['streams'] !== [] && $entry['streams'] !== null;
    }

    /**
     * One Fail per config-sourced realm binding that names a class which does not exist — the config
     * key and the unresolvable class, verbatim. Emitted ahead of the coverage findings on both the
     * doctor channel ({@see run()}) and the sweep channel ({@see suggestOperations()}).
     *
     * @return list<Finding>
     */
    protected function unresolvableBindingFindings(): array
    {
        return array_map(
            fn (array $binding) => Finding::fail(self::CHECK, sprintf(
                "config('%s') names '%s', which does not resolve to a real class — the realm's manifest source cannot be bound, so every route it would enumerate is invisible to this audit.",
                $binding['key'],
                $binding['class'],
            )),
            $this->unresolvableBindings,
        );
    }

    /**
     * The route files searched to locate a literal registration statement for the apply half, each
     * keyed to the BASE name prefix its own `Route::group(...)` mount wraps it with — verified
     * directly against `app/Providers/TenancyServiceProvider::mapRoutes()` (`routes/tenant.php`, no
     * name prefix) and `app/Providers/RouteServiceProvider::mapRoutes()` (`routes/api.php` →
     * `'api.v1.'`, `routes/admin.php` → `'api.admin.'`) — NOT assumed. Getting this wrong silently
     * makes every route in the mis-based file unresolvable (the exact bug this fixed: `api.php`'s
     * `broker.` group resolves to `api.v1.broker.tenants.store`, not `broker.tenants.store`, and a
     * flat `''`-prefix search for every file found zero of them). A route whose literal `->name(...)`
     * call isn't found in any of these (e.g. registered via a shared macro like
     * `Route::recordVersions(...)`, per ticket 25's comments) simply won't get an
     * {@see OperationSuggestion} — advisory-only, correctly.
     *
     * @return array<string, string>
     */
    protected static function defaultRouteFiles(): array
    {
        $candidates = [
            base_path('routes/tenant.php') => '',
            base_path('routes/api.php') => 'api.v1.',
            base_path('routes/admin.php') => 'api.admin.',
        ];

        return array_filter($candidates, fn ($file) => is_file($file), ARRAY_FILTER_USE_KEY);
    }

    /** @return list<Finding> */
    public function run(): array
    {
        return [
            ...$this->unresolvableBindingFindings(),
            ...$this->suggestFor($this->classify($this->routes)),
        ];
    }

    /**
     * The fix half (ticket 26, supersedes decision 1 for high/medium tiers only — see ticket 26's
     * `## Decision`). Reuses the estate's generic `literal-rewrite`/{@see LiteralRewriteOperation}
     * mechanism (the same one {@see SdkEndpointDriftAudit} and ticket 08's {@see SdkHookMigrationAudit}
     * already dogfood) rather than a bespoke Operation class — the anchor is the route's exact,
     * AST-located registration statement text (via {@see locateRouteStatement()}), so the splice is
     * byte-precise and format-preserving without hand-rolling a new writer.
     *
     * Low tier, ambiguous multi-DTO candidates, and any candidate whose registration statement can't
     * be uniquely located (macro-registered routes, e.g. `Route::recordVersions(...)`) get a
     * {@see FixableFinding} with NO suggestion — a hard bail rule, not a per-run judgment call,
     * mirroring {@see SdkEndpointDriftAudit}'s own "zero/ambiguous match → advisory-only" precedent.
     *
     * @return list<FixableFinding>
     */
    public function suggestOperations(): array
    {
        $detailed = $this->classifyDetailed($this->routes);
        $findings = array_map(
            fn (Finding $finding) => new FixableFinding($finding),
            $this->unresolvableBindingFindings(),
        );

        foreach ($detailed as $row) {
            if ($row['tier'] === null) {
                continue;
            }

            $finding = Finding::warn(self::CHECK, sprintf(
                '%s [%s] lacks ->returns() — candidate tier: %s.',
                $row['routeName'] ?? $row['uri'],
                $row['uri'],
                $row['tier'],
            ));

            $suggestion = $this->suggestionFor($row);
            $findings[] = new FixableFinding($finding, $suggestion);
        }

        return $findings;
    }

    /**
     * Build the literal-rewrite suggestion for one classified row, or null (advisory-only) when it
     * fails ANY of the hard bail rules: not high/medium, an ambiguous multi-DTO body, or no uniquely
     * locatable registration statement.
     *
     * ⚠️ **The DTO name is resolved against the controller's own imports before it is written, and an
     * unresolvable one bails to advisory.** `$row['dtoClass']` is the name **as written in the
     * controller body** — almost always a SHORT name, resolved there by that file's `use` statements —
     * so prefixing a backslash to it produces a GLOBAL-namespace FQN that does not exist. Measured
     * 2026-08-29 at `~/Herd/splicewire-app`: both suggestions this audit emitted named
     * `\BillingRedirectData` and `\ThreadData`, neither of which `class_exists()`, against real classes
     * at `Splicewire\Beam\Commerce\Data\BillingRedirectData` and `Splicewire\Tower\Data\ThreadData`.
     * `surgeon:*` writers preview by default so nothing auto-applied it, but the `-v` output is written
     * to be copied.
     *
     * `ThreadData` also shows why existence is checked and not just resolution: **two** classes carry
     * that short name (`laravel-beam-threads` and `tower`), and the existing `ambiguous` flag does not
     * see it — that flag counts DTOs constructed in one method body, a different question from one short
     * name owned by two packages.
     *
     * @param  array{routeName: ?string, uri: string, tier: ?string, dtoClass: ?string, many: bool, ambiguous: bool, controllerFile?: ?string}  $row
     */
    protected function suggestionFor(array $row): ?OperationSuggestion
    {
        if (! in_array($row['tier'], [self::TIER_HIGH, self::TIER_MEDIUM], true)) {
            return null;
        }
        if ($row['ambiguous'] || $row['dtoClass'] === null || $row['routeName'] === null) {
            return null;
        }

        $dto = $this->resolveDtoClass($row['dtoClass'], $row['controllerFile'] ?? null);
        if ($dto === null) {
            return null;
        }

        $located = $this->locateRouteStatement($row['routeName']);
        if ($located === null) {
            return null;
        }

        $call = $row['many']
            ? sprintf('->returns(\\%s::class, many: true)', $dto)
            : sprintf('->returns(\\%s::class)', $dto);

        // Insert immediately before the statement's terminating `;` — matches the convention every
        // hand-applied step-1 edit already follows (append to the END of the chain, not after `->name()`
        // specifically, since trailing calls like `->whereUuid(...)` may follow it).
        $old = $located['text'];
        $new = substr($old, 0, -1).$call.';';

        return new OperationSuggestion(
            kind: 'literal-rewrite',
            summary: "Add ->returns() to {$row['routeName']}",
            payload: ['file' => $located['file'], 'old' => $old, 'new' => $new],
        );
    }

    /**
     * Resolve a DTO name as written in a controller body to a fully-qualified class that EXISTS, or null
     * to bail the suggestion to advisory.
     *
     * Three steps, most specific first, and every one of them can decline:
     *
     *  1. a name already containing a separator is taken as fully qualified;
     *  2. otherwise the controller file's `use` statements are read — both `use A\B\Short;` and
     *     `use A\B\Other as Short;`, since an aliased import is exactly how a short name gets reused;
     *  3. otherwise the controller's own namespace is tried, which is where a same-namespace DTO sits.
     *
     * Whatever comes out must satisfy `class_exists()`. Declining is the right outcome rather than a
     * best guess: this audit's whole posture is that adding `->returns()` is an API-contract commitment
     * a human makes, and an advisory finding still tells them where to look. A wrong FQN in a
     * copy-pasteable rewrite is worse than no rewrite.
     */
    protected function resolveDtoClass(string $name, ?string $controllerFile): ?string
    {
        $name = ltrim($name, '\\');

        if (str_contains($name, '\\')) {
            return class_exists($name) ? $name : null;
        }

        if ($controllerFile === null || ! is_file($controllerFile)) {
            return null;
        }

        $source = (string) @file_get_contents($controllerFile);

        // `use A\B\Short;` and `use A\B\Other as Short;` — grouped and function/const imports are not
        // matched, and that is fine: a DTO is neither.
        if (preg_match_all('/^\s*use\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?\s*;/m', $source, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $alias = $match[2] ?? '';
                $short = $alias !== '' ? $alias : substr($match[1], (int) strrpos($match[1], '\\') + 1);

                if ($short === $name) {
                    return class_exists($match[1]) ? $match[1] : null;
                }
            }
        }

        if (preg_match('/^\s*namespace\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*;/m', $source, $ns)) {
            $sameNamespace = $ns[1].'\\'.$name;

            if (class_exists($sameNamespace)) {
                return $sameNamespace;
            }
        }

        return null;
    }

    /**
     * The pure core — no router, no filesystem, no AST. Given rows already tiered by {@see classify()}
     * (`tier: null` meaning confidently excluded/filtered), produce one {@see Finding} per surfaced
     * candidate. Directly unit testable with plain arrays — no disk, no parser.
     *
     * @param  list<array{routeName: ?string, uri: string, tier: ?string}>  $rows
     * @return list<Finding>
     */
    public function suggestFor(array $rows): array
    {
        $findings = [];

        foreach ($rows as $row) {
            if ($row['tier'] === null) {
                // Confidently unfit (filtered) or unresolvable — see classify() for which.
                continue;
            }

            $findings[] = Finding::warn(self::CHECK, sprintf(
                '%s [%s] lacks ->returns() — candidate tier: %s.',
                $row['routeName'] ?? $row['uri'],
                $row['uri'],
                $row['tier'],
            ));
        }

        return $findings;
    }

    /**
     * The AST-parsing half — resolves each route's controller action and tiers it. Isolated from
     * {@see suggestFor()} so the finding-shaping logic stays pure/testable while this, the genuinely
     * file-touching half, is exercised separately (via {@see run()} or directly).
     *
     * An unresolvable action (no controller file, action not found, parse failure) tiers as
     * {@see self::TIER_LOW} — genuinely unknown, not silently dropped, distinct from a confident
     * filter-idiom exclusion (`null`).
     *
     * @param  list<array{routeName: ?string, uri: string, controllerFile: ?string, controllerClass: ?string, actionMethod: ?string}>  $routes
     * @return list<array{routeName: ?string, uri: string, tier: ?string}>
     */
    public function classify(array $routes): array
    {
        $rows = [];
        /** @var array<string, array<string, ClassMethod>|null> $parsedByFile */
        $parsedByFile = [];

        foreach ($routes as $row) {
            $methods = $this->methodsFor($row['controllerFile'], $parsedByFile);
            $method = $methods !== null && $row['actionMethod'] !== null ? ($methods[$row['actionMethod']] ?? null) : null;

            $tier = match (true) {
                $method === null => self::TIER_LOW,
                $this->hasFilterIdiom($method) => null,
                $this->hasResponseFromData($method) => self::TIER_HIGH,
                $this->hasDtoConstruction($method) => self::TIER_MEDIUM,
                $row['controllerClass'] !== null => $this->classifyViaCallChain($method, $row['controllerClass'], $parsedByFile),
                default => self::TIER_LOW,
            };

            $rows[] = ['routeName' => $row['routeName'], 'uri' => $row['uri'], 'tier' => $tier];
        }

        return $rows;
    }

    /**
     * Detail-returning counterpart to {@see classify()} (ticket 26) — same tiering, in the same
     * branch order, kept as a SEPARATE pass rather than folded into `classify()` so the latter's
     * well-tested plain output stays byte-for-byte unchanged. Additionally resolves the DTO class,
     * "many" signal, and multi-class ambiguity flag {@see suggestOperations()} needs to build a fix.
     *
     * @param  list<array{routeName: ?string, uri: string, controllerFile: ?string, controllerClass: ?string, actionMethod: ?string}>  $routes
     * @return list<array{routeName: ?string, uri: string, tier: ?string, dtoClass: ?string, many: bool, ambiguous: bool, controllerFile: ?string}>
     */
    public function classifyDetailed(array $routes): array
    {
        $rows = [];
        /** @var array<string, array<string, ClassMethod>|null> $parsedByFile */
        $parsedByFile = [];

        foreach ($routes as $row) {
            $methods = $this->methodsFor($row['controllerFile'], $parsedByFile);
            $method = $methods !== null && $row['actionMethod'] !== null ? ($methods[$row['actionMethod']] ?? null) : null;

            $dtoClass = null;
            $many = false;
            $ambiguous = false;

            if ($method === null) {
                $tier = self::TIER_LOW;
            } elseif ($this->hasFilterIdiom($method)) {
                $tier = null;
            } elseif (($rfd = $this->responseFromDataClass($method)) !== null) {
                $tier = self::TIER_HIGH;
                $dtoClass = $rfd;
                $many = $this->manySignalPresent($method, $this->returnExpressions($method));
            } elseif (($dto = $this->dtoConstructionSignal($method)) !== null) {
                $tier = self::TIER_MEDIUM;
                $dtoClass = $dto['classes'][0];
                $many = $dto['many'];
                $ambiguous = count($dto['classes']) > 1;
            } elseif ($row['controllerClass'] !== null) {
                [$tier, $dtoClass, $many, $ambiguous] = $this->classifyViaCallChainDetailed($method, $row['controllerClass'], $parsedByFile);
            } else {
                $tier = self::TIER_LOW;
            }

            $rows[] = [
                'routeName' => $row['routeName'],
                'uri' => $row['uri'],
                'tier' => $tier,
                'dtoClass' => $dtoClass,
                'many' => $many,
                'ambiguous' => $ambiguous,
                // Carried so the fix half can resolve `$dtoClass` — which is a SHORT name as written in
                // the controller — against that controller's own imports. See suggestionFor().
                'controllerFile' => $row['controllerFile'],
            ];
        }

        return $rows;
    }

    /**
     * Detail-returning counterpart to {@see classifyViaCallChain()} — identical one-level-deep
     * call-chain following, additionally resolving dtoClass/many/ambiguous.
     *
     * @param  array<string, array<string, ClassMethod>|null>  $parsedByFile
     * @return array{0: ?string, 1: ?string, 2: bool, 3: bool}
     */
    protected function classifyViaCallChainDetailed(ClassMethod $method, string $controllerClass, array &$parsedByFile): array
    {
        $finder = new NodeFinder;
        /** @var MethodCall[] $calls */
        $calls = $finder->findInstanceOf((array) $method->stmts, MethodCall::class);

        if ($calls === []) {
            return [self::TIER_LOW, null, false, false];
        }

        $propertyTypes = $this->constructorPropertyTypes($controllerClass);
        $sawFilterIdiom = false;

        foreach ($calls as $call) {
            if (! $call->name instanceof Node\Identifier) {
                continue;
            }

            $targetClass = $this->resolveCallTargetClass($call, $controllerClass, $propertyTypes);
            if ($targetClass === null) {
                continue;
            }

            $calleeMethod = $this->resolveMethod($targetClass, $call->name->toString(), $parsedByFile);
            if ($calleeMethod === null) {
                continue;
            }

            if ($this->hasFilterIdiom($calleeMethod)) {
                $sawFilterIdiom = true;

                continue;
            }
            if (($rfd = $this->responseFromDataClass($calleeMethod)) !== null) {
                return [self::TIER_HIGH, $rfd, $this->manySignalPresent($calleeMethod, $this->returnExpressions($calleeMethod)), false];
            }
            if (($dto = $this->dtoConstructionSignal($calleeMethod)) !== null) {
                return [self::TIER_MEDIUM, $dto['classes'][0], $dto['many'], count($dto['classes']) > 1];
            }
        }

        return [$sawFilterIdiom ? null : self::TIER_LOW, null, false, false];
    }

    /**
     * Locate the literal registration statement for a full route name across {@see $this->routeFiles}
     * (ticket 26) — tracking `Route::name('prefix.')->group(...)`-style nesting so an in-group short
     * suffix (e.g. `->name('update')` inside a `thread.` group) resolves against its real full name,
     * not a raw substring search (which would be ambiguous — several groups reuse the same suffix, a
     * real case found in this route table). Returns null when zero files/statements resolve the name,
     * more than one does (genuinely ambiguous — never guess), or the located text already contains
     * `->returns(` (defensive; {@see forApp()} already filters these out).
     *
     * @return array{file: string, text: string}|null
     */
    protected function locateRouteStatement(string $routeName): ?array
    {
        $matches = [];

        foreach ($this->routeFiles as $file => $basePrefix) {
            $source = is_readable($file) ? (string) file_get_contents($file) : null;
            if ($source === null) {
                continue;
            }

            $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($source);
            if ($ast === null) {
                continue;
            }

            $map = $this->collectRouteStatements($ast, $basePrefix);
            if (! isset($map[$routeName])) {
                continue;
            }

            [$start, $end] = $map[$routeName];
            $text = substr($source, $start, $end - $start + 1);
            if (! str_contains($text, '->returns(')) {
                $matches[] = ['file' => $file, 'text' => $text];
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * Recursively walk a statement list, tracking the accumulated `->name('prefix.')` value through
     * any `->group(Closure)` wrapper, collecting `{fullRouteName: [startPos, endPos]}` for every leaf
     * registration statement carrying a literal `->name('...')` call. A duplicate full name (should be
     * impossible — Laravel route names are unique — but defensive) is dropped from the map entirely
     * rather than picked arbitrarily, so {@see locateRouteStatement()} sees it as unresolved, not wrong.
     *
     * Array-config group syntax (`Route::group(['as' => 'x.'], function () {...})`) is NOT handled —
     * only the fluent `->name(...)->group(...)` shape this route table actually uses. A route
     * registered that way simply won't resolve here — safe fallback, advisory-only, not a crash.
     *
     * @param  list<Node\Stmt>  $stmts
     * @return array<string, array{0: int, 1: int}>
     */
    protected function collectRouteStatements(array $stmts, string $prefix): array
    {
        $map = [];

        foreach ($stmts as $stmt) {
            if (! $stmt instanceof Expression) {
                continue;
            }
            $expr = $stmt->expr;
            if (! ($expr instanceof MethodCall || $expr instanceof StaticCall)) {
                continue;
            }

            $tierMount = $this->tierRoutesMountArgs($expr);
            if ($tierMount !== null) {
                [$tierName, $tierClosure] = $tierMount;
                $nested = $this->collectRouteStatements((array) $tierClosure->stmts, $prefix.$tierName);
                foreach ($nested as $name => $span) {
                    if (isset($map[$name])) {
                        unset($map[$name]);

                        continue;
                    }
                    $map[$name] = $span;
                }

                continue;
            }

            $nameValue = $this->literalNameArg($expr);
            $groupClosure = $this->groupClosureArg($expr);

            if ($groupClosure !== null) {
                $nested = $this->collectRouteStatements((array) $groupClosure->stmts, $prefix.($nameValue ?? ''));
                foreach ($nested as $name => $span) {
                    if (isset($map[$name])) {
                        unset($map[$name]);

                        continue;
                    }
                    $map[$name] = $span;
                }

                continue;
            }

            if ($nameValue !== null) {
                $full = $prefix.$nameValue;
                if (isset($map[$full])) {
                    unset($map[$full]);

                    continue;
                }
                $map[$full] = [$stmt->getStartFilePos(), $stmt->getEndFilePos()];
            }
        }

        return $map;
    }

    /**
     * The string argument of a `->name('...')` call in an expression's OUTER method-call chain only —
     * walks down `->var` (the receiver), never descending into a `->group(Closure)` argument's body, so
     * a group's own `->name('prefix.')` is found without also matching a route named INSIDE the group.
     */
    protected function literalNameArg(Node $expr): ?string
    {
        $cur = $expr;
        while ($cur instanceof MethodCall) {
            if ($cur->name instanceof Node\Identifier && $cur->name->toString() === 'name') {
                $arg = $cur->args[0] ?? null;
                if ($arg instanceof Node\Arg && $arg->value instanceof Node\Scalar\String_) {
                    return $arg->value->value;
                }
            }
            $cur = $cur->var;
        }

        return null;
    }

    /**
     * `TierRoutes::mount($tierPrefix, $tierName, $routes, ...)` (`app/Routing/TierRoutes.php`,
     * ADR-0124) — a SECOND group-registration idiom this route table uses alongside the fluent
     * `Route::name(...)->group(...)` shape, wrapping a large slice of `routes/tenant.php` (the whole
     * `beam.workflows.*` surface and siblings). `$tierName` is the second positional string arg,
     * `$routes` the third positional closure arg — both required params, so position is reliable.
     *
     * @return array{0: string, 1: Closure}|null
     */
    protected function tierRoutesMountArgs(Node $expr): ?array
    {
        if (! ($expr instanceof StaticCall && $expr->class instanceof Node\Name)) {
            return null;
        }
        if ($this->shortName($expr->class->toString()) !== 'TierRoutes'
            || ! ($expr->name instanceof Node\Identifier)
            || $expr->name->toString() !== 'mount') {
            return null;
        }

        $nameArg = $expr->args[1] ?? null;
        $closureArg = $expr->args[2] ?? null;
        if (! ($nameArg instanceof Node\Arg && $nameArg->value instanceof Node\Scalar\String_)) {
            return null;
        }
        if (! ($closureArg instanceof Node\Arg && $closureArg->value instanceof Closure)) {
            return null;
        }

        return [$nameArg->value->value, $closureArg->value];
    }

    /** The `Closure` argument of a `->group(...)` call in an expression's OUTER chain only. */
    protected function groupClosureArg(Node $expr): ?Closure
    {
        $cur = $expr;
        while ($cur instanceof MethodCall) {
            if ($cur->name instanceof Node\Identifier && $cur->name->toString() === 'group') {
                foreach ($cur->args as $arg) {
                    if ($arg instanceof Node\Arg && $arg->value instanceof Closure) {
                        return $arg->value;
                    }
                }
            }
            $cur = $cur->var;
        }

        return null;
    }

    /**
     * One level of call-chain following: when the action body itself carries no DTO/filter signal,
     * look at every `$this->method()` or `$this->property->method()` reachable from the action —
     * the latter resolved via {@see constructorPropertyTypes()} — and re-run the same three checks
     * against ONE such callee's body. Does not recurse into what the callee itself calls.
     *
     * Returns the same vocabulary {@see classify()} already uses for `$tier`: a resolved tier, `null`
     * for confidently-filtered (a callee shows the filter idiom), or {@see self::TIER_LOW} when
     * nothing in the chain resolves — genuinely unknown, not silently dropped.
     *
     * @param  array<string, array<string, ClassMethod>|null>  $parsedByFile
     */
    protected function classifyViaCallChain(ClassMethod $method, string $controllerClass, array &$parsedByFile): ?string
    {
        $finder = new NodeFinder;
        /** @var MethodCall[] $calls */
        $calls = $finder->findInstanceOf((array) $method->stmts, MethodCall::class);

        if ($calls === []) {
            return self::TIER_LOW;
        }

        $propertyTypes = $this->constructorPropertyTypes($controllerClass);
        $sawFilterIdiom = false;

        foreach ($calls as $call) {
            if (! $call->name instanceof Node\Identifier) {
                continue;
            }

            $targetClass = $this->resolveCallTargetClass($call, $controllerClass, $propertyTypes);
            if ($targetClass === null) {
                continue;
            }

            $calleeMethod = $this->resolveMethod($targetClass, $call->name->toString(), $parsedByFile);
            if ($calleeMethod === null) {
                continue;
            }

            if ($this->hasFilterIdiom($calleeMethod)) {
                $sawFilterIdiom = true;

                continue;
            }
            if ($this->hasResponseFromData($calleeMethod)) {
                return self::TIER_HIGH;
            }
            if ($this->hasDtoConstruction($calleeMethod)) {
                return self::TIER_MEDIUM;
            }
        }

        return $sawFilterIdiom ? null : self::TIER_LOW;
    }

    /**
     * Resolves a `MethodCall`'s receiver to a class name — `$this->method()` (the controller itself)
     * or `$this->property->method()` where `property` is a constructor-injected dependency. Any other
     * receiver shape (a local variable, a chained call, a static fetch) resolves to null: out of
     * scope for a bounded one-level follow.
     *
     * @param  array<string, string>  $propertyTypes
     */
    protected function resolveCallTargetClass(MethodCall $call, string $controllerClass, array $propertyTypes): ?string
    {
        $var = $call->var;

        if ($var instanceof Node\Expr\Variable && $var->name === 'this') {
            return $controllerClass;
        }

        if ($var instanceof PropertyFetch
            && $var->var instanceof Node\Expr\Variable
            && $var->var->name === 'this'
            && $var->name instanceof Node\Identifier) {
            return $propertyTypes[$var->name->toString()] ?? null;
        }

        return null;
    }

    /**
     * Maps constructor-injected property names to their class-typed hints — covers both promoted
     * (`public function __construct(protected SomeService $service)`) and classic
     * (property name matching the constructor parameter name) injection styles, via reflection. The
     * controller class is already known-loadable at this point ({@see forApp()} requires
     * `class_exists()` to reach here).
     *
     * @return array<string, string>
     */
    protected function constructorPropertyTypes(string $controllerClass): array
    {
        if (! class_exists($controllerClass)) {
            return [];
        }

        $reflection = new \ReflectionClass($controllerClass);
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return [];
        }

        $types = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof \ReflectionNamedType && ! $type->isBuiltin()) {
                $types[$parameter->getName()] = $type->getName();
            }
        }

        return $types;
    }

    /**
     * Resolves `$targetClass::$methodName`'s AST node — via {@see \ReflectionMethod::getFileName()},
     * the file that physically declares the method, whether that's the class itself or a trait it
     * uses (PHP resolves trait-provided methods to the trait's own file here). Delegates to the same
     * per-file {@see methodsFor()} cache the direct path uses.
     *
     * @param  array<string, array<string, ClassMethod>|null>  $cache
     */
    protected function resolveMethod(string $targetClass, string $methodName, array &$cache): ?ClassMethod
    {
        if (! method_exists($targetClass, $methodName)) {
            return null;
        }

        try {
            $reflectionMethod = new \ReflectionMethod($targetClass, $methodName);
        } catch (\ReflectionException) {
            return null;
        }

        $file = $reflectionMethod->getFileName();
        if ($file === false) {
            return null;
        }

        $methods = $this->methodsFor($file, $cache);

        return $methods[$methodName] ?? null;
    }

    /**
     * Every method of a controller file, keyed by name, parsed once and memoized across the whole sweep
     * (a controller mounts many routes) — the caching a per-route parse would otherwise repeat needlessly.
     *
     * @param  array<string, array<string, ClassMethod>|null>  $cache
     * @return array<string, ClassMethod>|null
     */
    protected function methodsFor(?string $file, array &$cache): ?array
    {
        if ($file === null) {
            return null;
        }
        if (array_key_exists($file, $cache)) {
            return $cache[$file];
        }

        $source = is_readable($file) ? (string) file_get_contents($file) : null;
        if ($source === null) {
            return $cache[$file] = null;
        }

        $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($source);
        $classNode = $ast === null ? null : (new NodeFinder)->findFirstInstanceOf($ast, ClassLike::class);
        if ($classNode === null) {
            return $cache[$file] = null;
        }

        $methods = [];
        foreach ($classNode->getMethods() as $method) {
            $methods[$method->name->toString()] = $method;
        }

        return $cache[$file] = $methods;
    }

    /** A `#[ResponseFromData(...)]` attribute on the method itself. */
    protected function hasResponseFromData(ClassMethod $method): bool
    {
        return $this->responseFromDataClass($method) !== null;
    }

    /**
     * The `#[ResponseFromData(DataClass::class)]` attribute's own declared DTO class (ticket 26) — a
     * declared source, not an inference, so this is the one signal {@see suggestOperations()} treats
     * as high-confidence enough to auto-apply.
     */
    protected function responseFromDataClass(ClassMethod $method): ?string
    {
        foreach ($method->attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                if ($this->shortName($attr->name->toString()) !== $this->shortName(ResponseFromData::class)) {
                    continue;
                }
                $first = $attr->args[0] ?? null;
                if ($first instanceof Node\Arg
                    && $first->value instanceof Node\Expr\ClassConstFetch
                    && $first->value->class instanceof Node\Name) {
                    return $first->value->class->toString();
                }
            }
        }

        return null;
    }

    /**
     * `SomeData::from(...)` / `SomeData::fromModel(...)` / `new SomeData(...)` anywhere in the body —
     * the `*Data` suffix matches this app's DTO naming convention (no PHP return-type signal exists;
     * confirmed uniformly generic `: ResponseBody`/`: Responsable` across sampled controllers).
     */
    protected function hasDtoConstruction(ClassMethod $method): bool
    {
        return $this->dtoConstructionSignal($method) !== null;
    }

    /**
     * Detail-returning counterpart to {@see hasDtoConstruction()} (ticket 26): every distinct `*Data`
     * class constructed WITHIN A `return` STATEMENT's expression — deliberately NOT the whole method
     * body. A real bug caught live during ticket 26's own verification: `TenantController::store()`
     * constructs `EntitlementDenialData` only inside `throw new EntitlementDeniedException(new
     * EntitlementDenialData(...))` on an error path, which a whole-body search wrongly picked up as
     * the route's response DTO. Scoping to return-statement expressions only eliminates that whole
     * false-positive class (exception payloads, logging, validation side-constructions) rather than
     * special-casing `throw`. Multiple distinct classes across return paths is exactly the "genuinely
     * ambiguous" case {@see suggestionFor()} bails on rather than guesses. `::collect(...)`/
     * `::collection(...)` calls count as construction too (a list-shaped DTO source).
     *
     * @return array{classes: list<string>, many: bool}|null
     */
    protected function dtoConstructionSignal(ClassMethod $method): ?array
    {
        $returnExprs = $this->returnExpressions($method);
        if ($returnExprs === []) {
            return null;
        }

        $classes = [];
        foreach ($returnExprs as $expr) {
            foreach ($this->outermostDtoConstructions($expr) as $class) {
                $classes[] = $class;
            }
        }

        if ($classes === []) {
            return null;
        }

        return ['classes' => array_values(array_unique($classes)), 'many' => $this->manySignalPresent($method, $returnExprs)];
    }

    /**
     * Every `*Data` construction (`::from()`/`::fromModel()`/`::collect()`/`::collection()` static
     * call, or `new SomeData(...)`) reachable from `$expr`, WITHOUT descending into a match's own
     * arguments once found — a `*Data` construction nested inside another `*Data` construction's
     * constructor arguments is a FIELD VALUE of the outer DTO, not a second candidate response type.
     * Fixes a real false-positive caught live (ticket 25): `WorkflowCatalogData::from(['guards' =>
     * GuardCatalogEntryData::collect($guards)])` was miscounted as two competing DTOs before this —
     * `NodeFinder::findInstanceOf()` (used previously) can't prune traversal once a match is found,
     * only filter inclusion, so it kept walking into the matched call's own arguments.
     *
     * @return list<string>
     */
    protected function outermostDtoConstructions(Node\Expr $expr): array
    {
        $visitor = new class extends NodeVisitorAbstract
        {
            /** @var list<string> */
            public array $found = [];

            public function enterNode(Node $node)
            {
                $class = null;

                if ($node instanceof StaticCall
                    && $node->class instanceof Node\Name
                    && str_ends_with($node->class->toString(), 'Data')
                    && $node->name instanceof Node\Identifier
                    && in_array($node->name->toString(), ['from', 'fromModel', 'collect', 'collection'], true)) {
                    $class = $node->class->toString();
                } elseif ($node instanceof New_
                    && $node->class instanceof Node\Name
                    && str_ends_with($node->class->toString(), 'Data')) {
                    $class = $node->class->toString();
                }

                if ($class !== null) {
                    $this->found[] = $class;

                    return NodeTraverser::DONT_TRAVERSE_CHILDREN;
                }

                return null;
            }
        };

        $traverser = new NodeTraverser;
        $traverser->addVisitor($visitor);
        $traverser->traverse([$expr]);

        return $visitor->found;
    }

    /**
     * Every `return` statement's expression in the method body (top-level and nested in if/else/match
     * branches — a method may have multiple return paths). Excludes bare `return;` (no expr) and,
     * deliberately, does NOT descend into nested closures — a closure's own `return` belongs to ITS
     * body, not the action's response. `NodeFinder` can't prune traversal (its filter only decides
     * inclusion, not descent), so this uses a real {@see NodeVisitorAbstract} returning
     * `NodeTraverser::DONT_TRAVERSE_CHILDREN` at each `Closure`/`ArrowFunction` boundary.
     *
     * @return list<Node\Expr>
     */
    protected function returnExpressions(ClassMethod $method): array
    {
        $visitor = new class extends NodeVisitorAbstract
        {
            /** @var list<Node\Expr> */
            public array $found = [];

            public function enterNode(Node $node)
            {
                if ($node instanceof Closure || $node instanceof Node\Expr\ArrowFunction) {
                    return NodeTraverser::DONT_TRAVERSE_CHILDREN;
                }
                if ($node instanceof Node\Stmt\Return_ && $node->expr !== null) {
                    $this->found[] = $node->expr;
                }

                return null;
            }
        };

        $traverser = new NodeTraverser;
        $traverser->addVisitor($visitor);
        $traverser->traverse((array) $method->stmts);

        return $visitor->found;
    }

    /**
     * Whether any OUTERMOST `*Data` construction across `$returnExprs` is ITSELF a `::collect()`/
     * `::collection()` call — bounded the same way as {@see outermostDtoConstructions()}, for the
     * same reason: a `::collect()` call nested inside another `*Data` construction's own arguments
     * is a list-shaped FIELD of a single-object response, not evidence the whole response is a list.
     * Real false-positive caught live (ticket 25): `WorkflowCatalogData::from(['guards' =>
     * GuardCatalogEntryData::collect(...)])` is a single `WorkflowCatalogData` object with
     * list-shaped fields — the previous unbounded `NodeFinder` search over ALL `StaticCall`s in the
     * return expression flagged `many: true` incorrectly.
     *
     * @param  list<Node\Expr>  $returnExprs
     */
    protected function outermostConstructionIsCollectCall(array $returnExprs): bool
    {
        foreach ($returnExprs as $expr) {
            $visitor = new class extends NodeVisitorAbstract
            {
                public bool $isCollect = false;

                public function enterNode(Node $node)
                {
                    $isData = null;

                    if ($node instanceof StaticCall
                        && $node->class instanceof Node\Name
                        && str_ends_with($node->class->toString(), 'Data')
                        && $node->name instanceof Node\Identifier
                        && in_array($node->name->toString(), ['from', 'fromModel', 'collect', 'collection'], true)) {
                        $isData = in_array($node->name->toString(), ['collect', 'collection'], true);
                    } elseif ($node instanceof New_
                        && $node->class instanceof Node\Name
                        && str_ends_with($node->class->toString(), 'Data')) {
                        $isData = false;
                    }

                    if ($isData !== null) {
                        if ($isData) {
                            $this->isCollect = true;
                        }

                        return NodeTraverser::DONT_TRAVERSE_CHILDREN;
                    }

                    return null;
                }
            };

            $traverser = new NodeTraverser;
            $traverser->addVisitor($visitor);
            $traverser->traverse([$expr]);

            if ($visitor->isCollect) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the body's DTO source is list-shaped, scoped to the SAME return-expression set
     * {@see dtoConstructionSignal()} resolved (the same false-positive-avoidance reasoning applies —
     * a `->map()`/`->through()` used to build an unrelated array elsewhere in the method must not
     * count). A `::collect(...)`/`::collection(...)` call within a return expression, OR a DTO
     * construction (`::from(...)`, `new SomeData(...)`) nested inside a closure passed to
     * `->map()`/`->through()` within one — the two "build one DTO per item" idioms found during
     * ticket 25 step 1's manual apply pass (the naive action-name heuristic it started with misfired
     * on both `dashboard.index`, a single object, and `statuses.index`, which uses `->through()` not
     * `->map()`).
     *
     * @param  list<Node\Expr>  $returnExprs
     */
    protected function manySignalPresent(ClassMethod $method, array $returnExprs): bool
    {
        if ($returnExprs === []) {
            return false;
        }

        if ($this->outermostConstructionIsCollectCall($returnExprs)) {
            return true;
        }

        // Parent links must be connected on the WHOLE method body (a return expr's ancestors live
        // above it in the full tree), then we only walk up from nodes found WITHIN the return exprs.
        $traverser = new NodeTraverser;
        $traverser->addVisitor(new ParentConnectingVisitor);
        $traverser->traverse((array) $method->stmts);

        $finder = new NodeFinder;
        $constructions = array_merge(
            $finder->findInstanceOf($returnExprs, StaticCall::class),
            $finder->findInstanceOf($returnExprs, New_::class),
        );
        foreach ($constructions as $node) {
            if ($this->hasAncestorMapOrThrough($node)) {
                return true;
            }
        }

        return false;
    }

    private function hasAncestorMapOrThrough(Node $node): bool
    {
        $cur = $node->getAttribute('parent');
        while ($cur instanceof Node) {
            if ($cur instanceof MethodCall && $cur->name instanceof Node\Identifier && in_array($cur->name->toString(), ['map', 'through'], true)) {
                return true;
            }
            $cur = $cur->getAttribute('parent');
        }

        return false;
    }

    /** `DataFilter::query(...)` anywhere in the body — the cheap common-case query-filtering proxy. */
    protected function hasFilterIdiom(ClassMethod $method): bool
    {
        $finder = new NodeFinder;
        /** @var StaticCall[] $staticCalls */
        $staticCalls = $finder->findInstanceOf((array) $method->stmts, StaticCall::class);

        foreach ($staticCalls as $call) {
            if ($call->class instanceof Node\Name
                && $this->shortName($call->class->toString()) === 'DataFilter'
                && $call->name instanceof Node\Identifier
                && $call->name->toString() === 'query') {
                return true;
            }
        }

        return false;
    }

    protected function shortName(string $fqn): string
    {
        $pos = strrpos($fqn, '\\');

        return $pos === false ? $fqn : substr($fqn, $pos + 1);
    }
}
