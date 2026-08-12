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
use Splicewire\Beam\Manifest\ManifestArity;
use Splicewire\Beam\Manifest\ManifestDescriptor;
use Splicewire\Beam\Manifest\ManifestIndex;

/**
 * The **meta-audit** (particle-doctrine-convergence, ticket 13): every registry-shaped container singleton
 * must `describe()` itself into the {@see ManifestIndex}.
 *
 * ## Why this one is a GATE when everything else in the effort is advisory
 * Every other check in this initiative reports a backlog, and a backlog that fails the build is just a
 * blocked build. This one is different in kind: `splicewire:beam:manifests --json` is what an agent is
 * *told to run* as its entry point for "where do I register this". A registry that is not in the index is a
 * registry an agent cannot find, and an agent that cannot find a registry builds a parallel mechanism next
 * to it. So the cost of the drift is not a stale document, it is a second implementation of something that
 * already exists — the exact failure the rest of this effort is paying to prevent. It is also the cheapest
 * check here (one `describe(...)` call fixes any finding), so it is the one place where blocking is
 * proportionate. Registered `gate: true` in `BeamServiceProvider::registerSurgeonAudits()`, and every
 * finding is a {@see Finding::fail()} so it blocks at the runner's DEFAULT floor rather than only at a
 * lowered one.
 *
 * ## The scope is the index's own membership — the ratchet
 * `forIndex()` derives its scan roots from the descriptors ALREADY in the index: a package that has
 * described at least one registry is **opted in**, and is then held to describing all of them. A package
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
 * {@see ManifestArity}'s docblock on exactly that), and blockdoc's node-type
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
 * ## Why not RegistrationDriftDetector
 * `Rushing\Surgeon\Audit\RegistrationDriftDetector` is the estate's diff-a-registry engine and was the
 * obvious seed, but its diff is *attribute-scan-shaped*: `detect($scanPaths, $attributeClass, $manual)`
 * asks an {@see AttributedClassScanner} which classes carry an attribute and
 * diffs that against a manual registration list. Both of this audit's sides are something else — the
 * discovered side is container binding *call sites*, the declared side is a live singleton's descriptor
 * list, and neither has an attribute to scan. Its `RegistrationDrift` DTO could be borrowed to carry the
 * diff, but its `toFindings()` emits `warn` with "registered manually but has no attribute" text and no
 * slot for the provider name this audit's findings are required to carry, so borrowing it would mean
 * emitting a strictly worse finding to reuse an `array_diff`. Stated here so the next reader does not
 * re-derive the question.
 *
 * ## The finding names the provider, not just the registry
 * A finding whose remedy is "somebody should describe this" is unactionable, because the index's direction
 * is load-bearing: an owner registers DOWN into it from its OWN provider and beam-core never reaches up to
 * discover anyone. So the fix has exactly one legal home — the provider that binds the singleton — and the
 * finding names that provider and its file:line.
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
        protected ManifestIndex $index,
        /** @var list<string> */
        protected array $excludedPaths = self::DEFAULT_EXCLUDED_PATHS,
    ) {}

    /**
     * The governed wiring: scan roots derived from the index's OWN membership (see the class docblock's
     * ratchet argument). A descriptor with a `package` contributes that package's `src/` under the host's
     * vendor dir; an app-local descriptor (`package: null`) contributes the host's own `app/`/`src/`.
     *
     * `vendor/<pkg>/src` rather than `vendor/<pkg>` on purpose: family packages each carry their own dev
     * `vendor/` tree, and descending into those re-scans the whole estate once per package.
     *
     * @param  list<string>|null  $roots
     */
    public static function forIndex(ManifestIndex $index, ?array $roots = null): self
    {
        return new self($roots ?? self::governedRoots($index), $index);
    }

    /**
     * @return list<string>
     */
    public static function governedRoots(ManifestIndex $index): array
    {
        $roots = [];

        foreach ($index->descriptors() as $descriptor) {
            if ($descriptor->package === null) {
                foreach (['app', 'src'] as $dir) {
                    if (is_dir($path = base_path($dir))) {
                        $roots[$path] = true;
                    }
                }

                continue;
            }

            $package = base_path('vendor/'.$descriptor->package);

            if (is_dir($package.'/src')) {
                $roots[$package.'/src'] = true;
            } elseif (is_dir($package)) {
                $roots[$package] = true;
            }
        }

        return array_keys($roots);
    }

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        $rows = $this->registries();
        $undescribed = array_values(array_filter($rows, fn (array $row) => ! $row['described']));

        if ($undescribed === []) {
            return [Finding::pass(self::CHECK, sprintf(
                'All %d registry-shaped singleton(s) in the governed packages describe themselves into the manifest index.',
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

                $concrete = $this->classStringArg($call->args[1] ?? null, $namespace, $imports);

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
            '%s is a registry-shaped singleton bound at %s:%d but is not described into the manifest index. '.
            '%s must call app(ManifestIndex::class)->describe(new ManifestDescriptor(name:, of:, seam:, arity:, '.
            'registerHint:, where: %s::class, package: %s)). An undescribed registry is one '.
            '`splicewire:beam:manifests --json` cannot show, so an agent looking for where to register '.
            'builds a parallel mechanism beside it.',
            $this->shortName($row['registry']),
            $row['file'],
            $row['line'],
            $this->shortName($row['provider']),
            $this->shortName($row['registry']),
            $row['package'] === null ? 'null' : "'".$row['package']."'",
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

        if (! $arrayProperty && ! $arrayConstructorParam) {
            return false;
        }

        $methods = $this->publicMethodNames($abstractReflection, $concreteReflection);

        $hasEntry = $arrayConstructorParam || $this->matchesVerb($methods, self::WRITE_VERBS);
        $hasLookup = $this->matchesVerb($methods, self::READ_VERBS);

        return $hasEntry && $hasLookup;
    }

    /**
     * Whether the index already carries this registry. Matched on the descriptor's `where` CONTAINING the
     * FQCN (several descriptors legitimately decorate it — `'#[ParticleResource] → '.FQCN`, or a config key
     * instead) or on the descriptor `name` being the registry's short name, optionally with a parenthetical
     * qualifier (`'ParticleWriter (write chain)'`). Matching loosely is the right error direction here: a
     * false "described" costs a missing index row, a false "undescribed" hard-blocks a build.
     */
    public function isDescribed(string $abstract, ?string $concrete = null): bool
    {
        $names = array_values(array_unique(array_filter([
            $this->shortName($abstract),
            $concrete !== null ? $this->shortName($concrete) : null,
        ])));

        foreach ($this->index->descriptors() as $descriptor) {
            foreach ([$abstract, $concrete] as $fqcn) {
                if ($fqcn !== null && str_contains($descriptor->where, $fqcn)) {
                    return true;
                }
            }

            foreach ($names as $name) {
                if ($descriptor->name === $name || str_starts_with($descriptor->name, $name.' (')) {
                    return true;
                }
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
     * The composer package a binding site belongs to, from the `vendor/<vendor>/<name>/` path segment —
     * `null` meaning app-local, which is the value an app-local descriptor's `package:` slot takes.
     *
     * Deliberately NOT a walk up to the nearest `composer.json`: a host's own `composer.json` says
     * `laravel/laravel`, so that walk would attribute every app binding to the skeleton package and put a
     * wrong `package:` in the remedy the finding prints.
     */
    protected function packageOf(string $file): ?string
    {
        $normalized = str_replace('\\', '/', $file);

        if (preg_match('#/vendor/([^/]+)/([^/]+)/#', $normalized, $m) !== 1) {
            return null;
        }

        return $m[1].'/'.$m[2];
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

    /** @return list<ManifestDescriptor> */
    public function described(): array
    {
        return $this->index->descriptors();
    }
}
