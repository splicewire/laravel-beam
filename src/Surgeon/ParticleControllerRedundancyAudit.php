<?php

namespace Splicewire\Beam\Surgeon;

use Illuminate\Support\Facades\Route;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\UseItem;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Rushing\Surgeon\Operation\FixableFinding;
use Rushing\Surgeon\Operation\OperationSuggestion;
use Rushing\Surgeon\Operation\SuggestsOperations;
use Splicewire\Beam\Facades\Particle as ParticleFacade;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Particle\Attributes\BespokeByDesign;
use Splicewire\Beam\Particle\Backing\ModelResourceIndex;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * The particle-controller REDUNDANCY audit (refactor-tooling ticket 16, from the hunt-02 "controller vs
 * particle" for-instance). Beam owns the concept of a *proper* particle controller (ticket 07, decision 3:
 * the concept-owner rule), so beam — not surgeon — owns the audit that says "this bespoke controller shell
 * is a route-wiring style the `Particle::mount()` front door already replaces."
 *
 * ## Detection keys on BEHAVIOR, not inheritance (two paths per controller)
 * The original invariant keyed leg 1 on `extends ParticleController`, which made the audit structurally
 * blind to a bespoke controller that does CRUD against a registered resource's model from OUTSIDE the
 * particle base (commerce's `Operator/PlanController` — a plain `Controller` whose `index` lists the
 * registered `plans` resource's `Plan` model — never surfaced). Detection now runs two paths:
 *
 * **Structural path** (the original — a particle-base shell; all three, per controller):
 *   1. `extends` the particle base ({@see ParticleController} / a host subclass of it — checked by walking
 *      the `extends` chain up the app's autoloader) and binds a key via its `particleResource()` override, AND
 *   2. serves a resource key that is **already registered** as a `ParticleResource` in the booted
 *      {@see ParticleResourceRegistry} (so the front door has a declaration to mount against), AND
 *   3. is **hand-wired** — its actions are mounted by bespoke `Route::get/post/…(…, [X::class, 'verb'])`
 *      calls while its peers (`tags`/`media`/`activity`) ride the controller-free `Particle::mount()`
 *      front door against the SAME registry.
 *
 * **Behavior path** (any controller, no base required; all three, per controller):
 *   1. has public action(s) named with a **CRUD-shaped verb** ({@see CRUD_VERBS}) whose bodies statically
 *      touch (a static call, {@see MODEL_TOUCH_HINTS}) a model class that is the **registered `model`** of
 *      some {@see ParticleResource}, AND
 *   2. that resource key is **hand-wired** in the scanned route files, AND
 *   3. that key does not already ride the `Particle::mount()` front door.
 * Behavior-path findings are always ADVISORY (a bespoke body is never a mechanical passthrough), naming
 * the actions, the touched model, and the registered resource whose declaration already serves the CRUD.
 *
 * A controller failing every path (no particle base + no CRUD-verb action on a registered model, resource
 * unregistered, already front-door-mounted) is NOT flagged — the redundancy is precisely "a controller
 * re-implementing surface `Particle::mount()` already mounts for the same declaration."
 *
 * ## The fix split — the deterministic/agentic seam (ticket 16)
 * Whether the shell can COLLAPSE to the front door is decided by a deterministic AST check of every action
 * body:
 *   - **pure passthrough** — every action body is a single `return $this-><baseVerb>(…)` /
 *     `return parent::<baseVerb>(…)` against an inherited base verb ({@see BASE_VERBS}) with NO envelope
 *     delta ⇒ a **fixable** {@see OperationSuggestion} nominating the collapse to `Particle::mount()`
 *     (the `particle-resource-collapse` kind a host's route-rewrite operation applies);
 *   - **has a real delta** — any action carries a genuine body (a `validate()` guard, a custom query, a
 *     different response envelope, or a non-CRUD sub-verb like `CircuitController`'s run lifecycle /
 *     `ContextScopeController`'s `embeddings`) ⇒ an **advisory** {@see OperationSuggestion::advisory()}
 *     naming what blocks the collapse. The human decides whether a partial collapse (CRUD → front door,
 *     keep a slim controller for the delta) is worth it.
 *
 * It lives in BEAM, not surgeon (ADR-0092 direction: beam depends DOWN on the foundation byte-splice
 * engine): surgeon must never know "a particle-controller shell duplicates a mountable resource" (a
 * beam estate fact). Beam owns that POLICY and nominates a generic collapse operation via the
 * Finding→Operation bridge — exactly like the sibling {@see SdkEndpointDriftAudit} /
 * {@see SdkNameConventionAudit}.
 *
 * ## Acknowledged bespoke shells ({@see BespokeByDesign})
 * A controller class carrying `#[BespokeByDesign(reason: …)]` is a REVIEWED divergence — its finding
 * downgrades to a Pass line that still surfaces `acknowledged: <reason>` (acknowledged ≠ invisible), and
 * no collapse is suggested. Read reflectively at emit time; a non-autoloadable fixture class resolves to
 * un-acknowledged, keeping the pure core fixture-testable.
 *
 * ## Honesty about what it can statically see
 * The extends-chain and hand-wired-vs-front-door legs are pure route-file/source facts, read statically. The
 * behavior path's touched-model detection is a static-call scan against the class's own `use` imports
 * (mirrors {@see ParticleOperationBypassAudit}'s "Honesty" section) — a model reached only via a property,
 * dependency injection, or a repository one level removed is invisible to this pass. The
 * registered-resource leg is a RUNTIME fact — the registry is populated by boot-time `#[ParticleResource]`
 * discovery, not knowable from a source scan — so the default wiring reads the booted
 * {@see ParticleResourceRegistry} in host context ({@see forRoutes()}). The pure core {@see suggestFor()}
 * takes all facts as inputs so it is unit-testable with in-memory fixtures (no registry, no route
 * table, no DB), mirroring the sibling audits.
 *
 * ## Scan scope
 * The default wiring scans the host's `app/Http/Controllers` + `routes/` AND every
 * `(controllersDir, routesDir)` pair family packages contributed to the boot-time {@see AuditScanPaths}
 * singleton — a package's HTTP surface joins the sweep wherever its provider boots.
 */
class ParticleControllerRedundancyAudit implements DoctorAudit, SuggestsOperations
{
    public const CHECK = 'particle.controller-redundant';

    /**
     * The inherited base verbs a redundant shell's actions passthrough to. `store`/`update`/`destroy`/
     * `index`/`show` are the REST verbs the front door mounts; `createParticle` is the base's create body an
     * extending controller rides to keep a legacy `200` (still a pure passthrough — same pipeline, just the
     * envelope flag). A body calling anything else is a real delta.
     */
    public const BASE_VERBS = ['index', 'show', 'store', 'update', 'destroy', 'createParticle'];

    /**
     * Actions that DON'T count as CRUD shell surface: the resource-binding override (not an action) and the
     * base internals. A controller whose ONLY methods are these + pure passthroughs is fully collapsible.
     */
    public const NON_ACTION_METHODS = ['particleResource', '__construct'];

    /**
     * The REST verbs the behavior path keys on — a public action with one of these names whose body
     * touches a registered resource's model is CRUD-shaped surface the front door already mounts.
     */
    public const CRUD_VERBS = ['index', 'show', 'store', 'update', 'destroy'];

    /**
     * Static-call method names treated as "this action's body touches the model" — the bypass audit's
     * {@see ParticleOperationBypassAudit::MODEL_TOUCH_HINTS} plus the query-builder entry points a bespoke
     * LIST body typically opens with (`Plan::orderBy(…)`, `Model::with(…)->paginate(…)`).
     */
    public const MODEL_TOUCH_HINTS = [
        'query', 'find', 'findOrFail', 'findMany', 'create', 'firstOrCreate', 'firstOrNew',
        'updateOrCreate', 'where', 'whereKey', 'all', 'make',
        'orderBy', 'latest', 'oldest', 'with', 'withCount', 'paginate', 'simplePaginate', 'get', 'count', 'pluck',
    ];

    /** @var list<string> */
    protected array $controllersDirs;

    /** @var list<string> */
    protected array $routesDirs;

    /**
     * @param  string|list<string>  $controllersDirs
     * @param  string|list<string>  $routesDirs
     */
    public function __construct(
        string|array $controllersDirs,
        string|array $routesDirs,
        protected ?ParticleResourceRegistry $registry = null,
    ) {
        $this->controllersDirs = array_values((array) $controllersDirs);
        $this->routesDirs = array_values((array) $routesDirs);
    }

    /**
     * The default host-scoped wiring: scan the app's `app/Http/Controllers` PLUS every controllers dir
     * family packages contributed via {@see AuditScanPaths}, parse the app's `routes/` files (plus
     * contributed routes dirs) for the hand-wired-vs-front-door split, and read the booted
     * {@see ParticleResourceRegistry} for the registered-resource leg. Kept off the constructor so the
     * class is pure-unit testable via {@see suggestFor()} — no registry, no route table, no DB.
     *
     * @param  string|list<string>|null  $controllersDirs
     * @param  string|list<string>|null  $routesDirs
     */
    public static function forRoutes(
        string|array|null $controllersDirs = null,
        string|array|null $routesDirs = null,
        ?ParticleResourceRegistry $registry = null,
    ): self {
        $contributed = app()->bound(AuditScanPaths::class) ? app(AuditScanPaths::class) : null;
        $controllersDirs ??= [base_path('app/Http/Controllers'), ...($contributed?->controllersDirs() ?? [])];
        $routesDirs ??= [base_path('routes'), ...($contributed?->routesDirs() ?? [])];
        $registry ??= app(ParticleResourceRegistry::class);

        return new self($controllersDirs, $routesDirs, $registry);
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
        [$handWiredKeys, $mountedKeys] = $this->collectRouteWiring($this->routesDirs);

        return $this->suggestFor(
            $this->collectControllers($this->controllersDirs),
            $handWiredKeys,
            $mountedKeys,
            $this->registeredKeys(),
            $this->registeredModels(),
        );
    }

    /**
     * The pure core — no disk, no route facade, no registry. Given the parsed controllers, the set of
     * resource keys mounted by HAND (bespoke `Route::…(…, [X::class, …])`), the set already mounted through
     * the `Particle::mount()` front door, the set of REGISTERED `ParticleResource` keys, and the registered
     * model-FQN => resource-key map (the behavior path's lens), produce one {@see FixableFinding} per
     * redundant CRUD controller.
     *
     * Directly unit testable: a pure-passthrough particle controller on a registered+hand-wired key →
     * fixable collapse; a with-delta one → advisory naming the blocker; a NON-particle controller whose
     * CRUD-verb action body touches a registered resource's model → advisory naming the behavior-level
     * redundancy; an unregistered resource or an already-front-door-mounted key → no finding.
     *
     * @param  list<array{class: string, file: string, extendsParticleBase: bool, resourceKey: ?string, actions: array<string, string>, crudModels?: array<string, list<class-string>>}>  $controllers
     *                                                                                                                                                                                                  `actions` maps a public action method name → its body shape: `'passthrough'` or `'delta'`;
     *                                                                                                                                                                                                  `crudModels` maps each CRUD-verb action → the model FQNs its body statically touches.
     * @param  array<string, true>  $handWiredKeys  resource keys mounted by a bespoke controller route call
     * @param  array<string, true>  $mountedKeys  resource keys already mounted via `Particle::mount()`
     * @param  array<string, true>  $registeredKeys  registered `ParticleResource` keys
     * @param  array<class-string, list<string>>  $registeredModels  model FQN => every resource key registered against it
     * @return list<FixableFinding>
     */
    public function suggestFor(array $controllers, array $handWiredKeys, array $mountedKeys, array $registeredKeys, array $registeredModels = []): array
    {
        $findings = [];

        foreach ($controllers as $controller) {
            $key = $controller['resourceKey'];

            // A controller outside the particle base (or one that never binds a key) can still be a
            // redundant CRUD controller — the BEHAVIOR path judges it by what its actions do.
            if (! $controller['extendsParticleBase'] || $key === null) {
                foreach ($this->behaviorFindings($controller, $handWiredKeys, $mountedKeys, $registeredModels) as $finding) {
                    $findings[] = $finding;
                }

                continue;
            }

            // Structural path. Leg 2: its key must be registered. Leg 3: it must be hand-wired (a peer
            // front-door mount of the SAME key is fine — the point is THIS class is bespoke).
            if (! isset($registeredKeys[$key]) || ! isset($handWiredKeys[$key])) {
                continue;
            }
            if (isset($mountedKeys[$key])) {
                // Already mounted through the front door for its own key — not a redundant hand-wired shell.
                continue;
            }

            $deltas = array_keys(array_filter($controller['actions'], fn (string $shape) => $shape === 'delta'));
            $shortClass = $this->shortName($controller['class']);
            $peerRidesFrontDoor = $mountedKeys !== [];

            // An acknowledged bespoke shell downgrades to a Pass that still carries the reason — the
            // ledger keeps the line, the WARN (and the collapse nomination) stand down.
            $acknowledged = BespokeByDesign::on($controller['class']);
            if ($acknowledged !== null) {
                $findings[] = new FixableFinding(
                    Finding::pass(self::CHECK, sprintf(
                        '%s is a hand-wired particle shell on the registered resource [%s] — bespoke by design, '.
                        'acknowledged: %s',
                        $shortClass,
                        $key,
                        $acknowledged->reason,
                    )),
                    null,
                );

                continue;
            }

            if ($deltas === []) {
                // Pure passthrough: every action is a base-verb call, no envelope delta — collapsible.
                $findings[] = new FixableFinding(
                    Finding::warn(self::CHECK, sprintf(
                        '%s is a pure-passthrough particle CRUD shell on the registered resource [%s]; its '.
                        'peers ride Particle::mount(). It can collapse to the front door (no envelope delta).',
                        $shortClass,
                        $key,
                    )),
                    new OperationSuggestion(
                        kind: 'particle-resource-collapse',
                        summary: "Collapse {$shortClass} to Particle::mount('{$key}') and delete the shell",
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
            // front door, keep a slim controller for the delta actions). Named, not applied: the human decides.
            $findings[] = new FixableFinding(
                Finding::warn(self::CHECK, sprintf(
                    '%s is a particle CRUD shell on the registered resource [%s]%s, but carries real deltas '.
                    '(%s) that block a full collapse to Particle::mount().',
                    $shortClass,
                    $key,
                    $peerRidesFrontDoor ? ' whose peers ride Particle::mount()' : '',
                    implode(', ', $deltas),
                )),
                OperationSuggestion::advisory(
                    "Partial collapse of {$shortClass}: move CRUD to Particle::mount('{$key}'), keep a slim ".
                    'controller for '.implode(', ', $deltas).'.',
                    'splicewire/laravel-beam',
                ),
            );
        }

        return $findings;
    }

    /**
     * The BEHAVIOR path: no particle base required. Each CRUD-verb public action whose body statically
     * touches a model registered as some resource's `model` is redundant surface when that resource key is
     * hand-wired and not already front-door-mounted. Emits ONE finding per (controller, resource key) — always
     * advisory (a bespoke body is never a mechanical passthrough), unless the class carries
     * `#[BespokeByDesign]`, which downgrades to the same acknowledged Pass as the structural path.
     *
     * @param  array{class: string, file: string, extendsParticleBase: bool, resourceKey: ?string, actions: array<string, string>, crudModels?: array<string, list<class-string>>}  $controller
     * @param  array<string, true>  $handWiredKeys
     * @param  array<string, true>  $mountedKeys
     * @param  array<class-string, list<string>>  $registeredModels
     * @return list<FixableFinding>
     */
    protected function behaviorFindings(array $controller, array $handWiredKeys, array $mountedKeys, array $registeredModels): array
    {
        // resource key => [actions => list<action>, model => FQN]
        $byKey = [];
        foreach ($controller['crudModels'] ?? [] as $action => $models) {
            foreach ($models as $model) {
                foreach ($registeredModels[$model] ?? [] as $candidate) {
                    // Same legs as the structural path: hand-wired, and not already riding the front door.
                    if (! isset($handWiredKeys[$candidate]) || isset($mountedKeys[$candidate])) {
                        continue;
                    }
                    $byKey[$candidate]['actions'][$action] = true;
                    $byKey[$candidate]['model'] = $model;
                }
            }
        }

        $findings = [];
        $shortClass = $this->shortName($controller['class']);

        foreach ($byKey as $key => $hit) {
            $actions = array_keys($hit['actions']);
            $actionList = implode(', ', $actions);
            $shortModel = $this->shortName($hit['model']);

            $acknowledged = BespokeByDesign::on($controller['class']);
            if ($acknowledged !== null) {
                $findings[] = new FixableFinding(
                    Finding::pass(self::CHECK, sprintf(
                        '%s hand-wires CRUD (%s) against [%s], the model of registered resource [%s] — bespoke '.
                        'by design, acknowledged: %s',
                        $shortClass,
                        $actionList,
                        $shortModel,
                        $key,
                        $acknowledged->reason,
                    )),
                    null,
                );

                continue;
            }

            $findings[] = new FixableFinding(
                Finding::warn(self::CHECK, sprintf(
                    '%s hand-wires CRUD action(s) (%s) against [%s], the model of the registered particle '.
                    'resource [%s], without extending the particle base or riding Particle::mount() — '.
                    'behavior-level redundancy the resource declaration already serves.',
                    $shortClass,
                    $actionList,
                    $shortModel,
                    $key,
                )),
                OperationSuggestion::advisory(
                    "Fold {$shortClass}'s CRUD ({$actionList}) into the registered '{$key}' particle resource — mount ".
                    "via Particle::mount('{$key}') and delete the bespoke action(s), or declare the ".
                    'divergence with #[BespokeByDesign].',
                    'splicewire/laravel-beam',
                ),
            );
        }

        return $findings;
    }

    /**
     * The registered `model FQN => every resource key registered against it` map, from the booted
     * registry — the behavior path's lens. Deliberately a LIST per model for the shared-model ambiguity
     * reason {@see ModelResourceIndex} documents. Empty when no registry is wired (the pure-unit path).
     *
     * Reads {@see ModelResourceIndex}. This method used to build the map here by reflecting into the
     * registry's private `$resources`, on the stated grounds that *"the registry exposes `has($key)` but
     * not an enumeration"* — ⚠️ **false: `all()` exists and applies the identical filter.** The same
     * belief produced the same reflection in `ParticleOperationBypassAudit`; ticket 11 §A7 found both and
     * gave them one home.
     *
     * @return array<class-string, list<string>>
     */
    protected function registeredModels(): array
    {
        if ($this->registry === null) {
            return [];
        }

        return (new ModelResourceIndex($this->registry))->all();
    }

    /**
     * The registered `ParticleResource` keys, from the booted registry — the structural path's leg 2.
     * Empty when no registry is wired (the pure-unit path).
     *
     * ⚠️ This used to reflect a private `$resources` property, on the same false belief the sibling
     * {@see ModelResourceIndex} documents: *"the registry exposes `has($key)` but not an enumeration."*
     * `all()` has existed the whole time. Worse, the property it reflected for **stopped existing**:
     * {@see ParticleResourceRegistry} composes a `BasicRegistry $entries` since the popcorn migration and
     * declares no `resources` anywhere in its hierarchy, so the walk up the chain fell off the end and
     * returned `[]`.
     *
     * Measured at `~/Herd/splicewire-app` on 2026-08-31: **0 keys against 53 registered resources.** Leg 2
     * of the structural path is `isset($registeredKeys[$key])`, so it was false for every controller —
     * the entire structural path (its pass, its two warns, and the fixable `particle-resource-collapse`
     * nomination) was unreachable. Only the behavior path ever fired, which is exactly why the audit
     * still looked alive. This is the estate's recurring shape: an instrument reporting success by not
     * running.
     *
     * This is the same defect, from the same cause, as `ParticleOperationBypassAudit::registeredOperationKeys()`
     * (repaired 2026-08-27, commit b9e9f57), and takes the same repair: read the **declarations**, never the
     * keyspace. `$resource->key` is public and is what the registry key is derived FROM, so a future rekey
     * cannot break this the same way. No reflection, no property-name assumption, and a host SUBCLASS of the
     * beam registry is handled for free because `all()` is inherited.
     *
     * @return array<string, true>
     */
    protected function registeredKeys(): array
    {
        if ($this->registry === null) {
            return [];
        }

        $keys = [];

        foreach ($this->registry->all() as $resource) {
            if ($resource instanceof ParticleResource) {
                $keys[$resource->key] = true;
            }
        }

        return $keys;
    }

    /**
     * Parse the route files for the two wiring styles, keyed by resource key:
     *   - HAND-WIRED — a `Route::get/post/put/patch/delete(uri, [SomeController::class, 'verb'])` whose uri's
     *     first path segment is the resource key (`agents`, `agents/{id}`, `context-scopes/{id}/embeddings`
     *     all key on their leading segment);
     *   - FRONT DOOR — a {@see ParticleFacade::mount()} call, read for the key it mounts
     *     ({@see mountedKeysIn()}).
     *
     * @param  list<string>  $routesDirs
     * @return array{0: array<string, true>, 1: array<string, true>} [handWiredKeys, mountedKeys]
     */
    protected function collectRouteWiring(array $routesDirs): array
    {
        $handWired = [];
        $mounted = [];

        foreach ($this->phpFilesUnder($routesDirs) as $file) {
            $source = (string) file_get_contents($file);

            foreach ($this->mountedKeysIn($source) as $key) {
                $mounted[$key] = true;
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

        return [$handWired, $mounted];
    }

    /**
     * The resource keys ONE route file mounts through the front door — every
     * `Particle::mount($uri, $resourceKey)` call in it.
     *
     * Read via the AST rather than a regex, for two reasons the deleted `Route::particleResource()` scan
     * did not have to face:
     *   - the key is **positional and optional** — `mount(string $uri, ?string $resourceKey = null)` keys on
     *     `$resourceKey ?? $uri`, so a one-arg `Particle::mount('plans')` mounts `plans` just as surely as a
     *     two-arg `Particle::mount('extensions', 'market-extensions')` mounts `market-extensions`. Named
     *     args (`mount(uri: …, resourceKey: …)`) are read the same way;
     *   - the facade can arrive under an **alias** (`use Splicewire\Beam\Facades\Particle as P;` → a bare
     *     `P::mount(…)`), which a source-text scan for the word `Particle` silently misses — the exact blind
     *     spot `BeamRouteProxy`'s `RouteFacade` import cost us.
     *
     * A call whose uri/key is not a string literal (a variable, a concatenation, a constant) is skipped:
     * this pass reads what it can see, and inventing a key it cannot read would be worse than a miss.
     *
     * @return list<string>
     */
    public function mountedKeysIn(string $source): array
    {
        try {
            $ast = $this->parse($source);
        } catch (Error) {
            return [];
        }
        if ($ast === null) {
            return [];
        }

        // Which bare class names in THIS file mean the Particle facade — its own short name unless an
        // import shadows it, plus every alias imported onto it.
        $names = [];
        $imports = $this->importsOf($ast);
        foreach ($imports as $alias => $fqn) {
            if (ltrim($fqn, '\\') === ParticleFacade::class) {
                $names[$alias] = true;
            }
        }
        if (! isset($imports['Particle'])) {
            $names['Particle'] = true;
        }

        $keys = [];
        /** @var StaticCall[] $calls */
        $calls = (new NodeFinder)->findInstanceOf($ast, StaticCall::class);
        foreach ($calls as $call) {
            if (! $call->class instanceof Node\Name || ! $call->name instanceof Node\Identifier) {
                continue;
            }
            if ($call->name->toString() !== 'mount') {
                continue;
            }
            $class = $call->class->toString();
            if (! isset($names[$class]) && $class !== ParticleFacade::class) {
                continue;
            }

            $key = $this->mountedKeyOf($call);
            if ($key !== null) {
                $keys[$key] = true;
            }
        }

        return array_keys($keys);
    }

    /**
     * The resource key ONE `Particle::mount()` call mounts: its `resourceKey` argument, or — absent, as it
     * is for the majority of the estate's mounts — the `uri` it defaults to. Null when neither is a string
     * literal this pass can read.
     */
    protected function mountedKeyOf(StaticCall $call): ?string
    {
        $uri = null;
        $resourceKey = null;
        $position = 0;

        foreach ($call->args as $arg) {
            if (! $arg instanceof Node\Arg) {
                continue;   // a `...` variadic placeholder (first-class callable syntax)
            }

            $value = $arg->value instanceof Node\Scalar\String_ ? $arg->value->value : null;
            $name = $arg->name?->toString();

            if ($name === 'uri') {
                $uri = $value;
            } elseif ($name === 'resourceKey') {
                $resourceKey = $value;
            } elseif ($name === null) {
                if ($position === 0) {
                    $uri = $value;
                } elseif ($position === 1) {
                    $resourceKey = $value;
                }
                $position++;
            }
        }

        $key = $resourceKey ?? ($uri === null ? null : trim($uri, '/'));

        return $key === null || $key === '' ? null : $key;
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
     * Parse every controller under the given dirs into its {@see suggestFor()} row: its FQN, whether it
     * extends the particle base (walking the `extends` chain through the app autoloader), the resource key
     * it binds (the literal in its `particleResource()` override's `$this->registry->get('key')`), each
     * public action classified passthrough-vs-delta, and each CRUD-verb action's statically touched models
     * (the behavior path's raw material).
     *
     * @param  list<string>  $dirs
     * @return list<array{class: string, file: string, extendsParticleBase: bool, resourceKey: ?string, actions: array<string, string>, crudModels: array<string, list<class-string>>}>
     */
    protected function collectControllers(array $dirs): array
    {
        $rows = [];
        foreach ($this->phpFilesUnder($dirs) as $file) {
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
     * @return array{class: string, file: string, extendsParticleBase: bool, resourceKey: ?string, actions: array<string, string>, crudModels: array<string, list<class-string>>}|null
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
        if ($classNode === null || $classNode->name === null) {
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
            'crudModels' => $this->crudModelsOf($classNode, $this->importsOf($ast)),
        ];
    }

    /**
     * The behavior path's raw material: each public CRUD-verb action ({@see CRUD_VERBS}) mapped to the
     * (deduplicated) model FQNs its body statically touches — a {@see MODEL_TOUCH_HINTS} static call whose
     * class resolves through the file's own `use` imports.
     *
     * @param  array<string, class-string>  $imports  short name => FQN
     * @return array<string, list<class-string>>
     */
    protected function crudModelsOf(Node\Stmt\Class_ $classNode, array $imports): array
    {
        $crudModels = [];
        foreach ($classNode->getMethods() as $method) {
            $name = $method->name->toString();
            if (! $method->isPublic() || ! in_array($name, self::CRUD_VERBS, true)) {
                continue;
            }

            $finder = new NodeFinder;
            /** @var StaticCall[] $calls */
            $calls = $finder->findInstanceOf((array) $method->stmts, StaticCall::class);

            $models = [];
            foreach ($calls as $call) {
                if (! $call->class instanceof Node\Name || ! $call->name instanceof Node\Identifier) {
                    continue;
                }
                if (! in_array($call->name->toString(), self::MODEL_TOUCH_HINTS, true)) {
                    continue;
                }

                $fqn = $imports[$call->class->toString()] ?? null;
                if ($fqn !== null) {
                    $models[$fqn] = true;
                }
            }

            if ($models !== []) {
                $crudModels[$name] = array_keys($models);
            }
        }

        return $crudModels;
    }

    /**
     * The file's `use` imports, short name => FQN — needed to resolve a bare `Plan::orderBy()` static call
     * to its real namespace without a live autoloader (mirrors the bypass audit's own).
     *
     * @param  Node[]  $ast
     * @return array<string, class-string>
     */
    protected function importsOf(array $ast): array
    {
        $finder = new NodeFinder;
        /** @var UseItem[] $uses */
        $uses = $finder->findInstanceOf($ast, UseItem::class);

        $imports = [];
        foreach ($uses as $use) {
            $fqn = $use->name->toString();
            $alias = $use->alias?->toString() ?? $this->shortName($fqn);
            $imports[$alias] = $fqn;
        }

        return $imports;
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
     * Absolute paths of every `.php` file under the given dirs (recursive), absent dirs skipped.
     *
     * @param  list<string>  $dirs
     * @return list<string>
     */
    protected function phpFilesUnder(array $dirs): array
    {
        return array_merge(...array_map(fn (string $dir) => $this->phpFiles($dir), $dirs ?: ['']));
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
