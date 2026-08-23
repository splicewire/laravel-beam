<?php

namespace Splicewire\Beam\Doctor;

use Illuminate\Contracts\Foundation\Application;
use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\UseItem;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use ReflectionAttribute;
use ReflectionClass;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Rushing\Popcorn\Registries\Exceptions\InvalidRegistryKey;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryArity;
use Rushing\Popcorn\Registries\RegistryIndex;
use Splicewire\Beam\Surgeon\UndescribedRegistryAudit;

/**
 * The **gate** (registry-kernel ticket 35, from ticket 14's D1/D6/D9/D11): a class that DECLARES itself a
 * registry with {@see IsRegistry} must declare itself completely and consistently.
 *
 * ## Why a gate here is proportionate when the estate's other checks are advisory
 *
 * The estate's usual argument against gating — *"a gate over that set blocks every host on day one and gets
 * switched off within the hour"* — turns on the population being everything that happens to be shaped a
 * certain way. **This population is opt-in by declaration and did not exist before ticket 23.** Nothing is
 * conscripted: a class enters the governed set the moment its author writes `#[IsRegistry(...)]`, and the
 * remedy for every finding is an argument on that same attribute. So there is no suppression list here of
 * ANY kind (14 D6) — not a `const`, not an artifact, not an opt-out attribute. Every judgement call lives in
 * {@see UndeclaredRegistryShapeAudit}, which is advisory precisely so this one does not have to be.
 *
 * A gate needing a suppression list is a gate built on a guess (14, closing note). This one is built on a
 * declaration.
 *
 * ## The checks
 *
 * Five checks, ONE check key ({@see CHECK}), each finding naming which check failed (14 D9) — the estate's
 * audits are single-purpose and this stays one purpose: *is this declaration complete?*
 *
 *   - {@see CHECK_CONTRACT} — `implements Registry`. See the sequencing note below; this is the one check
 *     that reports rather than blocks today.
 *   - {@see CHECK_ROOT} — `root` written at the declaration site and {@see Key}-legal.
 *     The empty string is the WHOLE-TREE root and is legal only for {@see RegistryIndex}, which is the one
 *     registry that owns the keyspace rather than a branch of it (ticket 20 D4).
 *   - {@see CHECK_ROOT_COLLISION} — no two declared registries on one root. Two registries on one root make
 *     that branch unroutable, which is different in kind from a duplicate ENTRY (the estate ships three
 *     argued policies for those). The runtime half throws at `describe()` time under `OnDuplicate::Reject`;
 *     this is the static half ticket 20 D7 handed here, and it fires before a boot rather than during one.
 *   - {@see CHECK_ARITY} — `arity` written at the declaration site.
 *   - {@see CHECK_ON_DUPLICATE} — `onDuplicate` written at the declaration site rather than silently
 *     inherited. The estate ships all three policies with argued docblocks (`LensRegistry` throws, tower's
 *     `CapabilityRegistry` admits, `ParticleResourceRegistry` overwrites), so an UNWRITTEN one is not a
 *     considered default, it is a guess that reads as a decision. Measured before landing: every one of the
 *     ~50 declarations in the estate already writes it, so this check ratchets a good state rather than
 *     opening a backlog.
 *
 * ## The chartered fifth check dissolved, and this is why
 *
 * Ticket 35 §1 chartered *"`resolve()` defined **only** under `RegistryArity::PickOne`"*, from ticket 06 D4.
 * **It has no subject.** {@see Registry} gives every registry both a one-entry read and an all-entries read,
 * and {@see RegistryArity}'s own docblock settles the point: arity is *"declared metadata, not an enforced
 * constraint"* — ticket 20 found that out by self-hosting the index, whose live `RunAll` and claimed
 * `PickOne` were never in conflict. Any implementer of the interface defines `resolve()`, so the check as
 * chartered would fail every `RunAll` and `ComposeMany` registry in the estate INCLUDING the index. The
 * root/collision pair is counted as two checks instead; the total is unchanged and the fifth one is real.
 *
 * ## `implements Registry` is measured; it does not gate. Yet.
 *
 * Ticket 21 D1 found that **declaring and indexing are two acts**, and only the first was 21's: ~50 classes
 * carry the attribute, three implement the contract. That is the designed state — `RegistryIndex::describe()`
 * takes a CONFORMING registry, so the index fills as tickets 37/38's migration lands, not before.
 *
 * So this check would hard-fail every declared registry in the fleet until an unrelated ticket lands, which
 * is exactly the failure the section above argues this audit is exempt from. {@see CONTRACT_GATES} is the
 * resolution and it is deliberately NOT a suppression list: it is one flag, population-wide, naming no rows
 * and requiring no maintenance, and flipping it is a one-line change once 37/38 land. A per-row list would
 * have to be argued row by row and would rot; a per-check flag cannot, because there is nothing in it to go
 * stale.
 *
 * The check still RUNS from day one — {@see declarations()} evaluates all five regardless — so a registry
 * declared tomorrow cannot quietly skip the contract. What the flag changes is only where the failure is
 * SAID: through the committed artifact and `splicewire:beam:registry-conformance`, which is where this
 * estate puts burn-downs, rather than through the doctor, which is where it puts blocks. See {@see gates()}
 * for why a non-gating check cannot simply be a `warn()` on a gating audit.
 *
 * ## Detection is the live container, so the claim is about a COMPOSITION
 *
 * The population is read off the live binding table plus whatever has reached {@see RegistryIndex} (14 D2),
 * not off a filesystem scan. That makes every finding a claim about **the host this audit booted inside**,
 * never about "the family" — a distinction 14 D12 is emphatic about, and the reason there is no cross-host
 * aggregator here. The ticket-03 census is what speaks for the family, and a boot cannot do its job.
 *
 * One consequence to know rather than rediscover: a registry bound under an INTERFACE whose attribute sits
 * on the concrete (`singleton(Schema::class, NodeSchema::class)`) is invisible to a binding-table read,
 * because Laravel wraps the concrete in a closure. Those are reachable through the index once they describe.
 * The cost is a miss, and a miss here is recoverable; a gate firing on something it half-read is not.
 */
class RegistryConformanceAudit implements DoctorAudit
{
    public const CHECK = 'registry.non-conforming';

    /**
     * Whether the `implements Registry` check BLOCKS or merely reports. See the class docblock — flip to
     * `true` once registry-kernel tickets 37 and 38 have landed the migration, and delete this constant
     * with the same commit that does.
     */
    public const CONTRACT_GATES = false;

    public const CHECK_CONTRACT = 'implements-registry';

    public const CHECK_ROOT = 'root';

    public const CHECK_ROOT_COLLISION = 'root-collision';

    public const CHECK_ARITY = 'arity';

    public const CHECK_ON_DUPLICATE = 'on-duplicate';

    /**
     * Constructor positions of {@see IsRegistry}, so a POSITIONAL declaration is read the same as a named
     * one. `ReflectionAttribute::getArguments()` reports exactly what the author wrote — which is the whole
     * reason the "declared rather than inherited" checks are answerable at all — but it reports positional
     * arguments under integer keys.
     *
     * @var list<string>
     */
    public const ARGUMENT_POSITIONS = ['root', 'of', 'arity', 'entryType', 'onDuplicate', 'optionality', 'note', 'order'];

    public function __construct(
        protected Application $app,
        protected RegistryIndex $index,
    ) {}

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        $rows = $this->declarations();
        $findings = [];

        foreach ($rows as $row) {
            foreach ($row['failures'] as $check) {
                if (! $this->gates($check)) {
                    continue;
                }

                $findings[] = Finding::fail(self::CHECK, $this->detail($row, $check));
            }
        }

        if ($findings !== []) {
            return $findings;
        }

        // The PASS says what it does NOT cover, in the same breath. A green gate reading as a clean estate
        // is the exact misreading `UndescribedRegistryAudit`'s docblock had to be rewritten to prevent, and
        // this one is green today only because its widest check is deliberately not gating yet.
        return [Finding::pass(self::CHECK, sprintf(
            'All %d declared registr%s in this composition declares itself completely%s',
            count($rows),
            count($rows) === 1 ? 'y' : 'ies',
            self::CONTRACT_GATES
                ? '.'
                : sprintf(
                    ' — EXCEPT the `%s` check, which does not gate yet (registry-kernel 37/38). %d of them do '.
                    'not implement the contract; run `splicewire:beam:registry-conformance --json` for the list.',
                    self::CHECK_CONTRACT,
                    count(array_filter($rows, fn (array $row) => in_array(self::CHECK_CONTRACT, $row['failures'], true))),
                ),
        ))];
    }

    /**
     * Every `#[IsRegistry]`-declaring class this host composes, with the checks it fails.
     *
     * Sorted by FQCN so a re-run with no code change reports in a byte-identical order; neither container
     * binding order nor filesystem iteration order is stable enough to compare against.
     *
     * @return list<array{registry: string, root: string, package: string|null, provider: string|null, file: string|null, line: int|null, failures: list<string>}>
     */
    public function declarations(): array
    {
        $population = $this->population();
        $rows = [];

        foreach ($population as $fqcn) {
            $attribute = $this->attributeOf($fqcn);

            if ($attribute === null) {
                continue;
            }

            $written = $this->writtenArguments($attribute);
            $declared = $this->instantiate($attribute);
            $location = $this->locate($fqcn);

            $rows[$fqcn] = [
                'registry' => $fqcn,
                'root' => $declared?->root ?? '',
                'package' => $this->packageOf($fqcn),
                'provider' => $location['provider'],
                'file' => $location['file'],
                'line' => $location['line'],
                'failures' => $this->failuresFor($fqcn, $written, $declared, $population),
            ];
        }

        ksort($rows);

        return array_values($rows);
    }

    /**
     * The work-list proper: only the rows with something wrong.
     *
     * @return list<array{registry: string, root: string, package: string|null, provider: string|null, file: string|null, line: int|null, failures: list<string>}>
     */
    public function nonConforming(): array
    {
        return array_values(array_filter($this->declarations(), fn (array $row) => $row['failures'] !== []));
    }

    /**
     * The FQCNs this composition declares as registries: every class-string in the live binding table
     * carrying the attribute, plus every owner already described into the index.
     *
     * The index is read UNFILTERED — scope is a structural question, and an authorizer narrowing it would
     * silently shrink what the gate governs (the same argument {@see UndescribedRegistryAudit::governedRoots()}
     * makes).
     *
     * @return list<string>
     */
    public function population(): array
    {
        $found = [];

        foreach (array_keys($this->app->getBindings()) as $abstract) {
            if (is_string($abstract) && $this->attributeOf($abstract) !== null) {
                $found[$abstract] = true;
            }
        }

        foreach ($this->index->unfiltered()->keys() as $key) {
            $owner = $this->index->owner($key);

            if ($owner !== null && $this->attributeOf($owner::class) !== null) {
                $found[$owner::class] = true;
            }
        }

        $keys = array_keys($found);
        sort($keys);

        return $keys;
    }

    /**
     * @param  array<string, mixed>  $written  arguments actually written at the declaration site
     * @param  list<string>  $population
     * @return list<string>
     */
    protected function failuresFor(string $fqcn, array $written, ?IsRegistry $declared, array $population): array
    {
        $failures = [];

        if (! is_a($fqcn, Registry::class, true)) {
            $failures[] = self::CHECK_CONTRACT;
        }

        if (! array_key_exists('root', $written) || ! $this->rootIsLegal($fqcn, $declared)) {
            $failures[] = self::CHECK_ROOT;
        } elseif ($this->collidesOnRoot($fqcn, $declared, $population)) {
            $failures[] = self::CHECK_ROOT_COLLISION;
        }

        if (! array_key_exists('arity', $written)) {
            $failures[] = self::CHECK_ARITY;
        }

        if (! array_key_exists('onDuplicate', $written)) {
            $failures[] = self::CHECK_ON_DUPLICATE;
        }

        return $failures;
    }

    /**
     * A declared root is legal when it parses as a key — with the ONE exception ticket 20 D4 carved out: the
     * zero-segment root, spelled `''`, belongs to {@see RegistryIndex} alone. Any other class spelling it
     * empty is claiming the whole keyspace, which is why the emptiness is checked here rather than left to
     * `rootKey()`, whose job is only to parse it.
     */
    protected function rootIsLegal(string $fqcn, ?IsRegistry $declared): bool
    {
        if ($declared === null) {
            return false;
        }

        if ($declared->root === '') {
            return is_a($fqcn, RegistryIndex::class, true);
        }

        try {
            $declared->rootKey();
        } catch (InvalidRegistryKey) {
            return false;
        }

        return true;
    }

    /**
     * @param  list<string>  $population
     */
    protected function collidesOnRoot(string $fqcn, ?IsRegistry $declared, array $population): bool
    {
        if ($declared === null) {
            return false;
        }

        foreach ($population as $other) {
            if ($other === $fqcn) {
                continue;
            }

            if ($this->instantiate($this->attributeOf($other))?->root === $declared->root) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{registry: string, root: string, package: string|null, provider: string|null, file: string|null, line: int|null, failures: list<string>}  $row
     */
    protected function detail(array $row, string $check): string
    {
        return sprintf(
            '[%s] %s%s — %s',
            $check,
            $this->shortName($row['registry']),
            $this->where($row),
            $this->remedy($row, $check),
        );
    }

    /**
     * @param  array{registry: string, root: string, package: string|null, provider: string|null, file: string|null, line: int|null, failures: list<string>}  $row
     */
    protected function where(array $row): string
    {
        if ($row['file'] !== null && $row['line'] !== null) {
            return sprintf(' (bound at %s:%d)', $row['file'], $row['line']);
        }

        // The degraded form: `nikic/php-parser` absent, or the binding made somewhere no provider file
        // shows it. Naming the provider class alone is still enough to find the binding by hand (14 D7).
        return $row['provider'] === null ? '' : sprintf(' (bound by %s)', $this->shortName($row['provider']));
    }

    /**
     * @param  array{registry: string, root: string, package: string|null, provider: string|null, file: string|null, line: int|null, failures: list<string>}  $row
     */
    protected function remedy(array $row, string $check): string
    {
        return match ($check) {
            self::CHECK_CONTRACT => sprintf(
                'declares #[IsRegistry] but does not implement %s, so it cannot be described into the index '.
                'and nothing can resolve a key through it. Hold a BasicRegistry as a field and forward the '.
                'seven methods — see registry-kernel tickets 37/38.',
                Registry::class,
            ),
            self::CHECK_ROOT => 'declares no legal root. The root is a dotted, Key-legal branch of the '.
                'keyspace, domain-first and vendor-free (`beam.realm.overlays`, `schemas.sources`) and never '.
                'derived from the composer coordinate. The empty root means the WHOLE tree and belongs to '.
                'RegistryIndex alone.',
            self::CHECK_ROOT_COLLISION => sprintf(
                'declares root `%s`, which another registry in this composition also declares. Two registries '.
                'on one root make that branch unroutable — the index rejects the second at describe() time, '.
                'so this is the same defect caught before a boot instead of during one.',
                $row['root'],
            ),
            self::CHECK_ARITY => 'writes no `arity:`. Arity is how many entries a read engages OUT '.
                '(PickOne / ComposeMany / RunAll) and it is what the index renders — a consumer reads it to '.
                'know whether it is looking at a lookup table or a pipeline.',
            self::CHECK_ON_DUPLICATE => 'writes no `onDuplicate:`, so it inherits Supersede silently. The '.
                'estate ships all three policies with argued docblocks, so an unwritten one is a guess that '.
                'reads as a decision. Write the one you mean, even where it is Supersede.',
            default => 'fails an unnamed check.',
        };
    }

    /**
     * Whether this check produces a doctor finding at all. See {@see CONTRACT_GATES}.
     *
     * A non-gating check cannot be expressed as a `warn()` HERE, and that is a mechanical fact rather than a
     * preference: this audit is registered `gate: true`, and on a gating audit a `warn` still fails the
     * runner at a lowered `--floor`. There is no severity on a gate that only reports. So the burn-down goes
     * where the estate already puts burn-downs — the committed artifact and
     * `splicewire:beam:registry-conformance`, which report it in full — and the doctor gates only what it
     * can gate today. That is ticket 12's rule ("gate what is statically decidable; report what is runtime
     * state") applied to a state that is decidable but not yet TRUE.
     *
     * Nothing is hidden by this: {@see declarations()} still runs every check, so the artifact's
     * `non_conforming` block and the `conforming` count both see it, and the PASS message above names the
     * number it is not gating on.
     */
    protected function gates(string $check): bool
    {
        return $check !== self::CHECK_CONTRACT || self::CONTRACT_GATES;
    }

    protected function attributeOf(?string $fqcn): ?ReflectionAttribute
    {
        if ($fqcn === null || (! class_exists($fqcn) && ! interface_exists($fqcn))) {
            return null;
        }

        try {
            $attributes = (new ReflectionClass($fqcn))->getAttributes(IsRegistry::class);
        } catch (\Throwable) {
            return null;
        }

        return $attributes[0] ?? null;
    }

    /**
     * The arguments the author WROTE, normalized so a positional declaration reads the same as a named one.
     *
     * @return array<string, mixed>
     */
    protected function writtenArguments(ReflectionAttribute $attribute): array
    {
        $written = [];

        foreach ($attribute->getArguments() as $key => $value) {
            $name = is_int($key) ? (self::ARGUMENT_POSITIONS[$key] ?? (string) $key) : $key;
            $written[$name] = $value;
        }

        return $written;
    }

    /**
     * The declaration as a value, or null where it will not instantiate — a required slot omitted, or an
     * argument of the wrong type. Reported as a root/arity failure by its absence rather than thrown: an
     * audit that fatals on the one malformed declaration reports nothing about the other forty-nine.
     */
    protected function instantiate(?ReflectionAttribute $attribute): ?IsRegistry
    {
        if ($attribute === null) {
            return null;
        }

        try {
            $instance = $attribute->newInstance();
        } catch (\Throwable) {
            return null;
        }

        return $instance instanceof IsRegistry ? $instance : null;
    }

    /**
     * Where this registry is bound, for the finding's `file:line`.
     *
     * Scoped to the host's LOADED providers rather than a tree walk: that is a few dozen files, it is the
     * set that can actually have made the binding this audit read out of the container, and it keeps an
     * enrichment from costing more than the check it enriches.
     *
     * @return array{provider: string|null, file: string|null, line: int|null}
     */
    protected function locate(string $fqcn): array
    {
        foreach ($this->providerFiles() as $provider => $file) {
            $source = (string) @file_get_contents($file);

            if (! str_contains($source, $this->shortName($fqcn))) {
                continue;
            }

            $line = $this->bindingLine($source, $fqcn);

            if ($line !== null) {
                return ['provider' => $provider, 'file' => $file, 'line' => $line];
            }

            // Degraded: the short name is in this provider's source but no parser could confirm the binding
            // site. Naming the provider still locates it by hand.
            return ['provider' => $provider, 'file' => null, 'line' => null];
        }

        return ['provider' => null, 'file' => null, 'line' => null];
    }

    /**
     * @return array<string, string> provider FQCN => file
     */
    protected function providerFiles(): array
    {
        $files = [];

        foreach (array_keys($this->app->getLoadedProviders()) as $provider) {
            if (! is_string($provider) || ! class_exists($provider)) {
                continue;
            }

            $file = (new ReflectionClass($provider))->getFileName();

            if (is_string($file)) {
                $files[$provider] = $file;
            }
        }

        return $files;
    }

    /**
     * The line of the container binding whose FIRST argument is this registry — the AST half, guarded on
     * `nikic/php-parser` being installed and returning null (not throwing, not guessing) when it is not.
     * A `strpos` on the short name cannot answer this: the same name appears in imports, docblocks and
     * type-hints, and reporting a docblock's line as a binding site is worse than reporting no line.
     */
    protected function bindingLine(string $source, string $fqcn): ?int
    {
        if (! class_exists(ParserFactory::class)) {
            return null;
        }

        try {
            $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($source);
        } catch (\Throwable) {
            return null;
        }

        if ($ast === null) {
            return null;
        }

        $namespace = $this->namespaceOf($ast);
        $imports = $this->importsOf($ast);
        $finder = new NodeFinder;

        /** @var list<MethodCall|StaticCall> $calls */
        $calls = $finder->find($ast, fn (Node $n) => $n instanceof MethodCall || $n instanceof StaticCall);

        foreach ($calls as $call) {
            if (! $call->name instanceof Node\Identifier
                || ! in_array($call->name->toString(), UndescribedRegistryAudit::BINDING_METHODS, true)
                || $call->args === []) {
                continue;
            }

            if ($this->classStringArg($call->args[0] ?? null, $namespace, $imports) === $fqcn) {
                return $call->getStartLine();
            }
        }

        return null;
    }

    /**
     * A `Foo::class` argument, resolved to an FQCN through the file's imports. Mirrors
     * {@see UndescribedRegistryAudit}'s own — deliberately not extracted into a shared helper: that audit's
     * copy is load-bearing for a gate, and a shared base class between two audits with different populations
     * is the coupling ticket 08 spent a whole ticket refusing.
     *
     * @param  array<string, string>  $imports
     */
    protected function classStringArg(?Node\Arg $arg, string $namespace, array $imports): ?string
    {
        if (! $arg instanceof Node\Arg
            || ! $arg->value instanceof ClassConstFetch
            || ! $arg->value->name instanceof Node\Identifier
            || $arg->value->name->toString() !== 'class'
            || ! $arg->value->class instanceof Node\Name) {
            return null;
        }

        $name = ltrim($arg->value->class->toString(), '\\');

        if (in_array($name, ['self', 'static', 'parent'], true)) {
            return null;
        }

        $head = explode('\\', $name)[0];

        if (isset($imports[$head])) {
            return $imports[$head].substr($name, strlen($head));
        }

        return str_contains($name, '\\') || $namespace === '' ? $name : $namespace.'\\'.$name;
    }

    /**
     * @param  Node[]  $ast
     * @return array<string, string>
     */
    protected function importsOf(array $ast): array
    {
        /** @var UseItem[] $uses */
        $uses = (new NodeFinder)->findInstanceOf($ast, UseItem::class);

        $imports = [];

        foreach ($uses as $use) {
            $fqn = $use->name->toString();
            $imports[$use->alias?->toString() ?? $this->shortName($fqn)] = $fqn;
        }

        return $imports;
    }

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
     * The composer package a declared registry belongs to. DERIVED from where the class file sits, never a
     * declared field: a declared one beside the thing it describes drifts and a derived one cannot (ticket
     * 07 D4). Delegated rather than reimplemented so the gate and the advisory report can never attribute
     * the same class to two different packages.
     */
    protected function packageOf(string $fqcn): ?string
    {
        $file = $this->attributeOf($fqcn) === null ? null : (new ReflectionClass($fqcn))->getFileName();

        return is_string($file) ? UndescribedRegistryAudit::packageOfPath($file) : null;
    }

    protected function shortName(string $fqn): string
    {
        $pos = strrpos($fqn, '\\');

        return $pos === false ? $fqn : substr($fqn, $pos + 1);
    }
}
