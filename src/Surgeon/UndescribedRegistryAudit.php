<?php

namespace Splicewire\Beam\Surgeon;

use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\UseItem;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Rushing\Popcorn\Discovery\AttributedClassScanner;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\RegistryArity;
use Rushing\Popcorn\Registries\RegistryIndex;
use Splicewire\Beam\Doctor;
use Splicewire\Beam\Doctor\RegistryConformanceAudit;
use Splicewire\Beam\Doctor\UndeclaredRegistryShapeAudit;

/**
 * The **meta-audit** (particle-doctrine-convergence, ticket 13): every registry-shaped container singleton
 * must declare itself with {@see IsRegistry}.
 *
 * ## ⚠️ Interim state — registry-kernel ticket 21 moved the goalposts, ticket 35 rebuilds this
 *
 * The obligation used to be *"call `describe(new ManifestDescriptor(...))` from your provider"*, and it was
 * checked against a live index's membership. Ticket 21 deleted that vocabulary: a registry now DECLARES
 * itself with `#[IsRegistry]` on the class, and separately gets INDEXED once it conforms to the `Registry`
 * contract — two acts, deliberately, because declaration lands before conformance across a 79-row migration
 * (ticket 14 D10's three-valued disposition exists precisely for the gap).
 *
 * So this audit now asks the DECLARATION question, which is the half that is answerable today, and it is
 * strictly better at it: matching moved off the descriptor's short `name` onto the attribute itself, which
 * kills the live defect where all three estate classes called `CapabilityRegistry` reported described by
 * colliding with each other. (Two of the three still carry the name; the third was renamed to
 * `Splicewire\Tower\Circuit\Capabilities\CapabilityLadder` by registry-kernel ticket 44. The defect was
 * never fixed by the rename — it was fixed by matching on the attribute.)
 *
 * **The membership ratchet below is vacuous until owners start describing into `RegistryIndex`.** Roots are
 * derived from index membership, and the index fills as ticket 37/38's migrations land — so a PASS from this
 * audit today means "nothing is governed yet", not "the estate is clean". Do not read it as the latter, and
 * do not paper over it here: {@see Doctor} gets
 * {@see RegistryConformanceAudit} (gating, population = classes carrying the
 * attribute) and {@see UndeclaredRegistryShapeAudit} (advisory, carrying every
 * judgement call) from registry-kernel ticket 35, which supersedes this class's scoping question rather than
 * patching it. The second of those CONSUMES this class's structural test rather than copying it — see
 * {@see forHost()}.
 *
 * ## It lives in `Surgeon/` and registers on the DOCTOR channel — that is not a contradiction
 *
 * Ticket 35 §4 moved this audit's registration into `BeamServiceProvider`'s unconditional doctor block,
 * because it was the estate's only gate and it was silently absent from every host that did not install
 * `rushing/laravel-surgeon` (a `require-dev` of beam) — enforcement as a function of host composition, which
 * is ticket 04 D1's defect wearing a different hat. The directory is a location, not a channel claim: it
 * never was one, and {@see UndeclaredSurfaceAudit} beside it is a pure `DoctorAudit` too. Only the
 * registration moved; a namespace move would have been a rename with no reader-facing gain.
 *
 * ## Unconditional means it must survive `nikic/php-parser` being absent
 *
 * That library arrives through surgeon, so an unconditional registration now runs in hosts that have it and
 * hosts that do not. Detection here is genuinely AST-shaped — a container binding CALL SITE is not a runtime
 * fact, which is why ticket 14 D2's move-detection-to-the-container applies to the ticket-35 pair and not to
 * this one — so where the parser is missing this audit reports its own blindness rather than a clean estate
 * ({@see detectionAvailable()}). A gate that returns "all clear" because it could not look is worse than one
 * that fails.
 *
 * ## Why this one is a GATE when everything else in the effort is advisory
 * Every other check in this initiative reports a backlog, and a backlog that fails the build is just a
 * blocked build. This one is different in kind: `popcorn:registries --json` is what an agent is
 * *told to run* as its entry point for "where do I register this". An undeclared registry is one no
 * catalogue can show, and an agent that cannot find a registry builds a parallel mechanism next to it. So
 * the cost of the drift is not a stale document, it is a second implementation of something that already
 * exists — the exact failure the rest of this effort is paying to prevent. It is also the cheapest check
 * here (one attribute fixes any finding), so it is the one place where blocking is proportionate. Registered `gate: true` in `BeamServiceProvider::registerRegistryConformanceAudits()`, and every
 * finding is a {@see Finding::fail()} so it blocks at the runner's DEFAULT floor rather than only at a
 * lowered one.
 *
 * ## The scope is the index's own membership — the ratchet
 * `forIndex()` derives its scan roots from the registries ALREADY in the index: a package that has
 * described at least one registry is **opted in**, and is then held to declaring all of them. A package
 * that has never described anything is not scanned.
 *
 * This is deliberate and it is what makes a gate survivable. The estate has ~55 registry-shaped singletons
 * across ~29 packages against 20 descriptors; a gate over all of them would hard-block every host in the
 * fleet on day one and be switched off within the hour. Scoping to membership inverts that: joining the
 * index is a commitment to completeness, the governed set only ever grows, and the *incomplete* state a
 * package can be in — "I described my headline registry and quietly left four others out" — is precisely
 * the state that makes the index untrustworthy to read. An index you cannot trust to be complete for the
 * packages it lists is not an index. A package that is absent entirely at least does not lie.
 *
 * ## What "registry-shaped" means, structurally
 * Not the class NAME. The estate's `Registry`/`Manifest`/`Pipeline`/`Store` suffixes are inconsistent (see
 * {@see RegistryArity}'s docblock on exactly that), and blockdoc's node-type
 * registry is bound under the interface `Contracts\Schema` — a name-based test would miss it while
 * flagging every `SchemaTypeProjector` in the fleet. The test is instead the shape a registry actually has:
 *
 *   1. it is bound as a **container singleton** (`singleton()`/`scoped()`) from a service provider —
 *      shared, accumulating state rather than a per-resolution value object;
 *   2. it holds **plural state** — an array-typed/array-defaulted property, or an array-typed constructor
 *      parameter (the config-source and constructor-policy seams are seeded, not `register()`ed into);
 *   3. it exposes an **entry path** (a public write-verb method, or that array constructor parameter) AND a
 *      **lookup path** (a public read-verb method). Both halves are required: a thing you can only put into
 *      is a sink, a thing you can only read from is a value.
 *
 * Verbs match on a camelCase prefix, so `resolveGenerate()`/`hasGround()` count — `HandlerRegistry` names
 * its lookups after what they look up, which a whole-name match would miss.
 *
 * ## Criterion 2 is blind to a registry whose store is EXTERNAL — the fourth signal
 *
 * "Plural state" means an array *in the object*, and that is a claim about where a registry keeps its
 * entries, not about whether it has any. A registry backed by a filesystem, a table or a remote service
 * holds a DIRECTORY PATH or a CONNECTION and nothing plural at all — its keyspace is real, it is simply not
 * in memory. Criterion 2 reads that as "not a registry" and says so silently, which is the failure mode this
 * whole effort exists to end.
 *
 * Measured, and this is the case the fourth signal is written against:
 * `schemastud/laravel-data-schemas` is a GOVERNED package (three of its classes carry `#[IsRegistry]`, so
 * the ratchet holds it to declaring all of them), and its provider binds
 * `Contracts\SchemaRegistry` => `new FilesystemSchemaRegistry($dir)`. That class holds
 * `protected string $directory`, takes `register(array $schema)` in and hands `get()`/`has()`/`ids()` back,
 * and stores one JSON file per `$id` on disk. It appeared NOWHERE in `.beam/registry-conformance.json` —
 * not conforming, not outstanding, not even unaccounted — while the artifact reported `unaccounted: 0`. The
 * map's completeness test is `outstanding == 0 && unaccounted == 0`, so a structurally uncountable class of
 * registry defeats it while looking clean.
 *
 * Two things were wrong, and both had to be fixed before that row could exist at all:
 *
 *   - **The concrete was unreadable.** Both of those bindings pass a CLOSURE, and the second argument was
 *     only ever read as a `Foo::class` literal. With no concrete the shape was tested against the ABSTRACT,
 *     an interface has no properties, and it fell out at the very first check. See
 *     {@see concreteFromClosureArg()}: a closure whose returned expression is a single `new X(...)` or
 *     `X::make(...)` names its concrete as plainly as a class-string does, and nothing else is read out of
 *     a closure body.
 *   - **The shape test itself.** Hence criterion 4, an ALTERNATIVE to criterion 2 that relaxes nothing else.
 *     A class with no plural state qualifies only when ALL of these hold:
 *       (a) it satisfies criterion 3 by METHODS — a public write verb AND a public read verb (the
 *           array-constructor entry path is definitionally unavailable here);
 *       (b) the container ABSTRACT it is bound under is an interface (or abstract class) that this class
 *           implements, and that lives in the SAME composer package as the class — the owning package
 *           declared a swappable contract KEY for this keyspace, which a value object never gets;
 *       (c) its constructor takes an **external store handle**: a `string` parameter named for a store
 *           location ({@see STORE_PARAM_NAMES}), or a parameter typed as a filesystem/database/cache handle
 *           ({@see STORE_PARAM_TYPES}). A no-argument constructor does not qualify — something has to say
 *           WHERE the entries live, and that something is the store.
 *
 * ### The precision/recall trade, stated
 *
 * This is tuned hard for PRECISION and it under-recalls on purpose. Every row here lands in a gate's
 * population and in a committed artifact, and a gate that cries wolf is a gate switched off within the week
 * — the same argument the membership ratchet above is made from. Condition (b) is the expensive half of
 * that: an external-store registry a package binds under its own CONCRETE class name, or under a contract
 * living in another package, is invisible to this signal and stays invisible. That is a known, deliberate
 * miss. It is tolerable because (b) is the only cheap thing that separates "a shared keyspace someone
 * thought worth a contract" from "a small class with a setter, a getter and a path argument", of which the
 * estate has many and none of which are registries. A miss is recoverable by widening later; a false row is
 * a gate nobody trusts. Widen (b) before (a) or (c), and only with a measured case in hand.
 *
 * ## Why not RegistrationDriftDetector
 * `Rushing\Surgeon\Audit\RegistrationDriftDetector` is the estate's diff-a-registry engine and was the
 * obvious seed, but its diff is *attribute-scan-shaped*: `detect($scanPaths, $attributeClass, $manual)`
 * asks an {@see AttributedClassScanner} which classes carry an attribute and
 * diffs that against a manual registration list. Both of this audit's sides are something else — the
 * discovered side is container binding *call sites* and the declared side is `#[IsRegistry]` — so half of
 * it now IS an attribute scan, but only half, and the halves are asymmetric in exactly the way that engine
 * cannot express. Its `RegistrationDrift` DTO could be borrowed to carry the
 * diff, but its `toFindings()` emits `warn` with "registered manually but has no attribute" text and no
 * slot for the provider name this audit's findings are required to carry, so borrowing it would mean
 * emitting a strictly worse finding to reuse an `array_diff`. Stated here so the next reader does not
 * re-derive the question.
 *
 * ## The finding names the registry, and the provider only to locate it
 * The remedy is now unambiguous — put `#[IsRegistry]` on the class that owns the keyspace — so the finding
 * leads with that. It still names the binding provider and its `file:line`, because that is how a reader
 * FINDS the class, not because the provider is where the fix goes. That inverted with ticket 21: under the
 * old descriptor the provider was the only legal home for the fix; under the attribute the class is.
 */
class UndescribedRegistryAudit implements DoctorAudit
{
    public const CHECK = 'manifest.undescribed-registry';

    /**
     * Container methods that produce a SHARED instance. `bind()` is deliberately absent: a per-resolution
     * binding cannot accumulate registrations, so it is a factory, not a registry.
     *
     * @var list<string>
     */
    public const BINDING_METHODS = ['singleton', 'scoped', 'singletonIf', 'scopedIf'];

    /**
     * Method-name prefixes for an ENTRY path — how a host gets something INTO the registry.
     *
     * @var list<string>
     */
    public const WRITE_VERBS = [
        'register', 'describe', 'add', 'append', 'push', 'put', 'extend', 'define', 'declare', 'record',
        'override', 'load', 'seed', 'attach', 'mount', 'set', 'with',
    ];

    /**
     * Method-name prefixes for the read path — either a LOOKUP (`get`/`resolve`/`for`) or, for a
     * compose-many registry, an ENGAGEMENT of the whole chain.
     *
     * The engagement half is not optional. blockdoc's `TransformPipeline` is a textbook singleton registry —
     * `register(class)` in, ordered `array $transforms` inside — and its only read is `transform($doc)`,
     * which *runs* the entries rather than handing one back. A lookup-only verb list reads that as
     * not-a-registry, which is precisely the registry the ticket names.
     *
     * @var list<string>
     */
    public const READ_VERBS = [
        'for', 'get', 'all', 'has', 'find', 'resolve', 'lookup', 'names', 'keys', 'entries', 'only',
        'descriptors', 'registrations', 'sources', 'steps', 'default',
        'apply', 'transform', 'process', 'pipe', 'through', 'run', 'walk', 'compose',
    ];

    /**
     * Constructor parameter NAMES that, on a `string` parameter, say "the entries live over there" — the
     * external-store half of criterion 4 (see the class docblock). Lower-cased on both sides at the compare.
     *
     * Deliberately short and location-shaped. `$name`, `$key`, `$id` and friends are absent on purpose: they
     * name the thing itself rather than where a keyspace is kept, and admitting them would make criterion 4
     * fire on most of the estate's small value objects.
     *
     * @var list<string>
     */
    public const STORE_PARAM_NAMES = [
        'directory', 'directories', 'dir', 'path', 'basepath', 'root', 'rootpath',
        'disk', 'filesystem', 'table', 'connection', 'bucket', 'store', 'storage',
    ];

    /**
     * Constructor parameter TYPES that ARE an external store handle, matched including subclasses and
     * implementations. A class holding one of these is holding somebody else's storage.
     *
     * @var list<string>
     */
    public const STORE_PARAM_TYPES = [
        'Illuminate\\Contracts\\Filesystem\\Filesystem',
        'Illuminate\\Filesystem\\Filesystem',
        'Illuminate\\Database\\ConnectionInterface',
        'Illuminate\\Database\\Eloquent\\Model',
        'Illuminate\\Contracts\\Cache\\Repository',
        'Illuminate\\Contracts\\Redis\\Connection',
        'League\\Flysystem\\FilesystemOperator',
        'Psr\\SimpleCache\\CacheInterface',
    ];

    /**
     * Path fragments excluded wholesale — test scaffolding and vendored non-family trees. A fixture provider
     * binding a fake registry is scaffolding for a test OF this mechanism, not an estate registry.
     *
     * Constructor-injectable, and injected as `[]` by this audit's own tests: a conformance assertion made
     * from inside a tree the audit excludes is vacuous, so the tests plant their violation somewhere the
     * audit can actually see it.
     *
     * @var list<string>
     */
    public const DEFAULT_EXCLUDED_PATHS = [
        '/tests/',
        '/test/',
        '/fixtures/',
        '/stubs/',
        '/node_modules/',
    ];

    public function __construct(
        /** @var list<string> */
        protected array $roots,
        protected RegistryIndex $index,
        /** @var list<string> */
        protected array $excludedPaths = self::DEFAULT_EXCLUDED_PATHS,
    ) {}

    /**
     * The live index this scan is measured against.
     *
     * Exposed because {@see UndeclaredRegistryShapeAudit} consumes this audit and
     * must answer the same question about the same index — *is this class governed by the gate?* — and
     * under registry-kernel 26 D5 being DESCRIBED is one of the two ways to be (ticket 49). Handing it a
     * second `RegistryIndex` through its own constructor would let the two audits disagree about which
     * index they mean, which is the defect this whole effort keeps finding.
     */
    public function index(): RegistryIndex
    {
        return $this->index;
    }

    /**
     * The governed wiring: scan roots derived from the index's OWN membership (see the class docblock's
     * ratchet argument). A registry owned by a package contributes that package's `src/` under the host's
     * vendor dir; an app-local one contributes the host's own `app/`/`src/`.
     *
     * `vendor/<pkg>/src` rather than `vendor/<pkg>` on purpose: family packages each carry their own dev
     * `vendor/` tree, and descending into those re-scans the whole estate once per package.
     *
     * @param  list<string>|null  $roots
     */
    public static function forIndex(RegistryIndex $index, ?array $roots = null): self
    {
        return new self($roots ?? self::governedRoots($index), $index);
    }

    /**
     * The same structural scan over the WHOLE host composition rather than the index's membership — what
     * {@see UndeclaredRegistryShapeAudit} is handed (registry-kernel ticket 35 §2).
     *
     * The two scopes are the point of having two audits. `forIndex()` is narrow because it feeds a GATE and
     * the ratchet is what makes a gate survivable; this one is wide because a report that only looks where
     * someone already opted in cannot report what nobody has opted into. Same verbs, same exclusions, same
     * ownership rule — one implementation, so the two can never disagree about what a registry looks like.
     *
     * Roots are the host's own source plus every installed family package, **expanded one level and
     * resolved**. Handing in `vendor/<vendor>` whole — which is what
     * {@see CentralPinJustificationAudit::forApp()} does — reports almost nothing in a co-dev tree, and
     * silently: `RecursiveDirectoryIterator` does not follow symlinks, and in a co-dev host every
     * `vendor/<vendor>/<package>` IS a symlink to the working checkout. Measured before this expansion was
     * added, a whole-host scan of `splicewire-app` produced ONE row. That is the failure mode this effort
     * exists to end, reproduced inside its own audit: an empty report and an unread one look identical.
     *
     * `<pkg>/src` is preferred over `<pkg>` for {@see governedRoots()}'s reason — each family package
     * carries its own dev `vendor/` tree, and descending into those re-scans the estate once per package.
     *
     * @param  list<string>|null  $roots
     */
    public static function forHost(RegistryIndex $index, ?array $roots = null): self
    {
        if ($roots !== null) {
            return new self($roots, $index);
        }

        $found = [];

        foreach (['app', 'src'] as $dir) {
            if (is_dir($path = base_path($dir))) {
                $found[(string) realpath($path)] = true;
            }
        }

        foreach (['rushing', 'schemastud', 'splicewire'] as $vendor) {
            foreach ((array) glob(base_path('vendor/'.$vendor.'/*'), GLOB_ONLYDIR) as $package) {
                $resolved = realpath((string) $package.'/src') ?: realpath((string) $package);

                if (is_string($resolved)) {
                    $found[$resolved] = true;
                }
            }
        }

        return new self(array_keys($found), $index);
    }

    /**
     * Whether this host can actually run the detection. See the class docblock: `nikic/php-parser` arrives
     * through `rushing/laravel-surgeon`, which is a `require-dev` of beam, and this audit is registered
     * unconditionally — so "no parser" is a live state in a production host rather than a hypothetical.
     */
    public function detectionAvailable(): bool
    {
        return class_exists(ParserFactory::class);
    }

    /**
     * The owning package is now DERIVED from where the owner's class file sits, not read off a hand-written
     * `package:` field — registry-kernel ticket 07 D4's rule (a declared field beside the thing it describes
     * drifts; a derived one cannot). It reads the index UNFILTERED: scan scope is a structural question, and
     * an authorizer narrowing it would silently shrink what the gate governs.
     *
     * @return list<string>
     */
    public static function governedRoots(RegistryIndex $index): array
    {
        $roots = [];
        $unfiltered = $index->unfiltered();

        foreach ($unfiltered->keys() as $key) {
            // The index describes ITSELF at construction under the zero-segment root (ticket 20 D4). That is
            // a structural property of self-hosting, not a package opting into the ratchet — counting it
            // would mean a freshly-constructed index governs something, and in a co-dev tree (where the
            // kernel's path carries no `vendor/` segment) that something is the host's own `app/`.
            if ((string) $key === '') {
                continue;
            }

            $owner = $index->owner($key);
            $file = $owner === null ? null : (new ReflectionClass($owner))->getFileName();
            $package = $file === false || $file === null ? null : self::packageOfPath($file);

            if ($package === null) {
                foreach (['app', 'src'] as $dir) {
                    if (is_dir($path = base_path($dir))) {
                        $roots[$path] = true;
                    }
                }

                continue;
            }

            $dir = base_path('vendor/'.$package);

            if (is_dir($dir.'/src')) {
                $roots[$dir.'/src'] = true;
            } elseif (is_dir($dir)) {
                $roots[$dir] = true;
            }
        }

        return array_keys($roots);
    }

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        if (! $this->detectionAvailable()) {
            // Warn, not fail: the host is not misconfigured, it simply composed beam without the dev
            // dependency that carries the parser. Warn, not PASS: a gate reporting "all clear" because it
            // could not look is the failure mode this whole effort is paying to remove.
            return [Finding::warn(self::CHECK, sprintf(
                'Registry declaration is UNCHECKED in this composition: %s detects container binding call '.
                'sites and nikic/php-parser (via rushing/laravel-surgeon) is not installed. This is not a '.
                'clean estate, it is an unread one.',
                self::class,
            ))];
        }

        $rows = $this->registries();
        $undescribed = array_values(array_filter($rows, fn (array $row) => ! $row['described']));

        if ($undescribed === []) {
            return [Finding::pass(self::CHECK, sprintf(
                'All %d registry-shaped singleton(s) in the governed packages declare themselves with #[IsRegistry].',
                count($rows),
            ))];
        }

        return array_map(
            fn (array $row) => Finding::fail(self::CHECK, $this->detail($row)),
            $undescribed,
        );
    }

    /**
     * Every registry-shaped singleton found under the roots, as sorted rows — the work-list.
     *
     * Sorted by registry FQCN so a re-run with no code change reports in a byte-identical order;
     * filesystem iteration order is not stable enough to compare against.
     *
     * @return list<array{registry: string, concrete: string|null, provider: string, package: string|null, file: string, line: int, described: bool}>
     */
    public function registries(): array
    {
        $rows = [];

        foreach ($this->roots as $root) {
            foreach ($this->phpFiles($root) as $file) {
                if ($this->isExcludedPath($file)) {
                    continue;
                }

                foreach ($this->bindingsInSource((string) file_get_contents($file), $file) as $row) {
                    // FIRST binding site wins, and the roots arrive foundation-first (they are derived in
                    // descriptor order), so the site reported is the owning package's provider rather than
                    // the host's re-binding of the same registry. One row per registry either way: one
                    // `describe(...)` call answers for every binding of it.
                    $rows[$row['registry']] ??= $row;
                }
            }
        }

        ksort($rows);

        return array_values($rows);
    }

    /**
     * The work-list proper: registry-shaped singletons absent from the index.
     *
     * @return list<array{registry: string, concrete: string|null, provider: string, package: string|null, file: string, line: int, described: bool}>
     */
    public function undescribed(): array
    {
        return array_values(array_filter($this->registries(), fn (array $row) => ! $row['described']));
    }

    /**
     * Parse ONE file's registry-shaped singleton bindings. Pure over source apart from the reflection the
     * shape test needs — unit-callable with no container and no DB.
     *
     * @return list<array{registry: string, concrete: string|null, provider: string, package: string|null, file: string, line: int, described: bool}>
     */
    public function bindingsInSource(string $source, string $file = ''): array
    {
        if ($file !== '' && $this->isExcludedPath($file)) {
            return [];
        }

        $ast = $this->parse($source);

        if ($ast === null) {
            return [];
        }

        $namespace = $this->namespaceOf($ast);
        $imports = $this->importsOf($ast);
        $finder = new NodeFinder;
        $rows = [];

        /** @var list<Node\Stmt\Class_> $classes */
        $classes = $finder->findInstanceOf($ast, Node\Stmt\Class_::class);

        foreach ($classes as $class) {
            if ($class->name === null || ! $this->isServiceProvider($class)) {
                continue;
            }

            $provider = ($namespace !== '' ? $namespace.'\\' : '').$class->name->toString();

            /** @var list<MethodCall|StaticCall> $calls */
            $calls = $finder->find($class->stmts, fn (Node $n) => $n instanceof MethodCall || $n instanceof StaticCall);

            foreach ($calls as $call) {
                if (! $call->name instanceof Node\Identifier
                    || ! in_array($call->name->toString(), self::BINDING_METHODS, true)
                    || $call->args === []) {
                    continue;
                }

                $abstract = $this->classStringArg($call->args[0] ?? null, $namespace, $imports);

                if ($abstract === null) {
                    continue;
                }

                $concrete = $this->classStringArg($call->args[1] ?? null, $namespace, $imports)
                    ?? $this->concreteFromClosureArg($call->args[1] ?? null, $namespace, $imports);

                if (! $this->isRegistryShaped($abstract, $concrete) || ! $this->isOwned($abstract, $concrete)) {
                    continue;
                }

                $rows[] = [
                    'registry' => $abstract,
                    'concrete' => $concrete === $abstract ? null : $concrete,
                    'provider' => $provider,
                    'package' => $this->packageOf($file),
                    'file' => $file,
                    'line' => $call->getStartLine(),
                    'described' => $this->isDescribed($abstract, $concrete),
                ];
            }
        }

        return $rows;
    }

    /**
     * The finding text. It names the registry, the PROVIDER that must describe it, and the binding's
     * file:line, and it spells out the one-call remedy — the author who needs this message is the author who
     * does not have the `describe(...)` signature in front of them.
     *
     * @param  array{registry: string, concrete: string|null, provider: string, package: string|null, file: string, line: int, described: bool}  $row
     */
    protected function detail(array $row): string
    {
        return sprintf(
            '%s is a registry-shaped singleton bound at %s:%d but declares no #[IsRegistry]. Put '.
            '#[IsRegistry(root: ..., of: ..., arity: RegistryArity::...)] on %s itself — the declaration '.
            'belongs on the class that owns the keyspace, not on the provider (%s) that binds it. The root '.
            'is a dotted key, domain-first and vendor-free (`beam.realm.overlays`, `schemas.sources`), never '.
            'derived from the composer coordinate. An undeclared registry is one `popcorn:registries` cannot '.
            'show, so an agent looking for where to register builds a parallel mechanism beside it.',
            $this->shortName($row['registry']),
            $row['file'],
            $row['line'],
            $this->shortName($row['registry']),
            $this->shortName($row['provider']),
        );
    }

    /**
     * The structural registry test (see the class docblock). Plural state on the CONCRETE (an interface has
     * no properties, so `singleton(Schema::class, NodeSchema::class)` must be shaped against `NodeSchema`),
     * verbs from either side (a host codes against the interface, so its methods count too).
     */
    public function isRegistryShaped(string $abstract, ?string $concrete = null): bool
    {
        $abstractReflection = $this->reflect($abstract);
        $concreteReflection = $concrete !== null && $concrete !== $abstract ? $this->reflect($concrete) : null;
        $shape = $concreteReflection ?? $abstractReflection;

        if ($shape === null || $shape->isInterface() || $shape->isAbstract()) {
            // No inspectable concrete — an interface bound to a closure, or a class this host does not
            // autoload. No shape claim is made: a gate that fires on a class it could not read is a gate
            // that gets switched off. The cost is a miss, and a miss here is recoverable.
            return false;
        }

        $arrayProperty = $this->hasArrayState($shape);
        $arrayConstructorParam = $this->hasArrayConstructorParam($shape);
        $methods = $this->publicMethodNames($abstractReflection, $concreteReflection);

        if (! $arrayProperty && ! $arrayConstructorParam) {
            // Criterion 4: no plural state, but the entries are kept somewhere else. Both halves of
            // criterion 3 must come from METHODS here, and the contract/store evidence carries the weight
            // criterion 2 was carrying. See the class docblock for the precision trade this takes.
            return $this->matchesVerb($methods, self::WRITE_VERBS)
                && $this->matchesVerb($methods, self::READ_VERBS)
                && $this->isBoundUnderOwnContract($abstractReflection, $shape)
                && $this->hasExternalStoreConstructor($shape);
        }

        $hasEntry = $arrayConstructorParam || $this->matchesVerb($methods, self::WRITE_VERBS);
        $hasLookup = $this->matchesVerb($methods, self::READ_VERBS);

        return $hasEntry && $hasLookup;
    }

    /**
     * Criterion 4 (b): the container key is the owning package's OWN contract for this keyspace.
     *
     * The abstract must be an interface (or abstract class) the concrete implements, and it must live in the
     * same composer package as the concrete. Both halves matter. Declaring a contract and binding the
     * concrete behind it is a deliberate act that says "this key is a seam other code resolves through" —
     * a per-resolution value object never gets one. Requiring the SAME package keeps the signal on the
     * owner: a class implementing somebody else's interface is an adapter, and the ownership rule
     * ({@see isOwned()}) already says the estate does not gate what it cannot ask to declare itself.
     */
    protected function isBoundUnderOwnContract(?ReflectionClass $abstract, ReflectionClass $concrete): bool
    {
        if ($abstract === null
            || $abstract->getName() === $concrete->getName()
            || (! $abstract->isInterface() && ! $abstract->isAbstract())
            || ! $concrete->isSubclassOf($abstract->getName())) {
            return false;
        }

        $abstractFile = $abstract->getFileName();
        $concreteFile = $concrete->getFileName();

        if ($abstractFile === false || $concreteFile === false) {
            return false;
        }

        // `null === null` is a real match, not a degenerate one: it is the app-local case, where a host's own
        // contract and its own implementation both sit above the base path and neither carries a composer
        // coordinate. That is the same claim — one owner — and refusing it would exempt every app-local
        // registry from a signal the packages are held to.
        return self::packageOfPath($abstractFile) === self::packageOfPath($concreteFile);
    }

    /**
     * Criterion 4 (c): the constructor names an EXTERNAL STORE — where the entries actually live.
     *
     * A `string` parameter whose name is a store location ({@see STORE_PARAM_NAMES}), or a parameter typed
     * as a storage handle ({@see STORE_PARAM_TYPES}), including subclasses. A constructor with no parameters
     * fails: a registry with neither plural state nor a store handle keeps its entries nowhere, which means
     * it is not a registry.
     */
    protected function hasExternalStoreConstructor(ReflectionClass $class): bool
    {
        foreach ($class->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();

            if (! $type instanceof ReflectionNamedType) {
                continue;
            }

            if ($type->getName() === 'string'
                && in_array(strtolower($parameter->getName()), self::STORE_PARAM_NAMES, true)) {
                return true;
            }

            if ($this->isStoreHandleType($type->getName())) {
                return true;
            }
        }

        return false;
    }

    protected function isStoreHandleType(string $type): bool
    {
        foreach (self::STORE_PARAM_TYPES as $handle) {
            if ($type === $handle || is_subclass_of($type, $handle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this registry declares itself — `#[IsRegistry]` on the abstract or on the concrete.
     *
     * **This replaces a name match, and that is the point.** The old test compared the descriptor's `name`
     * against the registry's SHORT class name, so all three estate classes then called `CapabilityRegistry`
     * (beam's, tower's subclass of it, and the unrelated `Tower\Circuit\Capabilities` one — now
     * `CapabilityLadder`, registry-kernel ticket 44) satisfied each
     * other's obligation by collision — one descriptor answered for three registries. An attribute cannot
     * collide: it is either on this class or it is not.
     *
     * Both sides are checked because a host codes against the interface while the declaration belongs on
     * whatever owns the keyspace, and `singleton(Schema::class, NodeSchema::class)` legitimately puts those
     * on different classes. Attributes are NOT inherited through `getAttributes()`, so a subclass that
     * genuinely owns a different root must declare its own — which is the behaviour we want and the reason
     * tower's `Tower\Capabilities\CapabilityRegistry` subclass is a real finding here rather than a false one.
     */
    public function isDescribed(string $abstract, ?string $concrete = null): bool
    {
        foreach ([$abstract, $concrete] as $fqcn) {
            if ($fqcn !== null && $this->reflect($fqcn) !== null && IsRegistry::of($fqcn) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the estate OWNS this registry — its class file lies inside one of the governed roots.
     *
     * A provider legitimately binds third-party registries as singletons (splicewire-app binds Opis's
     * `SchemaResolver`, packages bind Laravel `Manager`s from other vendors), and those are registries by
     * every structural test. But the index's contract is "an owner describes itself from its own provider",
     * and you cannot ask `opis/json-schema` to describe itself — so an external class can only ever be
     * described *about*, by whoever happens to bind it. That is a nice-to-have index row, not a gating
     * obligation, and gating it would make the blast radius of this check a function of every host's
     * third-party binding habits. Ownership is therefore part of the governed scope, exactly as membership
     * is.
     */
    protected function isOwned(string $abstract, ?string $concrete = null): bool
    {
        foreach ([$abstract, $concrete] as $fqcn) {
            $file = $fqcn === null ? false : $this->reflect($fqcn)?->getFileName();

            if ($file === false || $file === null) {
                continue;
            }

            foreach ($this->roots as $root) {
                // Both forms compared: a co-dev host reaches its packages through `vendor/<pkg>` SYMLINKS,
                // and whether a path arrives resolved or unresolved depends on how it was included.
                if (str_starts_with($file, $root)
                    || str_starts_with((string) realpath($file), (string) realpath($root))) {
                    return true;
                }
            }
        }

        return false;
    }

    /** A class that binds into the container: anything whose parent chain is named `*ServiceProvider`. */
    protected function isServiceProvider(Node\Stmt\Class_ $class): bool
    {
        return $class->extends !== null
            && str_ends_with($this->shortName($class->extends->toString()), 'ServiceProvider');
    }

    /** A `Foo::class` argument, resolved to an FQCN through the file's imports. */
    protected function classStringArg(?Node\Arg $arg, string $namespace, array $imports): ?string
    {
        if (! $arg instanceof Node\Arg
            || ! $arg->value instanceof ClassConstFetch
            || ! $arg->value->name instanceof Node\Identifier
            || $arg->value->name->toString() !== 'class'
            || ! $arg->value->class instanceof Node\Name) {
            return null;
        }

        return $this->resolveName($arg->value->class->toString(), $namespace, $imports);
    }

    /**
     * A source-level class name resolved to an FQCN through the file's imports and namespace.
     *
     * Shared by the class-string and the closure-body readers so a `Foo::class` argument and a
     * `new Foo(...)` inside a closure can never resolve to two different classes.
     *
     * @param  array<string, string>  $imports
     */
    protected function resolveName(string $name, string $namespace, array $imports): ?string
    {
        $name = ltrim($name, '\\');

        if ($name === '' || in_array($name, ['self', 'static', 'parent'], true)) {
            return null;
        }

        $head = explode('\\', $name)[0];

        if (isset($imports[$head])) {
            return $imports[$head].substr($name, strlen($head));
        }

        return str_contains($name, '\\') || $namespace === '' ? $name : $namespace.'\\'.$name;
    }

    /**
     * The concrete a binding CLOSURE builds — `singleton(Contract::class, fn () => new Impl($dir))`.
     *
     * Deliberately shallow. Only the closure's returned expression is read, and only when it is a single
     * `new X(...)` or a static call `X::make(...)` on a resolvable name. A closure that branches, that
     * resolves out of the container, or that returns a variable names no concrete here and gets `null` —
     * the same answer this method gave for every closure before it existed. Anything cleverer is
     * dataflow analysis, and a shape test that guesses wrong produces a gate finding against a class the
     * provider never built.
     *
     * The static-call form is not decoration: `ServedSchemaChain::overDirectories($dirs)` is one of the two
     * bindings this whole signal was written for. A static call's RETURN type is not consulted — the class
     * the call is made ON is the answer, because a named constructor by estate convention returns its own
     * class (`static`/`self`), and following a declared return type would follow it into another package.
     *
     * @param  array<string, string>  $imports
     */
    protected function concreteFromClosureArg(?Node\Arg $arg, string $namespace, array $imports): ?string
    {
        if (! $arg instanceof Node\Arg) {
            return null;
        }

        $expr = null;

        if ($arg->value instanceof Node\Expr\ArrowFunction) {
            $expr = $arg->value->expr;
        } elseif ($arg->value instanceof Node\Expr\Closure) {
            /** @var Node\Stmt\Return_|null $return */
            $return = (new NodeFinder)->findFirstInstanceOf($arg->value->stmts, Node\Stmt\Return_::class);
            $expr = $return?->expr;
        }

        $class = match (true) {
            $expr instanceof Node\Expr\New_ => $expr->class,
            $expr instanceof StaticCall => $expr->class,
            default => null,
        };

        if (! $class instanceof Node\Name) {
            return null;
        }

        return $this->resolveName($class->toString(), $namespace, $imports);
    }

    /** An array-typed or array-defaulted instance property — the plural state a registry accumulates in. */
    protected function hasArrayState(?ReflectionClass $class): bool
    {
        if ($class === null) {
            return false;
        }

        foreach ($class->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }

            if ($this->isArrayType($property->getType()) || $this->hasArrayDefault($property)) {
                return true;
            }
        }

        return false;
    }

    /** The seeded shape: `new Registry(config('...'))` — a config-source/constructor-policy seam. */
    protected function hasArrayConstructorParam(?ReflectionClass $class): bool
    {
        foreach ($class?->getConstructor()?->getParameters() ?? [] as $parameter) {
            if ($this->isArrayType($parameter->getType())) {
                return true;
            }
        }

        return false;
    }

    protected function isArrayType(mixed $type): bool
    {
        return $type instanceof ReflectionNamedType && $type->getName() === 'array';
    }

    protected function hasArrayDefault(ReflectionProperty $property): bool
    {
        return $property->hasDefaultValue() && is_array($property->getDefaultValue());
    }

    /**
     * @return list<string>
     */
    protected function publicMethodNames(?ReflectionClass ...$classes): array
    {
        $names = [];

        foreach ($classes as $class) {
            foreach ($class?->getMethods(ReflectionMethod::IS_PUBLIC) ?? [] as $method) {
                if (! $method->isStatic() && ! $method->isConstructor()) {
                    $names[$method->getName()] = true;
                }
            }
        }

        return array_keys($names);
    }

    /**
     * Verb match on a camelCase PREFIX, not the whole name: `HandlerRegistry` names its lookups after what
     * they look up (`resolveGenerate`, `hasGround`), and a whole-name match would read that as no lookup at
     * all. The boundary check keeps `getter`-style false hits out (`format` is not `for` + `mat`).
     *
     * @param  list<string>  $methods
     * @param  list<string>  $verbs
     */
    protected function matchesVerb(array $methods, array $verbs): bool
    {
        foreach ($methods as $method) {
            foreach ($verbs as $verb) {
                if ($method === $verb) {
                    return true;
                }

                if (str_starts_with($method, $verb) && ctype_upper($method[strlen($verb)] ?? 'a')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The composer package a binding site belongs to — `null` meaning app-local, which is the value an
     * app-local descriptor's `package:` slot takes.
     *
     * Two derivations, in order, and the second one exists because the first is blind in exactly the tree
     * this estate develops in:
     *
     *   1. the `vendor/<vendor>/<name>/` path segment, which is right in any canonical install;
     *   2. failing that, a walk up to the nearest `composer.json` — for a **co-dev** checkout, where
     *      `vendor/<vendor>/<name>` is a SYMLINK and a resolved path therefore carries no `vendor/` segment
     *      at all. Before this fallback, every row the whole-host scan produced in `splicewire-app` reported
     *      `package: null`, i.e. "app-local", for classes that live in twenty different packages.
     *
     * The walk stops **at the host's own base path**, which is what the original objection to it was about:
     * a host's `composer.json` says `laravel/laravel`, so a walk that reached it would attribute every app
     * binding to the skeleton package. Refusing to cross that line keeps app-local reading as `null` while
     * letting a symlinked package name itself.
     */
    protected function packageOf(string $file): ?string
    {
        return self::packageOfPath($file);
    }

    /**
     * The same derivation, callable from {@see governedRoots()} before an instance exists — and public
     * because {@see RegistryConformanceAudit} needs the identical answer. One
     * implementation, so the gate and the report can never attribute the same class to two packages.
     */
    public static function packageOfPath(string $file): ?string
    {
        $normalized = str_replace('\\', '/', $file);

        if (preg_match('#/vendor/([^/]+)/([^/]+)/#', $normalized, $m) === 1) {
            return $m[1].'/'.$m[2];
        }

        return self::composerNameAbove($normalized);
    }

    /**
     * The `name` of the nearest `composer.json` above a file, never crossing the host's own base path.
     */
    protected static function composerNameAbove(string $file): ?string
    {
        $stop = function_exists('base_path') ? (string) realpath(base_path()) : '';
        $dir = dirname($file);

        while ($dir !== '' && $dir !== '/' && $dir !== dirname($dir)) {
            if ($stop !== '' && $dir === $stop) {
                return null;
            }

            if (is_file($manifest = $dir.'/composer.json')) {
                $decoded = json_decode((string) file_get_contents($manifest), true);
                $name = is_array($decoded) ? ($decoded['name'] ?? null) : null;

                return is_string($name) && $name !== '' ? $name : null;
            }

            $dir = dirname($dir);
        }

        return null;
    }

    protected function reflect(string $class): ?ReflectionClass
    {
        if (! class_exists($class) && ! interface_exists($class)) {
            return null;
        }

        try {
            return new ReflectionClass($class);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function isExcludedPath(string $file): bool
    {
        $normalized = str_replace('\\', '/', strtolower($file));

        foreach ($this->excludedPaths as $fragment) {
            if (str_contains($normalized, strtolower($fragment))) {
                return true;
            }
        }

        return false;
    }

    // ── source/AST helpers (mirrors CentralPinJustificationAudit's own) ───────────────────────────────

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
     * @return Node[]|null
     */
    protected function parse(string $source): ?array
    {
        try {
            return (new ParserFactory)->createForNewestSupportedVersion()->parse($source);
        } catch (\Throwable) {
            // An unparseable file binds nothing; an audit that throws on one bad vendor file reports
            // nothing at all about the other few thousand.
            return null;
        }
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
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                static function (\SplFileInfo $current): bool {
                    if ($current->isDir()) {
                        // `vendor` is skipped only as a NESTED dir: each family package carries its own dev
                        // `vendor/` tree, and re-descending into those re-scans the whole estate per package.
                        return ! in_array($current->getFilename(), ['vendor', 'node_modules', '.git'], true);
                    }

                    return $current->getExtension() === 'php';
                },
            ),
        );

        foreach ($iterator as $file) {
            // An empty directory is yielded as a LEAF by RecursiveIteratorIterator, so the extension filter
            // above never sees it — without this guard it reaches `file_get_contents()` as a dir.
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * The roots currently in the index, as strings — what used to be the descriptor list.
     *
     * @return list<string>
     */
    public function described(): array
    {
        return array_map('strval', $this->index->unfiltered()->keys());
    }
}
