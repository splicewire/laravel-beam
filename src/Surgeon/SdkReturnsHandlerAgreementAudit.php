<?php

namespace Splicewire\Beam\Surgeon;

use Illuminate\Support\Facades\Route;
use PhpParser\Node;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use ReflectionClass;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData;
use Splicewire\Beam\Routing\BeamRouteAction;
use Splicewire\Beam\Routing\RouteReturnType;

/**
 * Does an annotated route's HANDLER actually send the shape the route DECLARES?
 * (`client-sdk-codegen` ticket 06.)
 *
 * The inverse of {@see SdkReturnsCoverageAudit}. That audit asks which routes LACK
 * `->beam()->returns(DtoClass::class)`; this one asks whether the ones that HAVE it are telling the
 * truth. Nothing in the estate asked the second question before this — `api-surface-coherence` 46
 * covered {@see RouteReturnType}'s *resolution* (which declaration wins), never
 * whether the winner matches what the controller returns.
 *
 * **Why an unchecked `->returns()` is worse than a missing one.** `returns` is the codegen seam's only
 * signal for a bespoke endpoint: the generated client casts the response body to the declared class, the
 * OpenAPI emitter describes the response with it, and `tsc` sources its types from the same place. So a
 * wrong annotation is not an omission that a reader notices — it is a *typed lie* that every instrument
 * downstream agrees with. Measured 2026-09-03 at `~/Herd/splicewire-app`:
 * `integrations.conduit.rotate-credential` declares `LlmKeySecretData` (which carries a plaintext `key`)
 * at `routes/tenant.php:1291`, while `splicewire/tower`'s `IntegrationsController::rotateCredential()`
 * returns `response()->json(['data' => ['rotated' => true]])`. A consumer reading `.key` off the
 * generated hook gets `undefined` at runtime with a green type-check.
 *
 * ## Advisory, never fatal
 *
 * Per the estate's rule — throw only on what a declaration's author could have gotten right *without
 * knowing which host loads it*. Both halves here are host facts: **which** routes are mounted is
 * host-dependent (the same `returns()` macro is called from a package whose routes a given host may not
 * register), and **what** a handler resolves to depends on the container and on which package version the
 * host vendored. A boot-time throw on this axis has already taken a host down on this estate once
 * (the event-catalog prefix throw, `AGENTS.md`). So: `Warn` at worst, and `run()` never throws.
 *
 * ## Reach before precision — and both halves in the same change
 *
 * Reach is every route in the router carrying an explicit declaration, read through
 * {@see BeamRouteAction::returns()} — not the two `beam.client.sources.*` realms
 * {@see SdkReturnsCoverageAudit::forApp()} scopes to. A wrong `returns()` is a lie wherever it is
 * declared, including on routes no generated hook covers, because the OpenAPI spec reads the same slot.
 *
 * Widening reach ARMS guesses that were previously dying on a null, so the precision half ships here too.
 * The governing rule is *a wrong label on a real finding, never a fabricated finding*, and it is enforced
 * by making the only Warn-tier verdict a **provable** one:
 *
 *   - {@see VERDICT_AGREES} — the declared class is constructed on a return path (directly, via a
 *     `#[ResponseFromData]` on the action, or one call-chain level in), matched on SHORT name because the
 *     controller body writes short names its own `use` statements resolve.
 *   - {@see VERDICT_CONTRADICTS} — **every** return path is a fully-literal envelope: array literals,
 *     scalars, and `response()->json(<literal>)`/`->noContent()`, with zero DTO construction and zero
 *     delegation anywhere in any return expression. One dynamic sub-expression anywhere and the row is
 *     undetermined instead. This is the rotate-credential shape and it is a fact, not an inference.
 *   - {@see VERDICT_CONSTRUCTS_OTHER} — return paths construct `*Data` classes and none of them is the
 *     declared one. Reported as the fact it is ("declares X, returns Y") rather than as a verdict that the
 *     declaration is wrong: the declared class can legitimately wrap the constructed one.
 *   - {@see VERDICT_UNDETERMINED} — everything else, each row carrying a REASON.
 *
 * ⚠️ **Return-statement scoping is load-bearing, not tidiness.** `rotateCredential()` constructs
 * `new LlmKeySecretData(...)` in its body — it writes one to the vault — and returns a literal array. A
 * whole-body DTO search therefore reads the estate's one known instance of this defect as **agreeing**.
 * {@see returnExpressions()} looks only at `return` expressions, and does not descend into nested
 * closures (a closure's `return` belongs to its own body). Same trap {@see SdkReturnsCoverageAudit}
 * documents from the other direction, where a `throw new Ex(new SomeData(...))` was read as a response.
 *
 * ## ⚠️ A route count is not a handler count, and the route count flatters the audit
 *
 * Measured at the flagship 2026-09-03: **209 declaring routes resolve to 106 distinct handlers.** Three
 * shared controllers account for 106 of the routes on their own — `ResourceDiscoveryController` (44),
 * `HookEventCatalogController` (34) and `ResourceFiltersController` (28) are each mounted once per
 * resource, so one agreeing method body is counted dozens of times. Per route the reading is 171 agree /
 * 38 undetermined (18% unmeasured); **per distinct handler it is 68 / 38 — 36% unmeasured.**
 *
 * Both numbers are true and the route one is the one a summary line naturally reports, which is exactly
 * the failure this estate keeps meeting: the instrument counts the thing it can enumerate, and the answer
 * gets written down against the question someone actually asked. Read the per-handler figure before
 * concluding anything about coverage. {@see classify()} returns the rows, so deduplicating on
 * `controllerClass@actionMethod` is one line.
 *
 * ## The answer distinguishes "clean" from "did not look"
 *
 * An audit whose zero reads identically for "no disagreements" and "could not resolve a single handler"
 * has not measured. So the undetermined population is a first-class output: it is emitted as a
 * {@see Finding::inconclusive()} carrying a per-reason breakdown, and a run over zero declarations is
 * inconclusive rather than a Pass. An instrument that enumerates its known blind spots and not its
 * unknown ones reads as thorough exactly where it is weakest, so {@see REASONS} is the enumerated list
 * and anything that escapes it lands under {@see REASON_UNPARSED} rather than being dropped.
 */
class SdkReturnsHandlerAgreementAudit implements DoctorAudit
{
    public const CHECK = 'sdk.returns-handler-agreement';

    public const VERDICT_AGREES = 'agrees';

    public const VERDICT_CONTRADICTS = 'contradicts';

    public const VERDICT_CONSTRUCTS_OTHER = 'constructs-other';

    public const VERDICT_UNDETERMINED = 'undetermined';

    /** The action is a closure, or otherwise carries no `Controller@method` to read. */
    public const REASON_NOT_A_CONTROLLER_ACTION = 'not-a-controller-action';

    /** The controller class does not resolve — a stale FQN, or a package this host did not vendor. */
    public const REASON_CONTROLLER_UNRESOLVABLE = 'controller-unresolvable';

    /** The class resolves but its file is unreadable, or the named method is not in it (inherited/`__call`). */
    public const REASON_METHOD_NOT_FOUND = 'method-not-found';

    /** The file could not be parsed into an AST. */
    public const REASON_UNPARSED = 'unparsed';

    /** The action returns nothing at all, or only bare `return;` — nothing to compare. */
    public const REASON_NO_RETURN_PATH = 'no-return-path';

    /** A return path delegates to a call this audit will not follow (deeper than one level, or unresolvable). */
    public const REASON_DELEGATES = 'delegates';

    /** Return paths are neither provably literal nor DTO-bearing — a variable, a helper, a merge. */
    public const REASON_OPAQUE_RETURN = 'opaque-return';

    public const REASONS = [
        self::REASON_NOT_A_CONTROLLER_ACTION,
        self::REASON_CONTROLLER_UNRESOLVABLE,
        self::REASON_METHOD_NOT_FOUND,
        self::REASON_UNPARSED,
        self::REASON_NO_RETURN_PATH,
        self::REASON_DELEGATES,
        self::REASON_OPAQUE_RETURN,
    ];

    /**
     * @param  list<array{routeName: ?string, uri: string, declared: string, controllerClass: ?string, actionMethod: ?string}>  $routes
     *                                                                                                                                   Every route carrying an explicit `returns` declaration. Kept as plain rows so the class is
     *                                                                                                                                   unit-testable with no router, no container and no host boot — the same reason
     *                                                                                                                                   {@see SdkReturnsCoverageAudit} keeps {@see forApp()} off its constructor.
     */
    public function __construct(protected array $routes) {}

    /**
     * The default host wiring: every route in the router whose mount declared a response DTO.
     *
     * Read through {@see BeamRouteAction::returns()} rather than by grepping `routes/*.php`, for two
     * reasons. A grep sees the host's own files only, while the declaration is legal from a package's
     * route file too; and a grep reads what someone WROTE, where this needs what the router actually
     * CARRIES — the estate's standing distinction between a declaration reading and a resolution reading.
     */
    public static function forApp(): self
    {
        $rows = [];

        foreach (Route::getRoutes() as $route) {
            $declared = BeamRouteAction::returns($route);
            if (! is_string($declared) || $declared === '') {
                continue;
            }

            $controllerClass = null;
            $actionMethod = null;
            $action = $route->getActionName();

            if (is_string($action) && str_contains($action, '@')) {
                [$controllerClass, $actionMethod] = explode('@', $action, 2);
            }

            $rows[] = [
                'routeName' => $route->getName(),
                'uri' => $route->uri(),
                'declared' => $declared,
                'controllerClass' => $controllerClass,
                'actionMethod' => $actionMethod,
            ];
        }

        return new self($rows);
    }

    /** @return list<Finding> */
    public function run(): array
    {
        $rows = $this->classify();

        if ($rows === []) {
            return [Finding::inconclusive(self::CHECK, 'No route in this host declares a response DTO with ->beam()->returns(), so no handler could be compared against one. This is a reach reading, not a clean one.')];
        }

        $findings = [];
        $counts = array_fill_keys([
            self::VERDICT_AGREES,
            self::VERDICT_CONTRADICTS,
            self::VERDICT_CONSTRUCTS_OTHER,
            self::VERDICT_UNDETERMINED,
        ], 0);
        $reasons = array_fill_keys(self::REASONS, 0);

        foreach ($rows as $row) {
            $counts[$row['verdict']]++;

            if ($row['verdict'] === self::VERDICT_UNDETERMINED) {
                $reasons[$row['reason']] = ($reasons[$row['reason']] ?? 0) + 1;

                continue;
            }

            if ($row['verdict'] === self::VERDICT_CONTRADICTS) {
                $findings[] = Finding::warn(self::CHECK, sprintf(
                    '%s [%s] declares %s but %s returns a literal envelope on every return path — the generated client and the OpenAPI response are typed from a shape the handler never sends.',
                    $row['routeName'] ?? $row['uri'],
                    $row['uri'],
                    $this->shortName($row['declared']),
                    $this->actionLabel($row),
                ));

                continue;
            }

            if ($row['verdict'] === self::VERDICT_CONSTRUCTS_OTHER) {
                $findings[] = Finding::warn(self::CHECK, sprintf(
                    '%s [%s] declares %s but %s constructs %s on its return paths — review whether the declared class wraps it or the declaration is stale.',
                    $row['routeName'] ?? $row['uri'],
                    $row['uri'],
                    $this->shortName($row['declared']),
                    $this->actionLabel($row),
                    implode(', ', array_map(fn (string $c) => $this->shortName($c), $row['constructed'])),
                ));
            }
        }

        $reasonSummary = implode(', ', array_map(
            fn (string $reason) => "{$reason}: {$reasons[$reason]}",
            array_values(array_filter(self::REASONS, fn (string $r) => ($reasons[$r] ?? 0) > 0)),
        ));

        // Always emitted, even when every row agreed: the counts are what tell a reader that a clean run
        // measured something rather than skipping everything.
        $findings[] = Finding::pass(self::CHECK, sprintf(
            '%d route(s) declare a response DTO — %d agree, %d contradict, %d construct a different DTO, %d could not be determined.',
            count($rows),
            $counts[self::VERDICT_AGREES],
            $counts[self::VERDICT_CONTRADICTS],
            $counts[self::VERDICT_CONSTRUCTS_OTHER],
            $counts[self::VERDICT_UNDETERMINED],
        ));

        // The undetermined tail is its OWN finding, and the reason it is separate is the whole point of
        // this audit's third design constraint. Folding it into the summary above and marking that summary
        // inconclusive makes a run that measured 209 routes render as "(measured nothing)" — false, and
        // false in the direction that teaches a reader to ignore the flag. Attached here, the flag says
        // exactly what it means: THIS population was not measured, the other one was.
        if ($counts[self::VERDICT_UNDETERMINED] > 0) {
            $findings[] = Finding::inconclusive(self::CHECK, sprintf(
                'No verdict could be reached for %d of %d declaring route(s) (%s) — these are unmeasured, not clean.',
                $counts[self::VERDICT_UNDETERMINED],
                count($rows),
                $reasonSummary,
            ));
        }

        return $findings;
    }

    /**
     * One classified row per declaring route. Public so a census (ticket 06's third acceptance
     * criterion) can read the table rather than re-deriving it from finding prose.
     *
     * @return list<array{routeName: ?string, uri: string, declared: string, controllerClass: ?string, actionMethod: ?string, verdict: string, reason: ?string, constructed: list<string>}>
     */
    public function classify(): array
    {
        /** @var array<string, array<string, ClassMethod>|null> $parsed */
        $parsed = [];
        $rows = [];

        foreach ($this->routes as $route) {
            $rows[] = $route + $this->classifyOne($route, $parsed);
        }

        return $rows;
    }

    /**
     * @param  array{declared: string, controllerClass: ?string, actionMethod: ?string}  $route
     * @param  array<string, array<string, ClassMethod>|null>  $parsed
     * @return array{verdict: string, reason: ?string, constructed: list<string>}
     */
    protected function classifyOne(array $route, array &$parsed): array
    {
        if ($route['controllerClass'] === null || $route['actionMethod'] === null) {
            return $this->undetermined(self::REASON_NOT_A_CONTROLLER_ACTION);
        }

        $file = $this->fileFor($route['controllerClass']);
        if ($file === null) {
            return $this->undetermined(self::REASON_CONTROLLER_UNRESOLVABLE);
        }

        $methods = $this->methodsFor($file, $route['controllerClass'], $parsed);
        if ($methods === null) {
            return $this->undetermined(self::REASON_UNPARSED);
        }

        $method = $methods[$route['actionMethod']] ?? null;
        if ($method === null) {
            return $this->undetermined(self::REASON_METHOD_NOT_FOUND);
        }

        return $this->classifyMethod($method, $route['declared'], $route['controllerClass'], $parsed, followChain: true);
    }

    /**
     * @param  array<string, array<string, ClassMethod>|null>  $parsed
     * @return array{verdict: string, reason: ?string, constructed: list<string>}
     */
    protected function classifyMethod(ClassMethod $method, string $declared, string $controllerClass, array &$parsed, bool $followChain): array
    {
        $declaredShort = $this->shortName($declared);

        $attribute = $this->responseFromDataClass($method);
        if ($attribute !== null && $this->shortName($attribute) === $declaredShort) {
            return $this->verdict(self::VERDICT_AGREES);
        }

        $returns = $this->returnExpressions($method);
        if ($returns === []) {
            return $this->undetermined(self::REASON_NO_RETURN_PATH);
        }

        $constructed = [];
        foreach ($returns as $expr) {
            foreach ($this->dtoConstructions($expr) as $class) {
                $constructed[$class] = true;
            }
        }
        $constructed = array_keys($constructed);

        foreach ($constructed as $class) {
            if ($this->shortName($class) === $declaredShort) {
                return $this->verdict(self::VERDICT_AGREES);
            }
        }

        if ($constructed !== []) {
            return $this->verdict(self::VERDICT_CONSTRUCTS_OTHER, constructed: $constructed);
        }

        // No DTO on any return path. Two ways that can end.
        //
        // Order matters and is not arbitrary: the literal test runs FIRST, because
        // `response()->json([...])` is itself a `MethodCall` and the delegation search would otherwise
        // claim every literal JSON envelope as an unresolvable hop — which is the estate's own founding
        // instance of this defect, so the audit would have been blind to the route it was built for.
        $allLiteral = true;
        foreach ($returns as $expr) {
            if (! $this->isLiteralEnvelope($expr)) {
                $allLiteral = false;

                break;
            }
        }

        if ($allLiteral) {
            return $this->verdict(self::VERDICT_CONTRADICTS);
        }

        // Otherwise follow ONE level into a delegating call — most controllers hand DTO construction to
        // a service — and re-ask there. Bounded to one level exactly as SdkReturnsCoverageAudit's own
        // chain-follow is: an unbounded walk is where a source-parsing instrument starts inventing
        // answers. An unresolved or ambiguous hop is `delegates`, never a fall-through to a verdict.
        if ($followChain) {
            $delegate = $this->delegateFor($returns, $controllerClass, $parsed);

            if ($delegate instanceof ClassMethod) {
                $inner = $this->classifyMethod($delegate, $declared, $controllerClass, $parsed, followChain: false);

                return $inner['verdict'] === self::VERDICT_UNDETERMINED
                    ? $this->undetermined(self::REASON_DELEGATES)
                    : $inner;
            }

            if ($delegate === false) {
                return $this->undetermined(self::REASON_DELEGATES);
            }
        }

        return $this->undetermined(self::REASON_OPAQUE_RETURN);
    }

    /**
     * Whether one return expression is a provably literal envelope — the only shape that licenses a
     * `contradicts` verdict.
     *
     * Deliberately conservative and whitelist-shaped: array literals of literals, scalars, `null`,
     * `response()->json(<literal>)` / `->noContent()` and `response()->noContent()`-style chains whose
     * arguments are all literal. Anything else — a variable, a property fetch, a helper call, a `+`
     * merge — is NOT literal and sends the row to {@see REASON_OPAQUE_RETURN}. A blacklist would have to
     * enumerate every dynamic form and would fabricate a finding on the first one it forgot.
     */
    protected function isLiteralEnvelope(Node\Expr $expr): bool
    {
        if ($expr instanceof Node\Scalar || $expr instanceof Node\Expr\ConstFetch) {
            return true;
        }

        if ($expr instanceof Node\Expr\Array_) {
            foreach ($expr->items as $item) {
                if ($item === null) {
                    continue;
                }
                if ($item->key !== null && ! $this->isLiteralEnvelope($item->key)) {
                    return false;
                }
                if (! $this->isLiteralEnvelope($item->value)) {
                    return false;
                }
            }

            return true;
        }

        // `response()->json([...])`, `response()->noContent()`, `response()->json([...], 201)` — a chain
        // rooted at the `response()` helper with literal arguments the whole way.
        if ($expr instanceof MethodCall) {
            foreach ($expr->args as $arg) {
                if (! $arg instanceof Node\Arg || ! $this->isLiteralEnvelope($arg->value)) {
                    return false;
                }
            }

            return $this->isLiteralEnvelope($expr->var);
        }

        if ($expr instanceof Node\Expr\FuncCall
            && $expr->name instanceof Node\Name
            && in_array($expr->name->toString(), ['response', 'noContent'], true)) {
            foreach ($expr->args as $arg) {
                if (! $arg instanceof Node\Arg || ! $this->isLiteralEnvelope($arg->value)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * The single `$this->method()` / `$this->property->method()` a return path delegates to, resolved one
     * level. Returns the callee's {@see ClassMethod}, `false` when a delegating call is present but
     * unresolvable, or `null` when there is no delegation to follow.
     *
     * @param  list<Node\Expr>  $returns
     * @param  array<string, array<string, ClassMethod>|null>  $parsed
     * @return ClassMethod|false|null
     */
    protected function delegateFor(array $returns, string $controllerClass, array &$parsed)
    {
        $calls = [];
        foreach ($returns as $expr) {
            foreach ((new NodeFinder)->findInstanceOf([$expr], MethodCall::class) as $call) {
                $calls[] = $call;
            }
        }

        if ($calls === []) {
            return null;
        }

        $propertyTypes = $this->constructorPropertyTypes($controllerClass);
        $resolved = [];

        foreach ($calls as $call) {
            if (! $call->name instanceof Node\Identifier) {
                continue;
            }
            $target = $this->resolveCallTargetClass($call, $controllerClass, $propertyTypes);
            if ($target === null) {
                continue;
            }
            $file = $this->fileFor($target);
            $methods = $file === null ? null : $this->methodsFor($file, $target, $parsed);
            $callee = $methods[$call->name->toString()] ?? null;
            if ($callee !== null) {
                $resolved[] = $callee;
            }
        }

        if (count($resolved) === 1) {
            return $resolved[0];
        }

        // Zero resolved (an unknown hop) or several (ambiguous) — both are "I could not determine",
        // never a licence to fall through to the literal branch.
        return false;
    }

    /**
     * `$this->foo()` → the controller itself; `$this->service->foo()` → the constructor-declared type of
     * `$service`. Anything else is unresolvable by design.
     *
     * @param  array<string, string>  $propertyTypes
     */
    protected function resolveCallTargetClass(MethodCall $call, string $controllerClass, array $propertyTypes): ?string
    {
        if ($call->var instanceof Node\Expr\Variable && $call->var->name === 'this') {
            return $controllerClass;
        }

        if ($call->var instanceof Node\Expr\PropertyFetch
            && $call->var->var instanceof Node\Expr\Variable
            && $call->var->var->name === 'this'
            && $call->var->name instanceof Node\Identifier) {
            return $propertyTypes[$call->var->name->toString()] ?? null;
        }

        return null;
    }

    /**
     * Constructor-declared dependency types, covering both promoted properties and classic
     * `$this->x = $x` assignments — read by reflection so an inherited constructor still resolves.
     *
     * @return array<string, string>
     */
    protected function constructorPropertyTypes(string $controllerClass): array
    {
        if (! class_exists($controllerClass)) {
            return [];
        }

        $constructor = (new ReflectionClass($controllerClass))->getConstructor();
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

    protected function fileFor(string $class): ?string
    {
        if (! class_exists($class) && ! interface_exists($class)) {
            return null;
        }

        $file = (new ReflectionClass($class))->getFileName();

        return $file === false || ! is_readable($file) ? null : $file;
    }

    /**
     * The named class's methods, parsed out of its file.
     *
     * Selected by NAME rather than by taking the first {@see ClassLike} in the file — the estate is PSR-4
     * so one-class-per-file is the norm, but "the norm" is not a measurement, and a file with a second
     * class in it would otherwise have every one of its routes silently classified against the wrong
     * body. Falls back to the first class only when no name matches (an anonymous or aliased declaration),
     * which is a resolution this audit can still be honest about.
     *
     * @param  array<string, array<string, ClassMethod>|null>  $cache
     * @return array<string, ClassMethod>|null
     */
    protected function methodsFor(string $file, string $class, array &$cache): ?array
    {
        $key = $file.'::'.$class;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        try {
            $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse((string) file_get_contents($file));
        } catch (\Throwable) {
            return $cache[$key] = null;
        }

        if ($ast === null) {
            return $cache[$key] = null;
        }

        $short = $this->shortName($class);
        $classNode = null;
        /** @var list<ClassLike> $candidates */
        $candidates = (new NodeFinder)->findInstanceOf($ast, ClassLike::class);

        foreach ($candidates as $candidate) {
            if ($candidate->name !== null && $candidate->name->toString() === $short) {
                $classNode = $candidate;

                break;
            }
        }

        $classNode ??= $candidates[0] ?? null;
        if ($classNode === null) {
            return $cache[$key] = null;
        }

        $methods = [];
        foreach ($classNode->getMethods() as $method) {
            $methods[$method->name->toString()] = $method;
        }

        return $cache[$key] = $methods;
    }

    /** The `#[ResponseFromData(DataClass::class)]` attribute's declared class, if the action carries one. */
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
     * Every `return` expression in the body, top-level and in branches, excluding bare `return;` and
     * deliberately NOT descending into nested closures — a closure's `return` belongs to its own body,
     * not to the action's response. See this class's docblock for why scoping to returns (rather than the
     * whole body) is what makes the audit able to see its own founding instance at all.
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
     * Every `*Data` class constructed anywhere within one return expression — `new X`, `X::from()`,
     * `X::fromModel()`, `X::collect()`, `X::collection()`. Matches the estate's `*Data` DTO suffix
     * convention, the same signal {@see SdkReturnsCoverageAudit::outermostDtoConstructions()} uses.
     *
     * Unlike that sibling this does NOT stop at the outermost construction: a declared shape can
     * legitimately be built inside a wrapper, and the question here is "is the declared class among the
     * ones this return builds", where the sibling's question was "what single class is this response".
     *
     * @return list<string>
     */
    protected function dtoConstructions(Node\Expr $expr): array
    {
        $found = [];

        foreach ((new NodeFinder)->find([$expr], function (Node $node): bool {
            if ($node instanceof StaticCall) {
                return $node->class instanceof Node\Name
                    && str_ends_with($node->class->toString(), 'Data')
                    && $node->name instanceof Node\Identifier
                    && in_array($node->name->toString(), ['from', 'fromModel', 'collect', 'collection'], true);
            }

            return $node instanceof New_
                && $node->class instanceof Node\Name
                && str_ends_with($node->class->toString(), 'Data');
        }) as $node) {
            /** @var StaticCall|New_ $node */
            $found[] = $node->class->toString();
        }

        return array_values(array_unique($found));
    }

    /**
     * @param  list<string>  $constructed
     * @return array{verdict: string, reason: ?string, constructed: list<string>}
     */
    protected function verdict(string $verdict, array $constructed = []): array
    {
        return ['verdict' => $verdict, 'reason' => null, 'constructed' => $constructed];
    }

    /** @return array{verdict: string, reason: ?string, constructed: list<string>} */
    protected function undetermined(string $reason): array
    {
        return ['verdict' => self::VERDICT_UNDETERMINED, 'reason' => $reason, 'constructed' => []];
    }

    /** @param array{controllerClass: ?string, actionMethod: ?string} $row */
    protected function actionLabel(array $row): string
    {
        return $this->shortName((string) $row['controllerClass']).'::'.$row['actionMethod'].'()';
    }

    protected function shortName(string $fqn): string
    {
        $position = strrpos($fqn, '\\');

        return $position === false ? $fqn : substr($fqn, $position + 1);
    }
}
