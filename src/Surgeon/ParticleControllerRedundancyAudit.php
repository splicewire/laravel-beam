<?php

namespace Splicewire\Beam\Surgeon;

use Illuminate\Support\Facades\Route;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Rushing\Surgeon\Operation\FixableFinding;
use Rushing\Surgeon\Operation\OperationSuggestion;
use Rushing\Surgeon\Operation\SuggestsOperations;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * The particle-controller REDUNDANCY audit (refactor-tooling ticket 16, from the hunt-02 "controller vs
 * particle" for-instance). Beam owns the concept of a *proper* particle controller (ticket 07, decision 3:
 * the concept-owner rule), so beam — not surgeon — owns the audit that says "this bespoke controller shell
 * is a route-wiring style the `Route::particleResource()` macro already replaces."
 *
 * ## The mechanical invariant (all three, per controller)
 * A controller is a redundant CRUD shell when it:
 *   1. `extends` the particle base ({@see ParticleController} / a host subclass of it — checked by walking
 *      the `extends` chain up the app's autoloader), AND
 *   2. serves a resource key that is **already registered** as a `ParticleResource` in the booted
 *      {@see ParticleResourceRegistry} (so the macro has a declaration to mount against), AND
 *   3. is **hand-wired** — its actions are mounted by bespoke `Route::get/post/…(…, [X::class, 'verb'])`
 *      calls while its peers (`tags`/`media`/`activity`) ride the controller-free `Route::particleResource()`
 *      macro against the SAME registry.
 * A controller failing any leg (not a particle subclass, resource unregistered, already macro-wired) is
 * NOT flagged — the redundancy is precisely "a thin shell the macro replaces for its peers."
 *
 * ## The fix split — the deterministic/agentic seam (ticket 16)
 * Whether the shell can COLLAPSE to the macro is decided by a deterministic AST check of every action
 * body:
 *   - **pure passthrough** — every action body is a single `return $this-><baseVerb>(…)` /
 *     `return parent::<baseVerb>(…)` against an inherited base verb ({@see BASE_VERBS}) with NO envelope
 *     delta ⇒ a **fixable** {@see OperationSuggestion} nominating the collapse to `Route::particleResource()`
 *     (the `particle-resource-collapse` kind a host's route-rewrite operation applies);
 *   - **has a real delta** — any action carries a genuine body (a `validate()` guard, a custom query, a
 *     different response envelope, or a non-CRUD sub-verb like `CircuitController`'s run lifecycle /
 *     `ContextScopeController`'s `embeddings`) ⇒ an **advisory** {@see OperationSuggestion::advisory()}
 *     naming what blocks the collapse. The human decides whether a partial collapse (CRUD → macro, keep a
 *     slim controller for the delta) is worth it.
 *
 * It lives in BEAM, not surgeon (ADR-0092 direction: beam depends DOWN on the foundation byte-splice
 * engine): surgeon must never know "a particle-controller shell duplicates a macro-mountable resource" (a
 * beam estate fact). Beam owns that POLICY and nominates a generic collapse operation via the
 * Finding→Operation bridge — exactly like the sibling {@see SdkEndpointDriftAudit} /
 * {@see SdkNameConventionAudit}.
 *
 * ## Honesty about what it can statically see
 * Two of the three legs are pure route-file/source facts (extends-chain, hand-wired-vs-macro), read
 * statically. The registered-resource leg is a RUNTIME fact — the registry is populated by boot-time
 * `#[ParticleResource]` discovery, not knowable from a source scan — so the default wiring reads the booted
 * {@see ParticleResourceRegistry} in host context ({@see forRoutes()}). The pure core {@see suggestFor()}
 * takes all four facts as inputs so it is unit-testable with in-memory fixtures (no registry, no route
 * table, no DB), mirroring the sibling audits.
 */
class ParticleControllerRedundancyAudit implements DoctorAudit, SuggestsOperations
{
    public const CHECK = 'particle.controller-redundant';

    /**
     * The inherited base verbs a redundant shell's actions passthrough to. `store`/`update`/`destroy`/
     * `index`/`show` are the REST verbs the macro mounts; `createParticle` is the base's create body an
     * extending controller rides to keep a legacy `200` (still a pure passthrough — same pipeline, just the
     * envelope flag). A body calling anything else is a real delta.
     */
    public const BASE_VERBS = ['index', 'show', 'store', 'update', 'destroy', 'createParticle'];

    /**
     * Actions that DON'T count as CRUD shell surface: the resource-binding override (not an action) and the
     * base internals. A controller whose ONLY methods are these + pure passthroughs is fully collapsible.
     */
    public const NON_ACTION_METHODS = ['particleResource', '__construct'];

    public function __construct(
        protected string $controllersDir,
        protected string $routesDir,
        protected ?ParticleResourceRegistry $registry = null,
    ) {}

    /**
     * The default host-scoped wiring: scan the app's `app/Http/Controllers`, parse the app's `routes/`
     * files for the hand-wired-vs-macro split, and read the booted {@see ParticleResourceRegistry} for the
     * registered-resource leg. Kept off the constructor so the class is pure-unit testable via
     * {@see suggestFor()} — no registry, no route table, no DB.
     */
    public static function forRoutes(
        ?string $controllersDir = null,
        ?string $routesDir = null,
        ?ParticleResourceRegistry $registry = null,
    ): self {
        $controllersDir ??= base_path('app/Http/Controllers');
        $routesDir ??= base_path('routes');
        $registry ??= app(ParticleResourceRegistry::class);

        return new self($controllersDir, $routesDir, $registry);
    }

    /**
     * The plain finding-half for beam's own doctor command (the {@see DoctorAudit} channel): the same
     * diagnosis as {@see suggestOperations()} with the fix-suggestion dropped, so one manifest
     * registration serves both `splicewire:beam:doctor` and surgeon's `surgeon:audit` sweep.
     *
     * @return list<Finding>
     */
    public function run(): array
    {
        return array_map(fn (FixableFinding $f) => $f->finding, $this->suggestOperations());
    }

    /**
     * @return list<FixableFinding>
     */
    public function suggestOperations(): array
    {
        [$handWiredKeys, $macroKeys] = $this->collectRouteWiring($this->routesDir);

        return $this->suggestFor(
            $this->collectControllers($this->controllersDir),
            $handWiredKeys,
            $macroKeys,
            $this->registeredKeys(),
        );
    }

    /**
     * The pure core — no disk, no route facade, no registry. Given the parsed controllers, the set of
     * resource keys mounted by HAND (bespoke `Route::…(…, [X::class, …])`), the set already ridden by the
     * `Route::particleResource()` macro, and the set of REGISTERED `ParticleResource` keys, produce one
     * {@see FixableFinding} per redundant CRUD shell.
     *
     * Directly unit testable: a pure-passthrough particle controller on a registered+hand-wired key →
     * fixable collapse; a with-delta one → advisory naming the blocker; a non-particle controller, an
     * unregistered resource, or an already-macro-wired key → no finding.
     *
     * @param  list<array{class: string, file: string, extendsParticleBase: bool, resourceKey: ?string, actions: array<string, string>}>  $controllers
     *                                                                                                                                                  `actions` maps a public action method name → its body shape: `'passthrough'` or `'delta'`.
     * @param  array<string, true>  $handWiredKeys  resource keys mounted by a bespoke controller route call
     * @param  array<string, true>  $macroKeys  resource keys already mounted via `Route::particleResource()`
     * @param  array<string, true>  $registeredKeys  registered `ParticleResource` keys
     * @return list<FixableFinding>
     */
    public function suggestFor(array $controllers, array $handWiredKeys, array $macroKeys, array $registeredKeys): array
    {
        $findings = [];

        foreach ($controllers as $controller) {
            $key = $controller['resourceKey'];

            // Leg 1: must extend the particle base. Leg 2: its key must be registered. Leg 3: it must be
            // hand-wired (a peer macro-mount of the SAME key is fine — the point is THIS class is bespoke).
            if (! $controller['extendsParticleBase'] || $key === null) {
                continue;
            }
            if (! isset($registeredKeys[$key]) || ! isset($handWiredKeys[$key])) {
                continue;
            }
            if (isset($macroKeys[$key])) {
                // Already macro-wired for its own key — not a redundant hand-wired shell.
                continue;
            }

            $deltas = array_keys(array_filter($controller['actions'], fn (string $shape) => $shape === 'delta'));
            $shortClass = $this->shortName($controller['class']);
            $peerRidesMacro = $macroKeys !== [];

            if ($deltas === []) {
                // Pure passthrough: every action is a base-verb call, no envelope delta — collapsible.
                $findings[] = new FixableFinding(
                    Finding::warn(self::CHECK, sprintf(
                        '%s is a pure-passthrough particle CRUD shell on the registered resource [%s]; its '.
                        'peers ride Route::particleResource(). It can collapse to the macro (no envelope delta).',
                        $shortClass,
                        $key,
                    )),
                    new OperationSuggestion(
                        kind: 'particle-resource-collapse',
                        summary: "Collapse {$shortClass} to Route::particleResource('{$key}', …) and delete the shell",
                        payload: [
                            'controller' => $controller['class'],
                            'file' => $controller['file'],
                            'resourceKey' => $key,
                            'actions' => array_keys($controller['actions']),
                        ],
                    ),
                );

                continue;
            }

            // A real delta blocks the full collapse — advisory nomination of a PARTIAL collapse (CRUD →
            // macro, keep a slim controller for the delta actions). Named, not applied: the human decides.
            $findings[] = new FixableFinding(
                Finding::warn(self::CHECK, sprintf(
                    '%s is a particle CRUD shell on the registered resource [%s]%s, but carries real deltas '.
                    '(%s) that block a full collapse to Route::particleResource().',
                    $shortClass,
                    $key,
                    $peerRidesMacro ? ' whose peers ride Route::particleResource()' : '',
                    implode(', ', $deltas),
                )),
                OperationSuggestion::advisory(
                    "Partial collapse of {$shortClass}: move CRUD to Route::particleResource('{$key}', …), keep a slim ".
                    'controller for '.implode(', ', $deltas).'.',
                    'splicewire/laravel-beam',
                ),
            );
        }

        return $findings;
    }

    /**
     * The registered `ParticleResource` keys, from the booted registry. The registry exposes `has($key)`
     * but not an enumeration, so read its private map reflectively — the honest way to answer the runtime
     * "is this key registered" leg without a fabricated static check. Empty when no registry is wired (the
     * pure-unit path).
     *
     * The bound instance may be a HOST SUBCLASS of the beam base (e.g. Tower's own registry), whose
     * `resources` map is a PRIVATE property on the beam parent — so walk the class hierarchy to the class
     * that DECLARES it rather than reflecting the leaf class (whose `hasProperty` can't see a parent's
     * private).
     *
     * @return array<string, true>
     */
    protected function registeredKeys(): array
    {
        if ($this->registry === null) {
            return [];
        }

        $ref = new \ReflectionClass($this->registry);
        while ($ref !== false && ! $ref->hasProperty('resources')) {
            $ref = $ref->getParentClass();
        }
        if ($ref === false) {
            return [];
        }

        $prop = $ref->getProperty('resources');
        $prop->setAccessible(true);
        /** @var array<string, mixed> $resources */
        $resources = $prop->getValue($this->registry);

        return array_fill_keys(array_keys($resources), true);
    }

    /**
     * Parse the route files for the two wiring styles, keyed by resource key:
     *   - HAND-WIRED — a `Route::get/post/put/patch/delete(uri, [SomeController::class, 'verb'])` whose uri's
     *     first path segment is the resource key (`agents`, `agents/{id}`, `context-scopes/{id}/embeddings`
     *     all key on their leading segment);
     *   - MACRO — a `Route::particleResource('key', …)` whose first string arg IS the key.
     *
     * @return array{0: array<string, true>, 1: array<string, true>} [handWiredKeys, macroKeys]
     */
    protected function collectRouteWiring(string $routesDir): array
    {
        $handWired = [];
        $macro = [];

        foreach ($this->phpFiles($routesDir) as $file) {
            $source = (string) file_get_contents($file);

            // Macro mounts: Route::particleResource('key', …)
            if (preg_match_all('/particleResource\(\s*[\'"]([^\'"]+)[\'"]/', $source, $m)) {
                foreach ($m[1] as $key) {
                    $macro[$key] = true;
                }
            }

            // Hand-wired controller mounts: Route::<verb>('uri', [X::class, 'action']) — the uri's leading
            // path segment is the resource key. Restrict to array-callable route defs so a `->name('x')` or
            // a `Route::view` never registers.
            if (preg_match_all('/Route::(?:get|post|put|patch|delete)\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*\[/', $source, $m)) {
                foreach ($m[1] as $uri) {
                    $segment = $this->leadingSegment($uri);
                    if ($segment !== null) {
                        $handWired[$segment] = true;
                    }
                }
            }
        }

        return [$handWired, $macro];
    }

    /** The leading (non-parameter) path segment of a route uri — the resource key it hangs off. */
    protected function leadingSegment(string $uri): ?string
    {
        $first = explode('/', trim($uri, '/'))[0] ?? '';

        // A `{param}` leading segment (or empty) is not a resource key.
        if ($first === '' || str_starts_with($first, '{')) {
            return null;
        }

        return $first;
    }

    /**
     * Parse every controller under a dir into its {@see suggestFor()} row: its FQN, whether it extends the
     * particle base (walking the `extends` chain through the app autoloader), the resource key it binds (the
     * literal in its `particleResource()` override's `$this->registry->get('key')`), and each public action
     * classified passthrough-vs-delta.
     *
     * @return list<array{class: string, file: string, extendsParticleBase: bool, resourceKey: ?string, actions: array<string, string>}>
     */
    protected function collectControllers(string $dir): array
    {
        $rows = [];
        foreach ($this->phpFiles($dir) as $file) {
            $row = $this->parseController((string) file_get_contents($file), $file);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Parse ONE controller's source into a {@see suggestFor()} row, or null when it is not a class file.
     * Pure over source + reflection — unit-callable.
     *
     * @return array{class: string, file: string, extendsParticleBase: bool, resourceKey: ?string, actions: array<string, string>}|null
     */
    public function parseController(string $source, string $file = ''): ?array
    {
        $ast = $this->parse($source);
        if ($ast === null) {
            return null;
        }

        $finder = new NodeFinder;
        /** @var Node\Stmt\Class_|null $classNode */
        $classNode = $finder->findFirstInstanceOf($ast, Node\Stmt\Class_::class);
        if ($classNode === null || $classNode->name === null || $classNode->extends === null) {
            return null;
        }

        $namespace = $this->namespaceOf($ast);
        $fqn = ($namespace !== '' ? $namespace.'\\' : '').$classNode->name->toString();

        return [
            'class' => $fqn,
            'file' => $file,
            'extendsParticleBase' => $this->extendsParticleBase($fqn),
            'resourceKey' => $this->resourceKeyOf($classNode),
            'actions' => $this->classifyActions($classNode),
        ];
    }

    /**
     * Whether a class is a subclass of the beam {@see ParticleController} base (directly or through a host
     * shim like `App\Http\Controllers\Particle\ParticleController`). Resolved through the app autoloader —
     * the truthful check, since a host wraps the base in its own subclass. A non-autoloadable class
     * (fixture-only) falls back to false; the pure {@see suggestFor()} core takes the boolean directly so
     * fixtures assert both branches without loading a class.
     */
    protected function extendsParticleBase(string $fqn): bool
    {
        if (! class_exists($fqn)) {
            return false;
        }

        return is_subclass_of($fqn, ParticleController::class);
    }

    /**
     * The resource key a controller binds — the string literal in its `particleResource()` override's
     * `return $this->registry->get('key');`. Null when it doesn't override (the inline tier resolves its
     * key from the route default, so a class without an override isn't a bespoke shell we key on).
     */
    protected function resourceKeyOf(Node\Stmt\Class_ $classNode): ?string
    {
        foreach ($classNode->getMethods() as $method) {
            if ($method->name->toString() !== 'particleResource') {
                continue;
            }

            $finder = new NodeFinder;
            /** @var MethodCall[] $calls */
            $calls = $finder->findInstanceOf((array) $method->stmts, MethodCall::class);
            foreach ($calls as $call) {
                if ($call->name instanceof Node\Identifier
                    && $call->name->toString() === 'get'
                    && isset($call->args[0])
                    && $call->args[0]->value instanceof Node\Scalar\String_) {
                    return $call->args[0]->value->value;
                }
            }
        }

        return null;
    }

    /**
     * Classify each public action method passthrough-vs-delta. A method is a **passthrough** when its body
     * is a single `return` of a `$this-><baseVerb>(…)` or `parent::<baseVerb>(…)` call naming an inherited
     * {@see BASE_VERBS} verb; anything else (a guard, a custom query, a different envelope, extra statements,
     * a non-CRUD verb) is a **delta**. The resource-binding override and constructor
     * ({@see NON_ACTION_METHODS}) are skipped — they are not action surface.
     *
     * @return array<string, string> method name → `'passthrough'` | `'delta'`
     */
    protected function classifyActions(Node\Stmt\Class_ $classNode): array
    {
        $actions = [];
        foreach ($classNode->getMethods() as $method) {
            if (! $method->isPublic()) {
                continue;
            }
            $name = $method->name->toString();
            if (in_array($name, self::NON_ACTION_METHODS, true)) {
                continue;
            }

            $actions[$name] = $this->isPassthrough($method) ? 'passthrough' : 'delta';
        }

        return $actions;
    }

    /**
     * A method body is a pure passthrough iff its ONLY statement is `return $this-><baseVerb>(…)` or
     * `return parent::<baseVerb>(…)` for an inherited base verb. Any extra statement, or a return of
     * anything else (a bespoke `ResponseBody::from(...)`, a `DataFilter::query(...)`, etc.), makes it a
     * delta — the deterministic seam that keeps a legacy-envelope or non-CRUD action out of the fixable set.
     */
    protected function isPassthrough(ClassMethod $method): bool
    {
        $stmts = $method->stmts ?? [];
        if (count($stmts) !== 1 || ! $stmts[0] instanceof Return_) {
            return false;
        }

        $expr = $stmts[0]->expr;

        if ($expr instanceof MethodCall
            && $expr->var instanceof Node\Expr\Variable
            && $expr->var->name === 'this'
            && $expr->name instanceof Node\Identifier) {
            return in_array($expr->name->toString(), self::BASE_VERBS, true);
        }

        if ($expr instanceof StaticCall
            && $expr->class instanceof Node\Name
            && $expr->class->toString() === 'parent'
            && $expr->name instanceof Node\Identifier) {
            return in_array($expr->name->toString(), self::BASE_VERBS, true);
        }

        return false;
    }

    // ── source/AST helpers ───────────────────────────────────────────────────────────────────────────

    /**
     * @param  Node[]  $ast
     */
    protected function namespaceOf(array $ast): string
    {
        /** @var Node\Stmt\Namespace_|null $ns */
        $ns = (new NodeFinder)->findFirstInstanceOf($ast, Node\Stmt\Namespace_::class);

        return $ns?->name?->toString() ?? '';
    }

    /**
     * @return Node[]|null
     */
    protected function parse(string $source): ?array
    {
        return (new ParserFactory)->createForNewestSupportedVersion()->parse($source);
    }

    protected function shortName(string $fqn): string
    {
        $pos = strrpos($fqn, '\\');

        return $pos === false ? $fqn : substr($fqn, $pos + 1);
    }

    /**
     * Absolute paths of every `.php` file under a dir (recursive), or empty when the dir is absent.
     *
     * @return list<string>
     */
    protected function phpFiles(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $files = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($it as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
