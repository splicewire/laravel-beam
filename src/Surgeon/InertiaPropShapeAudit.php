<?php

namespace Splicewire\Beam\Surgeon;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;

/**
 * The **Inertia leg** of the negative-space detector (particle-doctrine-convergence ticket 06): every
 * `Inertia::render(...)` site whose props cross the boundary as an inline array rather than a declared Data
 * object.
 *
 * ## Why this is a SIBLING audit and not a row-producer bolted onto {@see UndeclaredSurfaceAudit}
 *
 * The three legs of this ticket each have a different ENUMERATION SEAM, and the seam is what decides where the
 * code lives:
 *
 *   - the HTTP leg reads the live router (`Route::getRoutes()`) — a runtime object graph in this package;
 *   - the MCP leg (`Splicewire\Beam\Mcp\Surgeon\McpToolShapeAudit`, deliberately named in prose rather than
 *     `{@see}`-linked: an import would make beam-core reference an OPTIONAL downstream package) reads a
 *     runtime registry in ANOTHER package, so it went sibling and registers down into this package's doctor
 *     manifest;
 *   - this leg reads **files on disk**, statically, with no router and no registry at all.
 *
 * Folding this into {@see UndeclaredSurfaceAudit} would break that audit in two concrete ways rather than one
 * aesthetic one. First, its row schema is route-keyed — `uri`, `methods`, `name`, `action` — and an Inertia
 * render site has none of those; it has a page name and a file:line. Merging would mean either a second row
 * schema behind the same key or four permanently-null columns in a COMMITTED artifact. Second, that artifact's
 * byte-stability contract is `usort` by `[uri, methods]`, and rows with no uri have nothing to sort on, so the
 * ratchet's "the number only goes down" reading would stop being computable from a single ordered list.
 * Third, and decisively: {@see UndeclaredSurfaceAudit} deliberately takes NO host file paths (its provider
 * binding says so in as many words), because needing a `forApp()` path seam is exactly what makes an audit
 * host-scoped and filesystem-bound. This audit needs one. Different seam, different constructor, different
 * audit — the same reasoning the MCP leg already applied once.
 *
 * ## What counts as declared
 *
 * The render site is the boundary. Props are declared when the second argument IS a Data object
 * (`SomePageData::from($x)`, `new SomePageData(...)`) — one typed shape crossing to the client. They are
 * undeclared when they cross as an array, because an array announces nothing: the client learns the shape by
 * reading the server, which is the whole failure this ticket exists to count.
 *
 * ### Data-derived but FLATTENED still counts as undeclared — tiered `mechanical`
 *
 * `Inertia::render('songs/show', ['song' => PublicSongData::project($song)])` (audiostud) is the interesting
 * case. A Data class is genuinely involved, so it is tempting to call it declared. It is not: what crosses the
 * boundary is an ARRAY with one key, and the array is the contract the client actually consumes. The Data
 * object was flattened back into untyped shape one character before the boundary, so the render site declares
 * nothing — declaredness is a property of the site, not of the ancestry of the values at it.
 *
 * But it is not the same WORK as an array of raw scalars, so it does not get the same tier. Every value is
 * already a Data construction, so hoisting them into a page-props Data object requires nobody to invent or
 * choose a shape — the field types are already written down. That is a decision-free edit, which is precisely
 * what {@see UndeclaredSurfaceAudit::TIER_MECHANICAL} means in this vocabulary. An inline array of raw scalars
 * gets {@see UndeclaredSurfaceAudit::TIER_GUIDED}, because somebody has to commit to a shape that does not yet
 * exist, and that is a real contract decision.
 *
 * ### A render inside a closure is a finding at the SAME tier, not a worse one
 *
 * `Fortify::loginView(fn ($request) => Inertia::render('auth/login', [...]))` (audiostud, 7 of them in one
 * file) is double-invisible: no controller action to find it by, and inline props once found. It must not be
 * skipped, and it is not — this audit walks source, not the router, so a closure is no harder to see than a
 * method.
 *
 * It does NOT tier as `manual`, and that is a deliberate divergence from the HTTP leg. There, `manual` means
 * there is literally nothing to annotate: a closure route has no method to carry `#[ResponseFromData]`, so a
 * controller must be created first. Here the declaration site is the props ARGUMENT, which exists and is
 * writable whether it sits in a closure or a controller. Closure-ness changes how hard the surface is to
 * FIND; tiering in this effort is by how hard it is to CONVERT. So the closure is recorded in the row's
 * `context` field and named in the finding text — visible, never silent — while the tier stays driven by prop
 * shape.
 *
 * ### Zero props is NOT a finding — `Route::inertia('/os', 'os')` is declared by emptiness
 *
 * `Route::inertia()` with no props argument, and `Inertia::render('page')` likewise, pass no data. There is no
 * shape to declare and no undeclared shape can be hiding in one: this is "there is nothing to ask about", not
 * "we never asked". Reporting them would make the artifact's number stop meaning what it says.
 *
 * This is the same asymmetry the MCP leg had to get right and states explicitly: an absent *input* schema
 * means a tool takes no input, which is a COMPLETE contract, while an absent *output* schema is always a
 * missing one because every tool returns something. Absence is a finding only where something must exist.
 * Props need not exist. So a propless render is a complete contract.
 *
 * It is still not silent, per this ticket's own no-silent-caps principle: propless sites are collected by
 * {@see propless()} and their count is stated in this audit's own finding text, so a reader sees "8 propless
 * sites, declared by emptiness" rather than inferring their absence. And a `Route::inertia()` that DOES pass a
 * props array is a finding like any other.
 *
 * ## No path exclusions, on purpose
 *
 * {@see CentralPinJustificationAudit::DEFAULT_EXCLUDED_PATHS} drops `/tests/`, `/fixtures/` and `/stubs/`
 * wholesale — correct there (a fixture model pinning central is scaffolding, not an estate pin), but it means
 * an audit asserted from inside a test tree can pass BY EXCLUSION while proving nothing. This audit carries no
 * such list: scope comes from the roots it is handed ({@see forApp()} hands it `app/`, `routes/`, `src/` and
 * the family packages), and {@see sitesInSource()} is pure over source so the unit tests exercise the real
 * classifier rather than a filter.
 */
class InertiaPropShapeAudit implements DoctorAudit
{
    public const CHECK = 'particle.undeclared-inertia-props';

    /** Props crossed the boundary as an inline array of values that are not Data objects. */
    public const REASON_INLINE_ARRAY = 'passes inline prop array instead of a declared Data object';

    /** Every value IS a Data construction, but they were flattened into an array at the render site. */
    public const REASON_FLATTENED_DATA = 'flattens Data objects into an inline prop array at the render site';

    /** A props expression that is neither an array literal nor a resolvable Data construction. */
    public const REASON_UNRESOLVABLE = 'passes a props expression that does not statically resolve to a declared Data object';

    /** The render sits in a named function or method — findable by every static pass in the pipeline. */
    public const CONTEXT_METHOD = 'method';

    /** The render sits inside a closure or arrow function — invisible to any action-resolving pass. */
    public const CONTEXT_CLOSURE = 'closure';

    /**
     * Static-call render sites, as `ClassShortName::method` => the ZERO-BASED index of the props argument.
     *
     * `Route::inertia($uri, $page, $props)` shifts the props slot to 2 because the uri comes first — getting
     * this wrong would read a route's PAGE NAME as its props and classify every one of them unresolvable.
     *
     * @var array<string, int>
     */
    public const STATIC_RENDER_SITES = [
        'Inertia::render' => 1,
        'Route::inertia' => 2,
    ];

    /** The `inertia('page', [...])` global helper — the same surface without the facade. */
    public const HELPER_RENDER = 'inertia';

    /**
     * Static methods on a `*Data` class that CONSTRUCT one. `from`/`fromModel`/`collect`/`collection` are
     * laravel-data's own; `project` is the family's projection convention (`PublicSongData::project($song)`),
     * and omitting it would misread the single most common flattened-Data site in the estate as unresolvable.
     *
     * @var list<string>
     */
    public const DATA_FACTORY_METHODS = ['from', 'fromModel', 'collect', 'collection', 'project'];

    public function __construct(
        /** @var list<string> */
        protected array $roots,
    ) {}

    /**
     * Host-scoped wiring: the host's own code plus the family packages it composes. The packages matter — the
     * beam-accounts controllers and beam-mdx's content routes render Inertia pages the host never sees, so an
     * `app/`-only scan would report a comfortable number from inside every host while those sat one directory
     * over. Same argument, same shape as {@see CentralPinJustificationAudit::forApp()}.
     *
     * @param  list<string>|null  $roots
     */
    public static function forApp(?array $roots = null): self
    {
        if ($roots === null) {
            $roots = array_values(array_filter([
                base_path('app'),
                base_path('routes'),
                base_path('src'),
                base_path('vendor/splicewire'),
                base_path('vendor/rushing'),
                base_path('vendor/schemastud'),
            ], 'is_dir'));
        }

        return new self($roots);
    }

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        $sites = $this->sites();
        $undeclared = $this->undeclaredIn($sites);
        $propless = count($sites) - count($undeclared);

        if ($undeclared === []) {
            return [Finding::pass(self::CHECK, sprintf(
                'Every Inertia render site declares its props (%d propless site(s), declared by emptiness).',
                $propless,
            ))];
        }

        return array_map(
            fn (array $row) => Finding::warn(self::CHECK, sprintf(
                '[%s] Inertia page %s (%s) — %s at %s:%d',
                $row['tier'],
                $row['page'] ?? '<dynamic>',
                $row['context'] === self::CONTEXT_CLOSURE
                    // Named, because a closure render is invisible to every action-resolving pass in the
                    // pipeline; the reader needs to know why they have never seen this one reported.
                    ? 'in a closure — '.$row['enclosing']
                    : $row['enclosing'],
                $row['reason'],
                $row['file'],
                $row['line'],
            )),
            $undeclared,
        );
    }

    /**
     * Every Inertia render site found under the roots, as sorted rows — propless ones included, carrying a
     * null reason and tier.
     *
     * Sorted by file then line so a re-run with no code change is byte-identical; filesystem iteration order
     * is not stable enough for the artifact contract this leg feeds.
     *
     * @return list<array{page: string|null, file: string, line: int, context: string, enclosing: string, reason: string|null, tier: string|null}>
     */
    public function sites(): array
    {
        $rows = [];

        foreach ($this->roots as $root) {
            foreach ($this->phpFiles($root) as $file) {
                foreach ($this->sitesInSource((string) file_get_contents($file), $file) as $row) {
                    $rows[] = $row;
                }
            }
        }

        usort($rows, fn (array $a, array $b) => [$a['file'], $a['line']] <=> [$b['file'], $b['line']]);

        return $rows;
    }

    /**
     * The work-list proper: the render sites whose props are undeclared. This is the artifact payload.
     *
     * @return list<array{page: string|null, file: string, line: int, context: string, enclosing: string, reason: string|null, tier: string|null}>
     */
    public function undeclared(): array
    {
        return $this->undeclaredIn($this->sites());
    }

    /**
     * The propless census — sites passing no props at all (`Route::inertia('/os', 'os')` and friends).
     * Deliberately NOT findings (see the class docblock), but deliberately COUNTABLE: a cap nobody can see is
     * the failure mode this ticket names in its own second paragraph.
     *
     * @return list<array{page: string|null, file: string, line: int, context: string, enclosing: string, reason: string|null, tier: string|null}>
     */
    public function propless(): array
    {
        return array_values(array_filter($this->sites(), fn (array $row) => $row['reason'] === null));
    }

    /**
     * Parse ONE file's Inertia render sites. Pure over source — unit-callable with no disk, container, or
     * router, which is what lets the tests exercise the real classifier instead of a path filter.
     *
     * @return list<array{page: string|null, file: string, line: int, context: string, enclosing: string, reason: string|null, tier: string|null}>
     */
    public function sitesInSource(string $source, string $file = ''): array
    {
        $ast = $this->parse($source);

        if ($ast === null) {
            return [];
        }

        // Parent links must be connected across the WHOLE file before any ancestor walk: a render's enclosing
        // closure or method lives ABOVE it in the tree, and there is no way back up without them.
        $traverser = new NodeTraverser;
        $traverser->addVisitor(new ParentConnectingVisitor);
        $ast = $traverser->traverse($ast);

        $finder = new NodeFinder;
        $rows = [];

        /** @var list<StaticCall|FuncCall> $calls */
        $calls = $finder->find($ast, fn (Node $n) => $n instanceof StaticCall || $n instanceof FuncCall);

        foreach ($calls as $call) {
            $slot = $this->propsSlot($call);

            if ($slot === null) {
                continue;
            }

            $classification = $this->classify($this->argAt($call, $slot, 'props'));

            if ($classification === null) {
                // Declared: a Data object crosses the boundary. Not a site worth recording at all.
                continue;
            }

            [$context, $enclosing] = $this->enclosing($call);

            $rows[] = [
                'page' => $this->pageName($call, $slot),
                'file' => $file,
                'line' => $call->getStartLine(),
                'context' => $context,
                'enclosing' => $enclosing,
                'reason' => $classification['reason'],
                'tier' => $classification['tier'],
            ];
        }

        usort($rows, fn (array $a, array $b) => [$a['file'], $a['line']] <=> [$b['file'], $b['line']]);

        return $rows;
    }

    /**
     * @param  list<array{page: string|null, file: string, line: int, context: string, enclosing: string, reason: string|null, tier: string|null}>  $sites
     * @return list<array{page: string|null, file: string, line: int, context: string, enclosing: string, reason: string|null, tier: string|null}>
     */
    protected function undeclaredIn(array $sites): array
    {
        return array_values(array_filter($sites, fn (array $row) => $row['reason'] !== null));
    }

    /**
     * The zero-based index of the props argument if this call is a render site, else null. Matches on the
     * class SHORT name so an aliased or fully-qualified `Inertia` facade import resolves the same way.
     */
    protected function propsSlot(StaticCall|FuncCall $call): ?int
    {
        if ($call instanceof FuncCall) {
            return $call->name instanceof Node\Name && $call->name->toString() === self::HELPER_RENDER ? 1 : null;
        }

        if (! $call->class instanceof Node\Name || ! $call->name instanceof Node\Identifier) {
            return null;
        }

        $key = $this->shortName($call->class->toString()).'::'.$call->name->toString();

        return self::STATIC_RENDER_SITES[$key] ?? null;
    }

    /**
     * The argument at a POSITIONAL slot, or the one passed by the given NAME. Named arguments are checked
     * because `Inertia::render(props: [...])` is legal and reading it positionally would silently classify the
     * site as propless — a false PASS, the one direction this audit must never fail in.
     */
    protected function argAt(StaticCall|FuncCall $call, int $slot, string $name): ?Node\Expr
    {
        $positional = 0;

        foreach ($call->getArgs() as $arg) {
            if ($arg->name !== null) {
                if ($arg->name->toString() === $name) {
                    return $arg->value;
                }

                continue;
            }

            if ($positional === $slot) {
                return $arg->value;
            }

            $positional++;
        }

        return null;
    }

    /** The literal page name, or null when it is a variable (`Inertia::render($page, ...)`, beam-mdx). */
    protected function pageName(StaticCall|FuncCall $call, int $slot): ?string
    {
        // The page argument sits immediately before the props slot in every registered render shape.
        $page = $this->argAt($call, $slot - 1, 'page') ?? $this->argAt($call, $slot - 1, 'component');

        return $page instanceof Node\Scalar\String_ ? $page->value : null;
    }

    /**
     * Classify a props expression: null when it is DECLARED (a Data object crosses the boundary), else the
     * reason and tier. A propless site classifies as a reason-less, tier-less row so it stays countable
     * without becoming a finding.
     *
     * @return array{reason: string|null, tier: string|null}|null
     */
    protected function classify(?Node\Expr $props): ?array
    {
        if ($props === null) {
            return ['reason' => null, 'tier' => null];
        }

        if ($props instanceof Node\Expr\Array_) {
            if ($props->items === []) {
                // `Inertia::render('page', [])` says the same thing as omitting the argument.
                return ['reason' => null, 'tier' => null];
            }

            return $this->everyValueIsDataConstruction($props)
                ? ['reason' => self::REASON_FLATTENED_DATA, 'tier' => UndeclaredSurfaceAudit::TIER_MECHANICAL]
                : ['reason' => self::REASON_INLINE_ARRAY, 'tier' => UndeclaredSurfaceAudit::TIER_GUIDED];
        }

        if ($this->isDataConstruction($props)) {
            return null;
        }

        // A variable, an `array_merge(...)`, a spread — genuinely can't tell (`$props` in beam-accounts'
        // SecurityController is the live case). Reported, not dropped: an unreadable boundary is exactly the
        // "we generated nothing / this does not exist look identical" state the ticket exists to make visible.
        return ['reason' => self::REASON_UNRESOLVABLE, 'tier' => UndeclaredSurfaceAudit::TIER_GUIDED];
    }

    /**
     * Whether EVERY value in the array is a Data construction — the flattened-Data case. All of them, not
     * any: one raw scalar alongside three Data objects still means somebody must design the shape of that
     * scalar's field, so the edit stops being decision-free and the row belongs in `guided`.
     *
     * A spread element (`...$more`) disqualifies the array: its values are not visible here, so claiming they
     * are all Data objects would be a guess in the optimistic direction.
     */
    protected function everyValueIsDataConstruction(Node\Expr\Array_ $props): bool
    {
        foreach ($props->items as $item) {
            if ($item === null || $item->unpack || ! $this->isDataConstruction($item->value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * `SomeData::from(...)` / `::project(...)` / `new SomeData(...)` — matched on the `*Data` suffix, the
     * family's DTO naming convention, exactly as {@see SdkReturnsCoverageAudit::outermostDtoConstructions()}
     * does. There is no PHP type signal to read here: the props argument is `array` or `mixed` at every one of
     * these call sites, which is the very reason this audit has to exist.
     */
    protected function isDataConstruction(Node\Expr $expr): bool
    {
        if ($expr instanceof StaticCall
            && $expr->class instanceof Node\Name
            && str_ends_with($expr->class->toString(), 'Data')
            && $expr->name instanceof Node\Identifier) {
            return in_array($expr->name->toString(), self::DATA_FACTORY_METHODS, true);
        }

        return $expr instanceof New_
            && $expr->class instanceof Node\Name
            && str_ends_with($expr->class->toString(), 'Data');
    }

    /**
     * Where the render lexically sits: the nearest enclosing closure, method or function, walking UP the
     * parent links. The closure check comes first because a closure INSIDE a method is what makes the Fortify
     * sites double-invisible — reporting the outer method there would hide the very thing worth naming.
     *
     * @return array{0: string, 1: string}
     */
    protected function enclosing(Node $call): array
    {
        $cur = $call->getAttribute('parent');

        while ($cur instanceof Node) {
            if ($cur instanceof Closure || $cur instanceof ArrowFunction) {
                return [self::CONTEXT_CLOSURE, $this->closureLabel($cur)];
            }

            if ($cur instanceof Node\Stmt\ClassMethod) {
                return [self::CONTEXT_METHOD, $this->methodLabel($cur)];
            }

            if ($cur instanceof Node\Stmt\Function_) {
                return [self::CONTEXT_METHOD, $cur->name->toString().'()'];
            }

            $cur = $cur->getAttribute('parent');
        }

        // A render at file top level — a route file's own body, which is where `Route::inertia` lives.
        return [self::CONTEXT_METHOD, 'file scope'];
    }

    /**
     * A closure has no name, so the useful label is the call it was PASSED TO — `Fortify::loginView()`,
     * `ShareLinkScopes::handle()`. That is the string an author greps for, and without it every one of the
     * seven Fortify sites reports as an indistinguishable "closure".
     */
    protected function closureLabel(Closure|ArrowFunction $closure): string
    {
        $parent = $closure->getAttribute('parent');

        // The closure is an argument; its parent is the Arg, whose parent is the receiving call.
        if ($parent instanceof Node\Arg) {
            $parent = $parent->getAttribute('parent');
        }

        if ($parent instanceof StaticCall
            && $parent->class instanceof Node\Name
            && $parent->name instanceof Node\Identifier) {
            return $this->shortName($parent->class->toString()).'::'.$parent->name->toString().'()';
        }

        if ($parent instanceof Node\Expr\MethodCall && $parent->name instanceof Node\Identifier) {
            return '->'.$parent->name->toString().'()';
        }

        if ($parent instanceof FuncCall && $parent->name instanceof Node\Name) {
            return $parent->name->toString().'()';
        }

        return 'closure';
    }

    protected function methodLabel(Node\Stmt\ClassMethod $method): string
    {
        $parent = $method->getAttribute('parent');
        $class = $parent instanceof Node\Stmt\ClassLike && $parent->name !== null ? $parent->name->toString().'::' : '';

        return $class.$method->name->toString().'()';
    }

    /**
     * @return Node[]|null
     */
    protected function parse(string $source): ?array
    {
        try {
            return (new ParserFactory)->createForNewestSupportedVersion()->parse($source);
        } catch (\Throwable) {
            // An unparseable file has no render sites; an audit that throws on one bad file in a vendor tree
            // reports nothing at all about the other few thousand.
            return null;
        }
    }

    protected function shortName(string $fqn): string
    {
        $pos = strrpos(ltrim($fqn, '\\'), '\\');
        $trimmed = ltrim($fqn, '\\');

        return $pos === false ? $trimmed : substr($trimmed, $pos + 1);
    }

    /**
     * Absolute paths of every `.php` file under a dir (recursive), or empty when the dir is absent. Mirrors
     * {@see CentralPinJustificationAudit::phpFiles()}, including both of its non-obvious guards.
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
                        // `vendor` is skipped only as a NESTED dir: `forApp()` hands us `vendor/splicewire` as
                        // a root directly, and each family package there carries its own dev `vendor/` tree —
                        // re-descending into those re-scans the whole estate once per package and exhausts a
                        // 128 MB limit long before it finishes.
                        return ! in_array($current->getFilename(), ['vendor', 'node_modules', '.git'], true);
                    }

                    return $current->getExtension() === 'php';
                },
            ),
        );

        foreach ($iterator as $file) {
            // An empty directory is yielded as a LEAF by RecursiveIteratorIterator, so the extension filter
            // above never sees it — without this guard it reaches `file_get_contents()` as a directory.
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
