<?php

namespace Splicewire\Beam\Surgeon;

use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\UseItem;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Rushing\Surgeon\Operation\FixableFinding;
use Rushing\Surgeon\Operation\OperationSuggestion;
use Rushing\Surgeon\Operation\SuggestsOperations;
use Splicewire\Beam\Particle\Attributes\BespokeByDesign;
use Splicewire\Beam\Particle\Backing\ModelResourceIndex;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * The particle-OPERATION bypass audit (declaration-drift-audit map, ticket 03 — a sibling to
 * {@see ParticleControllerRedundancyAudit}, not a replacement). That audit catches a controller SHELL
 * duplicating a registered resource's CRUD; this one catches a hand-wired NON-CRUD action whose body
 * operates on an ALREADY-REGISTERED resource's own model, entirely outside
 * {@see ParticleOperationRegistry} — the `Route::particleOp()` escape hatch built for exactly this
 * ("the framework supplies routing/auth/kind plumbing, the handler stays host-written",
 * {@see ParticleOperation}'s own docblock).
 *
 * ## The mechanical invariant (all four, per action)
 * A hand-wired controller action is a bypass candidate when it:
 *   1. is **hand-wired** — mounted by a bespoke `Route::get/post/put/patch/delete(…, [X::class, 'verb'])`,
 *      the same wiring-style fact {@see ParticleControllerRedundancyAudit} reads (its own `extends
 *      ParticleController` leg is NOT required here — the redundancy is against the MODEL, not the
 *      controller shell, so this audit sees action classes the sibling audit structurally cannot), AND
 *   2. its body touches (a static call on) a model class that is the **registered `model`** of SOME
 *      {@see ParticleResource}, AND
 *   3. is **not itself a standard CRUD verb** ({@see ParticleControllerRedundancyAudit::BASE_VERBS} —
 *      that overlap is the sibling audit's territory, never double-flagged here), AND
 *   4. has **no matching {@see ParticleOperation}** already registered for
 *      `{resourceKey}:{actionName}`.
 *
 * ## Acknowledged bespoke sites ({@see BespokeByDesign})
 * A method (or its whole class) carrying `#[BespokeByDesign(reason: …)]` is a REVIEWED divergence — the
 * finding downgrades to a Pass line that still surfaces `acknowledged: <reason>` (acknowledged ≠
 * invisible), and no migration is suggested. The attribute is read reflectively at emit time, so the
 * pure core stays fixture-testable: a non-autoloadable fixture class simply resolves to un-acknowledged.
 *
 * ## Always advisory, never mechanically fixable
 * Unlike a pure-passthrough CRUD shell (a textual delete), migrating a bespoke action into a
 * {@see ParticleOperation} requires a human to choose the
 * {@see OperationKind}, name an `ability`, and confirm the model resolves by
 * the same `{id}` the action already takes — real wiring judgment every time, even when the handler
 * body itself moves verbatim. Every finding is therefore {@see OperationSuggestion::advisory()}, naming
 * the resource/model/action to migrate; nothing here auto-applies.
 *
 * ## Honesty about what it can statically see
 * The touched-model detection is a static-call scan (`Model::query()`/`::find*`/`::create`/`::where*`/
 * `::firstOrCreate`/`::make`) against the class's own `use` imports — a model referenced only via a
 * property, dependency injection, or a service class one level removed is invisible to this pass
 * (mirrors the sibling audit's own "Honesty" section). The registered-resource and
 * registered-operation legs are RUNTIME facts (the registries are populated by boot-time discovery),
 * read from the booted registries in host context ({@see forRoutes()}); the pure core
 * ({@see suggestFor()}) takes all facts as inputs so it is unit-testable with in-memory fixtures.
 */
class ParticleOperationBypassAudit implements DoctorAudit, SuggestsOperations
{
    public const CHECK = 'particle.operation-bypass';

    /** Static-call method names treated as "this method touches the model". */
    public const MODEL_TOUCH_HINTS = [
        'query', 'find', 'findOrFail', 'findMany', 'create', 'firstOrCreate', 'firstOrNew',
        'updateOrCreate', 'where', 'whereKey', 'all', 'make',
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
        protected ?ParticleResourceRegistry $resourceRegistry = null,
        protected ?ParticleOperationRegistry $operationRegistry = null,
    ) {
        $this->controllersDirs = array_values((array) $controllersDirs);
        $this->routesDirs = array_values((array) $routesDirs);
    }

    /**
     * The default host-scoped wiring — mirrors {@see ParticleControllerRedundancyAudit::forRoutes()},
     * including the host dirs + package-contributed {@see AuditScanPaths} pairs. Kept off the constructor
     * so the class is pure-unit testable via {@see suggestFor()} — no registry, no route table, no DB.
     *
     * @param  string|list<string>|null  $controllersDirs
     * @param  string|list<string>|null  $routesDirs
     */
    public static function forRoutes(
        string|array|null $controllersDirs = null,
        string|array|null $routesDirs = null,
        ?ParticleResourceRegistry $resourceRegistry = null,
        ?ParticleOperationRegistry $operationRegistry = null,
    ): self {
        $contributed = app()->bound(AuditScanPaths::class) ? app(AuditScanPaths::class) : null;
        $controllersDirs ??= [base_path('app/Http/Controllers'), ...($contributed?->controllersDirs() ?? [])];
        $routesDirs ??= [base_path('routes'), ...($contributed?->routesDirs() ?? [])];
        $resourceRegistry ??= app(ParticleResourceRegistry::class);
        $operationRegistry ??= app(ParticleOperationRegistry::class);

        return new self($controllersDirs, $routesDirs, $resourceRegistry, $operationRegistry);
    }

    /**
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
        return $this->suggestFor(
            $this->collectControllers($this->controllersDirs),
            $this->collectHandWiredActions($this->routesDirs, $this->controllersDirs),
            $this->registeredModels(),
            $this->registeredOperationKeys(),
        );
    }

    /**
     * The pure core — no disk, no route facade, no registries. Given the parsed controllers (each public
     * non-CRUD action mapped to the model FQNs its body touches), the set of hand-wired `Fqcn::method`
     * action keys, the registered model-FQN => resource-key map, and the set of already-registered
     * `resource:name` operation keys — produce one advisory {@see FixableFinding} per bypass.
     *
     * @param  list<array{class: string, file: string, actions: array<string, list<class-string>>}>  $controllers
     * @param  array<string, true>  $handWiredActions  `"Fqcn::method"` keys mounted by a bespoke route
     * @param  array<class-string, list<string>>  $registeredModels  model FQN => every resource key
     *                                                               registered against it (usually one; more
     *                                                               than one is a genuine ambiguity — e.g. a
     *                                                               write resource and a public read
     *                                                               projection sharing the same Eloquent
     *                                                               model — surfaced rather than guessed)
     * @param  array<string, true>  $registeredOperationKeys  `"resource:name"` keys already registered
     * @return list<FixableFinding>
     */
    public function suggestFor(array $controllers, array $handWiredActions, array $registeredModels, array $registeredOperationKeys): array
    {
        $findings = [];

        foreach ($controllers as $controller) {
            foreach ($controller['actions'] as $action => $models) {
                // Leg 3: CRUD verbs are ParticleControllerRedundancyAudit's territory, never double-flagged here.
                if (in_array($action, ParticleControllerRedundancyAudit::BASE_VERBS, true)) {
                    continue;
                }

                // Leg 1: must be mounted by a bespoke route, not framework-internal dispatch.
                if (! isset($handWiredActions["{$controller['class']}::{$action}"])) {
                    continue;
                }

                // Leg 2: the action must touch a model that's the registered model of SOME resource(s).
                $resourceKeys = [];
                $touchedModel = null;
                foreach ($models as $model) {
                    if (isset($registeredModels[$model]) && $registeredModels[$model] !== []) {
                        $resourceKeys = $registeredModels[$model];
                        $touchedModel = $model;
                        break;
                    }
                }
                if ($resourceKeys === []) {
                    continue;
                }

                // Leg 4: not already covered by a registered operation of the same name on ANY candidate
                // resource — a match on one resource key means the action is already served, full stop.
                $alreadyCovered = false;
                foreach ($resourceKeys as $candidate) {
                    if (isset($registeredOperationKeys["{$candidate}:{$action}"])) {
                        $alreadyCovered = true;
                        break;
                    }
                }
                if ($alreadyCovered) {
                    continue;
                }

                $shortClass = $this->shortName($controller['class']);
                $ambiguous = count($resourceKeys) > 1;
                $resourceList = implode(', ', $resourceKeys);

                // An acknowledged bespoke site downgrades to a Pass that still carries the reason —
                // the ledger keeps the line, the WARN (and the migration nomination) stand down.
                $acknowledged = BespokeByDesign::on($controller['class'], $action);
                if ($acknowledged !== null) {
                    $findings[] = new FixableFinding(
                        Finding::pass(self::CHECK, sprintf(
                            '%s::%s hand-wires a non-CRUD action against [%s] (resource [%s]) — bespoke by design, '.
                            'acknowledged: %s',
                            $shortClass,
                            $action,
                            $this->shortName($touchedModel),
                            $resourceList,
                            $acknowledged->reason,
                        )),
                        null,
                    );

                    continue;
                }

                $findings[] = new FixableFinding(
                    Finding::warn(self::CHECK, $ambiguous
                        ? sprintf(
                            '%s::%s hand-wires an action against [%s], which is registered under MULTIPLE particle '.
                            'resources [%s], with no matching ParticleOperation on any of them. Ambiguous — a human '.
                            'must pick the resource before migrating to Route::particleOp().',
                            $shortClass,
                            $action,
                            $this->shortName($touchedModel),
                            $resourceList,
                        )
                        : sprintf(
                            '%s::%s hand-wires an action against [%s], the model registered for particle resource [%s], '.
                            "with no matching ParticleOperation. It can migrate to Route::particleOp('%s', '%s', …).",
                            $shortClass,
                            $action,
                            $this->shortName($touchedModel),
                            $resourceList,
                            $resourceList,
                            $action,
                        )),
                    $ambiguous
                        ? OperationSuggestion::advisory(
                            "{$shortClass}::{$action} touches a model shared by resources [{$resourceList}] — ".
                            'resolve which one owns this action before choosing an OperationKind/ability and '.
                            'mounting via Route::particleOp(); the handler body moves as-is once resolved.',
                            'splicewire/laravel-beam',
                        )
                        : OperationSuggestion::advisory(
                            "Migrate {$shortClass}::{$action} into a ParticleOperation on '{$resourceList}' (name '{$action}'): ".
                            'choose an OperationKind, an ability, and mount via Route::particleOp() — the handler body moves as-is.',
                            'splicewire/laravel-beam',
                        ),
                );
            }
        }

        return $findings;
    }

    /**
     * The registered `model FQN => every resource key registered against it` map, from the booted
     * {@see ParticleResourceRegistry} via {@see ModelResourceIndex}. Empty when no registry is wired
     * (the pure-unit path).
     *
     * This method used to build the map here by reflecting into the registry's private `$resources`,
     * on the stated grounds that it *"exposes `get($key)`/`has($key)` but not an enumeration"* —
     * ⚠️ **false: `all()` exists** (`ParticleResourceRegistry:121-127`) and applies the identical filter.
     * `ParticleControllerRedundancyAudit` held the same belief and grew the same reflection; ticket 11 §A7
     * found the pair and gave them one home.
     *
     * Deliberately a LIST per model, not a single key: two resources sharing one Eloquent model (a
     * writable owner-scoped resource and a public read-only projection, e.g. audiostud's `songs` +
     * `listen-songs` both targeting `Composition`) is a real shape, and picking just one silently
     * (whichever registers last) can name the WRONG resource in a migration suggestion — worse than
     * surfacing the ambiguity.
     *
     * @return array<class-string, list<string>>
     */
    protected function registeredModels(): array
    {
        if ($this->resourceRegistry === null) {
            return [];
        }

        return (new ModelResourceIndex($this->resourceRegistry))->all();
    }

    /**
     * The registered `resource:name` operation keys, read from the booted {@see ParticleOperationRegistry}
     * reflectively (it exposes `get($resource, $name)` but not an enumeration). Empty when no registry is
     * wired (the pure-unit path).
     *
     * @return array<string, true>
     */
    protected function registeredOperationKeys(): array
    {
        if ($this->operationRegistry === null) {
            return [];
        }

        $ref = new \ReflectionClass($this->operationRegistry);
        while ($ref !== false && ! $ref->hasProperty('operations')) {
            $ref = $ref->getParentClass();
        }
        if ($ref === false) {
            return [];
        }

        $prop = $ref->getProperty('operations');
        $prop->setAccessible(true);
        /** @var array<string, mixed> $operations */
        $operations = $prop->getValue($this->operationRegistry);

        return array_fill_keys(array_keys($operations), true);
    }

    /**
     * Parse the route files for every bespoke `Route::get/post/put/patch/delete(uri, [X::class, 'verb'])`
     * mount, keyed `"Fqcn::verb"` against the collected controllers' short names (route files typically
     * `use` a controller and reference it by short name — resolving through the ALREADY-PARSED controller
     * FQNs avoids re-parsing `use` statements in every route file).
     *
     * @param  list<string>  $routesDirs
     * @param  list<string>  $controllersDirs
     * @return array<string, true>
     */
    protected function collectHandWiredActions(array $routesDirs, array $controllersDirs): array
    {
        $shortToFqn = [];
        foreach ($this->collectControllers($controllersDirs) as $controller) {
            $shortToFqn[$this->shortName($controller['class'])] = $controller['class'];
        }

        $handWired = [];
        foreach ($this->phpFilesUnder($routesDirs) as $file) {
            $source = (string) file_get_contents($file);

            if (preg_match_all(
                '/Route::(?:get|post|put|patch|delete)\(\s*[\'"][^\'"]*[\'"]\s*,\s*\[\s*([\w\\\\]+)::class\s*,\s*[\'"]([^\'"]+)[\'"]\s*\]/',
                $source,
                $m,
                PREG_SET_ORDER,
            )) {
                foreach ($m as $match) {
                    $shortOrFqn = ltrim($match[1], '\\');
                    $method = $match[2];
                    $fqn = $shortToFqn[$shortOrFqn] ?? $shortOrFqn;
                    $handWired["{$fqn}::{$method}"] = true;
                }
            }
        }

        return $handWired;
    }

    /**
     * Parse every controller under a dir into its {@see suggestFor()} row: its FQN, and each PUBLIC
     * non-constructor action mapped to the (deduplicated) model FQNs its body touches.
     *
     * @param  list<string>  $dirs
     * @return list<array{class: string, file: string, actions: array<string, list<class-string>>}>
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
     * Pure over source + the class's own `use` imports — unit-callable.
     *
     * @return array{class: string, file: string, actions: array<string, list<class-string>>}|null
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
        $imports = $this->importsOf($ast);

        $actions = [];
        foreach ($classNode->getMethods() as $method) {
            if (! $method->isPublic() || $method->name->toString() === '__construct') {
                continue;
            }

            $actions[$method->name->toString()] = $this->touchedModels($method, $imports);
        }

        return [
            'class' => $fqn,
            'file' => $file,
            'actions' => $actions,
        ];
    }

    /**
     * The (deduplicated) imported class FQNs a method's static calls resolve to, restricted to calls whose
     * method name is one of {@see MODEL_TOUCH_HINTS} — the static-call scan this audit's "Honesty" section
     * documents as its detection limit.
     *
     * @param  array<string, class-string>  $imports  short name => FQN
     * @return list<class-string>
     */
    protected function touchedModels(ClassMethod $method, array $imports): array
    {
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

            $className = $call->class->toString();
            $fqn = $imports[$className] ?? null;
            if ($fqn !== null) {
                $models[$fqn] = true;
            }
        }

        return array_keys($models);
    }

    /**
     * The class's own `use` imports, short name => FQN — needed to resolve a bare `Composition::query()`
     * static call to its real namespace without a live autoloader.
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

    // ── source/AST helpers (mirrors ParticleControllerRedundancyAudit's own) ───────────────────────────

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
