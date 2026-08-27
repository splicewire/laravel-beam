<?php

namespace Splicewire\Beam\Surgeon;

use PhpParser\Comment;
use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\UseItem;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Surgeon\Support\HostScanRoots;

/**
 * The **central-pin justification** audit (particle-doctrine-convergence, ticket 12): every pin of the
 * `central` connection must cite, at the pin, which floor category earns it.
 *
 * ## Why this is a check and not a paragraph in the brief
 * A prose hint can only be obeyed by someone who already knows they wrote a pin. `splicewire/laravel-beam-ux`
 * pins central through a class CONSTANT (`Lifecycle/EntryPromoter.php:34`, `Theme/ThemeResolver.php:43`) and
 * reaches the connection through `Model::on(self::CENTRAL_CONNECTION)` / `->setConnection(...)` — never once
 * writing `protected $connection = 'central'`. A `grep` for the property form, and every reviewer who has
 * internalized that form as "what a pin looks like", misses both. **A hint cannot find the pin that does not
 * look like a pin**, which is the entire argument for making this executable. Hence
 * {@see FORM_PROPERTY}, {@see FORM_CONSTANT} and {@see FORM_CALL} are all first-class here.
 *
 * ## The decidable test
 * From `scriptorium-corpus/apocrypha/tenant-is-a-profile-over-a-floor.md`: **does this record index the
 * churn, or participate in it?** Floor indexes; profile participates. The floor is a CLOSED list
 * ({@see FLOOR_CATEGORIES}) with three checkable predicates — the bootstrap paradox (you cannot gate the door
 * with a key that only exists behind the door), one-central-join (resolvable without booting every tenant
 * schema), and portability — plus an implicit fourth: floor rows are pointer-only and secret-free.
 *
 * ## What counts as a cited justification
 * A `{@see TAG} <category>` tag in a comment or docblock ATTACHED TO THE PIN, or on the class that declares
 * it, naming one member of {@see FLOOR_CATEGORIES}. Deliberately structured rather than prose-sniffed:
 * nearly every pin in the estate already carries a docblock explaining what the model IS and that it lives
 * centrally, and a restatement of the pin ("the central audit trail, pinned to the central connection") is
 * not a justification of it — it answers "what" where the floor test asks "which category of floor, and by
 * which predicate". Only a named category is decidable against the closed list, so only a named category
 * silences a finding. A tag naming something OUTSIDE the closed list is itself reported: inventing a
 * category is how a closed list quietly opens.
 *
 * ## Advisory, permanently
 * The output is a **documentation backlog**, and a documentation backlog that fails the build is just a
 * blocked build. Every finding is a {@see Finding::warn()} — this audit never emits a `Fail` — and it
 * registers advisory (`gate: false`) in `BeamServiceProvider::registerSurgeonAudits()`.
 *
 * ## The census, and why this paragraph is dated
 *
 * **30 pins, 21 unjustified** at `~/Herd/splicewire-app` (measured 2026-08-27, realm-and-floor
 * reconciliation ticket 03). Of the unjustified ones, roughly 4 survive the decidable test cleanly, 3 are
 * arguable, and the rest are `central`-because-untenanted rather than floor.
 *
 * This paragraph previously read *"23 pins of which 10 carry no justification"* — and was wrong, while
 * `HostScanRoots`' own docblock recorded 24/15 from the same effort. Two numbers for one census, in one
 * package, and the class docblock is the one people read. It is dated now because a census figure is a
 * MEASUREMENT, not a fact about the code: it moves whenever the scan roots move, and this ticket moved
 * them twice — `database/` into scope (+2) and anonymous classes no longer skipped (+4). Re-run it rather
 * than cite it; {@see forApp()}`->pins()` is three lines in tinker.
 *
 * Reporting a pin is NOT a claim that the pin is wrong. The known live contradiction —
 * `Role`/`Permission` pinned central while the corpus argues Spatie roles are per-tenant furniture — is
 * ADR-sized and explicitly deferred; this audit reports those two like any other uncited pin and takes no
 * position on resolving them.
 */
class CentralPinJustificationAudit implements DoctorAudit
{
    public const CHECK = 'tenancy.central-pin-justification';

    /** The connection name whose pins this audit governs. */
    public const CENTRAL = 'central';

    /** The docblock/comment tag a pin uses to cite its floor category. */
    public const TAG = '@central-floor';

    /**
     * The CLOSED floor list — kernel/config primitives and the tenant-isolation record, the query engine,
     * the registry runtime, auth, and the billing/entitlement wall. Closed is the point: a pin that cannot
     * name itself into one of these is not floor, it is a profile row that participates in the churn, and
     * the honest outcome is either a re-pin or a written exception — never a new category.
     *
     * @var list<string>
     */
    public const FLOOR_CATEGORIES = [
        'kernel',
        'tenant-isolation',
        'query-engine',
        'registry-runtime',
        'auth',
        'billing-wall',
    ];

    /**
     * The host dirs this audit scans, WIDER than {@see HostScanRoots::HOST_DIRS} by `database/`.
     *
     * A host's migrations and seeders pin the connection like anything else — the flagship's
     * `create_tenant_syncs_table` carries both `protected $connection = 'central'` and
     * `Schema::connection('central')` — and the shared default stops at `['app','src']`, so every one of
     * them sat outside this audit's population. The audit's own argument for recognizing the constant and
     * call forms is that *"a hint cannot find the pin that does not look like a pin"*; the same argument,
     * one turn further, is that a census cannot judge a pin it never reads. Widened here rather than in
     * {@see HostScanRoots::HOST_DIRS} because the other two consumers have no business in `database/` —
     * registries and Inertia props do not live there, and a shared widening would buy them a slower scan
     * and nothing else.
     *
     * ⚠️ **`config/` is deliberately NOT here.** The flagship pins the OAuth token tables in a config
     * array — `config/passport.php`: `'connection' => env('PASSPORT_CONNECTION', 'central')` — and that is
     * a real pin this audit will never see. But it is an array literal: no property, no constant, no
     * method call, so none of the three forms match it and adding the root would buy scan cost and zero
     * findings. Detecting it needs a config-shaped matcher, which is a different instrument from an AST
     * walk over class-likes. Recorded rather than silently skipped, because "the census does not scan
     * `config/`" and "the census scans `config/` and finds nothing" are indistinguishable in the output.
     *
     * @var list<string>
     */
    public const HOST_DIRS = ['app', 'src', 'database'];

    /** `protected $connection = 'central'` — the form everyone recognizes. */
    public const FORM_PROPERTY = 'property';

    /** `const CENTRAL_CONNECTION = 'central'` — the laravel-beam-ux form a property search cannot see. */
    public const FORM_CONSTANT = 'constant';

    /** `Model::on('central')` / `->setConnection('central')` — a pin with no declaration site at all. */
    public const FORM_CALL = 'call';

    /**
     * Method names that pin a query or model to a named connection. `on`/`onWriteConnection` are Eloquent's
     * static entrypoints; `setConnection`/`connection` are the instance and builder forms.
     *
     * @var list<string>
     */
    public const CONNECTION_METHODS = ['on', 'onWriteConnection', 'setConnection', 'connection', 'usingConnection'];

    /**
     * Path fragments whose files are test fixtures or test support, excluded wholesale. A fixture model
     * pinning central is scaffolding for a test of the pin mechanism, not an estate pin, and asking a
     * fixture to cite a floor category would train authors to paste categories they have not thought about.
     *
     * @var list<string>
     */
    public const DEFAULT_EXCLUDED_PATHS = [
        '/tests/',
        '/test/',
        '/fixtures/',
        '/stubs/',
        '/factories/',
        '/vendor/orchestra/',
        '/node_modules/',
    ];

    /**
     * Namespace segments marking a test-support class that lives under `src/` (beam ships `Testing/`
     * helpers), plus the `Tests`/`Fixtures` roots for any package that autoloads them outside `tests/`.
     *
     * @var list<string>
     */
    public const DEFAULT_EXCLUDED_NAMESPACE_SEGMENTS = ['Tests', 'Testing', 'TestSupport', 'Fixtures'];

    public function __construct(
        /** @var list<string> */
        protected array $roots,
        /** @var list<string> */
        protected array $excludedPaths = self::DEFAULT_EXCLUDED_PATHS,
        /** @var list<string> */
        protected array $excludedNamespaceSegments = self::DEFAULT_EXCLUDED_NAMESPACE_SEGMENTS,
    ) {}

    /**
     * The default host-scoped wiring: the host's own first-party code PLUS the family packages it composes.
     * Scanning `vendor/{splicewire,rushing,schemastud}` is deliberate and is not the usual "never audit
     * vendor" mistake — the overwhelming majority of the estate's central pins live in family PACKAGES
     * (tower, beam-commerce, beam-tenancy, beam-accounts, beam-ux), so a scan confined to `app/` would
     * report a comfortable zero from inside every host while the backlog sat untouched one directory over.
     *
     * ## Each vendor dir is expanded ONE LEVEL and resolved, and that is load-bearing
     * Handing in `vendor/<vendor>` whole is what this method used to do, and it reported **zero pins at the
     * flagship** — measured 2026-08-26 by beam-facade ticket 97, which found it while wiring
     * {@see CentralPinResolvabilityAudit} on top of this census. `RecursiveDirectoryIterator` does not
     * follow symlinks, and in a co-dev host every `vendor/<vendor>/<package>` IS a symlink to the working
     * checkout — so the traversal reached the vendor directory, found nothing but links, and returned a
     * clean empty result. An empty report and an unread one look identical, which is the estate's recurring
     * defect class: *an instrument that reports success by not running.* Expanding one level with `glob()`
     * and `realpath()`-ing each package hands the iterator real directories instead.
     *
     * `<pkg>/src` is preferred over `<pkg>` because each family package carries its own dev `vendor/` tree.
     * {@see UndescribedRegistryAudit::forHost()} solved the identical problem the identical way, and a THIRD
     * audit then inherited the broken shape from a docblock — so beam-facade 149 moved the expansion into
     * {@see HostScanRoots} and this method calls it. Mirroring deliberately is what propagated the defect;
     * the copies are gone.
     *
     * @param  list<string>|null  $roots
     */
    public static function forApp(?array $roots = null): self
    {
        return new self($roots ?? HostScanRoots::resolve(self::HOST_DIRS));
    }

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        $rows = $this->pins();
        $unjustified = array_values(array_filter($rows, fn (array $row) => ! $row['justified']));

        if ($unjustified === []) {
            return [Finding::pass(self::CHECK, sprintf(
                'All %d central connection pin(s) cite a floor category.',
                count($rows),
            ))];
        }

        return array_map(
            fn (array $row) => Finding::warn(self::CHECK, $this->detail($row)),
            $unjustified,
        );
    }

    /**
     * Every central pin found, as sorted rows — the artifact payload and the work-list.
     *
     * Sorted by file then line so a re-run with no code change produces a byte-identical artifact;
     * filesystem iteration order is not stable enough to commit against.
     *
     * @return list<array{class: string, file: string, line: int, form: string, targets: list<string>, citation: string|null, justified: bool}>
     */
    public function pins(): array
    {
        $rows = [];

        foreach ($this->roots as $root) {
            foreach ($this->phpFiles($root) as $file) {
                if ($this->isExcludedPath($file)) {
                    continue;
                }

                foreach ($this->pinsInSource((string) file_get_contents($file), $file) as $row) {
                    $rows[] = $row;
                }
            }
        }

        usort($rows, fn (array $a, array $b) => [$a['file'], $a['line']] <=> [$b['file'], $b['line']]);

        return $rows;
    }

    /**
     * The work-list proper: the pins that cite nothing, or cite a category outside the closed list.
     *
     * @return list<array{class: string, file: string, line: int, form: string, targets: list<string>, citation: string|null, justified: bool}>
     */
    public function unjustified(): array
    {
        return array_values(array_filter($this->pins(), fn (array $row) => ! $row['justified']));
    }

    /**
     * Parse ONE file's central pins. Pure over source — unit-callable with no disk, container, or DB.
     *
     * At most one row per class per FORM, at the earliest line of that form: a class that declares
     * `const CENTRAL_CONNECTION = 'central'` and then calls `::on(self::CENTRAL_CONNECTION)` three times has
     * pinned central once, at the constant. Reporting each use site would turn one decision into a
     * three-item backlog and bury the declaration that actually needs the citation.
     *
     * @return list<array{class: string, file: string, line: int, form: string, targets: list<string>, citation: string|null, justified: bool}>
     */
    public function pinsInSource(string $source, string $file = ''): array
    {
        if ($file !== '' && $this->isExcludedPath($file)) {
            return [];
        }

        $ast = $this->parse($source);

        if ($ast === null) {
            return [];
        }

        $namespace = $this->namespaceOf($ast);

        if ($this->isExcludedNamespace($namespace)) {
            return [];
        }

        $imports = $this->importsOf($ast);
        $finder = new NodeFinder;
        $rows = [];

        /** @var list<Node\Stmt\Class_|Node\Stmt\Trait_> $classLikes */
        $classLikes = $finder->find($ast, fn (Node $n) => $n instanceof Node\Stmt\Class_ || $n instanceof Node\Stmt\Trait_);

        foreach ($classLikes as $classLike) {
            // ⚠️ An ANONYMOUS class is still a pin site, and skipping it hid an entire population.
            //
            // This used to `continue` on a null name, which reads as defensive and is not: since Laravel 9
            // EVERY migration is `return new class extends Migration`, so every migration pin in the estate
            // was invisible. Measured on the widening that brought `database/` into scope — the flagship's
            // `create_tenant_syncs_table` carries BOTH `protected $connection = 'central'` and
            // `Schema::connection('central')`, and the census reported neither, while the two seeders in the
            // same directory (ordinary named classes) reported fine.
            //
            // It has no FQN to report, so it is addressed the way a reader would address it — by file and
            // line. That is the better identity for a migration anyway: nobody refers to one by class name.
            $class = $classLike->name === null
                ? 'class@anonymous'
                : ($namespace !== '' ? $namespace.'\\' : '').$classLike->name->toString();

            if (str_ends_with($class, 'Test')) {
                continue;
            }

            $classDoc = $this->commentText([$classLike->getDocComment()]);
            $constants = $this->centralConstants($classLike);
            $calls = $this->connectionCalls($classLike, $constants, $imports, $finder);

            // The property form: the one everybody already looks for.
            foreach ($classLike->getProperties() as $property) {
                foreach ($property->props as $prop) {
                    if ($prop->name->toString() !== 'connection' || ! $this->isCentralString($prop->default)) {
                        continue;
                    }

                    $rows[] = $this->row(
                        $class,
                        $file,
                        $property->getStartLine(),
                        self::FORM_PROPERTY,
                        $calls['targets'],
                        $this->citation($classDoc.$this->commentsOf($property)),
                    );

                    break 2;
                }
            }

            // The constant form. The CONSTANT is the pin site — every use defers to it, so that is where the
            // citation belongs — but a constant only counts once the class actually reaches a connection
            // through it. A bare `'central'` string constant is not self-evidently a pin (this audit's own
            // `self::CENTRAL` is one, and is not pinning anything), so the connection call is the evidence
            // and the constant is the address.
            $used = array_values(array_filter(
                $constants,
                fn (string $name) => in_array($name, $calls['usedConstants'], true),
                ARRAY_FILTER_USE_KEY,
            ));

            if ($used !== []) {
                $rows[] = $this->row(
                    $class,
                    $file,
                    $used[0]['line'],
                    self::FORM_CONSTANT,
                    $calls['targets'],
                    $this->citation($classDoc.$used[0]['comments']),
                );

                continue;
            }

            // The bare-call form: a pin with no declaration site at all. Only reached when neither a
            // property nor a constant already answered for this class.
            if ($calls['line'] !== null) {
                $rows[] = $this->row(
                    $class,
                    $file,
                    $calls['line'],
                    self::FORM_CALL,
                    $calls['targets'],
                    $this->citation($classDoc.$calls['comments']),
                );
            }
        }

        return $rows;
    }

    /**
     * @param  list<string>  $targets
     * @return array{class: string, file: string, line: int, form: string, targets: list<string>, citation: string|null, justified: bool}
     */
    protected function row(string $class, string $file, int $line, string $form, array $targets, ?string $citation): array
    {
        return [
            'class' => $class,
            'file' => $file,
            'line' => $line,
            'form' => $form,
            'targets' => $targets,
            'citation' => $citation,
            'justified' => $citation !== null && in_array($citation, self::FLOOR_CATEGORIES, true),
        ];
    }

    /**
     * The finding text. It names the pin, its FORM, and its file:line, so the report is a work-list rather
     * than a score — and it repeats the closed list, because the author who needs this message is precisely
     * the author who does not have the floor list in front of them.
     *
     * @param  array{class: string, file: string, line: int, form: string, targets: list<string>, citation: string|null, justified: bool}  $row
     */
    protected function detail(array $row): string
    {
        $targets = $row['targets'] === [] ? '' : sprintf(' [%s]', implode(', ', array_map($this->shortName(...), $row['targets'])));

        if ($row['citation'] !== null) {
            return sprintf(
                '%s pins the [%s] connection (%s form%s) at %s:%d citing [%s], which is not one of the closed floor '.
                'categories (%s). The floor list is closed — a pin that cannot name itself into it participates in '.
                'the churn rather than indexing it.',
                $this->shortName($row['class']),
                self::CENTRAL,
                $row['form'],
                $targets,
                $row['file'],
                $row['line'],
                $row['citation'],
                implode(', ', self::FLOOR_CATEGORIES),
            );
        }

        return sprintf(
            '%s pins the [%s] connection (%s form%s) at %s:%d with no `%s <category>` citation. Does this record '.
            'INDEX the churn or PARTICIPATE in it? If it indexes, cite one of (%s); if it participates, it belongs '.
            'on the tenant connection.',
            $this->shortName($row['class']),
            self::CENTRAL,
            $row['form'],
            $targets,
            $row['file'],
            $row['line'],
            self::TAG,
            implode(', ', self::FLOOR_CATEGORIES),
        );
    }

    /**
     * Class constants whose literal value is `central`, keyed by constant name.
     *
     * @return array<string, array{line: int, comments: string}>
     */
    protected function centralConstants(Node\Stmt\Class_|Node\Stmt\Trait_ $classLike): array
    {
        $constants = [];

        foreach ($classLike->stmts as $stmt) {
            if (! $stmt instanceof Node\Stmt\ClassConst) {
                continue;
            }

            foreach ($stmt->consts as $const) {
                if ($this->isCentralString($const->value)) {
                    $constants[$const->name->toString()] = [
                        'line' => $stmt->getStartLine(),
                        'comments' => $this->commentsOf($stmt),
                    ];
                }
            }
        }

        return $constants;
    }

    /**
     * Connection-pinning calls inside a class: the earliest line, the models they target, and the comments
     * around the earliest one. Catches both `Model::on(...)` (a {@see StaticCall}) and
     * `(new Model)->setConnection(...)` (a {@see MethodCall}), with the argument resolved either as a
     * `'central'` literal or as a `self::CONST` that the class defines as `central`.
     *
     * @param  array<string, array{line: int, comments: string}>  $constants
     * @param  array<string, string>  $imports
     * @return array{line: int|null, comments: string, targets: list<string>, usedConstants: list<string>}
     */
    protected function connectionCalls(Node\Stmt\Class_|Node\Stmt\Trait_ $classLike, array $constants, array $imports, NodeFinder $finder): array
    {
        /** @var list<StaticCall|MethodCall> $calls */
        $calls = $finder->find($classLike->stmts, fn (Node $n) => $n instanceof StaticCall || $n instanceof MethodCall);

        $line = null;
        $comments = '';
        $targets = [];
        $usedConstants = [];

        foreach ($calls as $call) {
            if (! $call->name instanceof Node\Identifier
                || ! in_array($call->name->toString(), self::CONNECTION_METHODS, true)
                || $call->args === []) {
                continue;
            }

            $arg = $call->args[0];

            if (! $arg instanceof Node\Arg || ! $this->resolvesToCentral($arg->value, $constants)) {
                continue;
            }

            if ($line === null || $call->getStartLine() < $line) {
                $line = $call->getStartLine();
                $comments = $this->commentsOf($call);
            }

            if ($arg->value instanceof ClassConstFetch && $arg->value->name instanceof Node\Identifier) {
                $usedConstants[$arg->value->name->toString()] = true;
            }

            $target = $this->targetOf($call);

            if ($target !== null) {
                $targets[$imports[$target] ?? $target] = true;
            }
        }

        return [
            'line' => $line,
            'comments' => $comments,
            'targets' => array_keys($targets),
            'usedConstants' => array_keys($usedConstants),
        ];
    }

    /** The class a connection-pinning call is aimed at, when statically visible. */
    protected function targetOf(StaticCall|MethodCall $call): ?string
    {
        $subject = $call instanceof StaticCall ? $call->class : $call->var;

        if ($subject instanceof New_) {
            $subject = $subject->class;
        }

        if (! $subject instanceof Node\Name) {
            return null;
        }

        $name = $subject->toString();

        return in_array($name, ['self', 'static', 'parent'], true) ? null : $name;
    }

    /**
     * @param  array<string, array{line: int, comments: string}>  $constants
     */
    protected function resolvesToCentral(?Node $node, array $constants): bool
    {
        if ($this->isCentralString($node)) {
            return true;
        }

        if ($node instanceof ClassConstFetch && $node->name instanceof Node\Identifier) {
            return isset($constants[$node->name->toString()]);
        }

        return false;
    }

    protected function isCentralString(?Node $node): bool
    {
        return $node instanceof Node\Scalar\String_ && $node->value === self::CENTRAL;
    }

    /**
     * The cited floor category, lowercased, or null when nothing cites. The value is returned even when it
     * is NOT a member of the closed list — an invented category is a distinct, reportable state from an
     * absent one, and collapsing the two would hide it.
     */
    protected function citation(string $text): ?string
    {
        if (preg_match('/'.preg_quote(self::TAG, '/').'[:=\s]+([A-Za-z0-9_-]+)/', $text, $m) !== 1) {
            return null;
        }

        return strtolower($m[1]);
    }

    /** Every comment and docblock attached to a node, flattened for tag matching. */
    protected function commentsOf(Node $node): string
    {
        return $this->commentText([$node->getDocComment(), ...$node->getComments()]);
    }

    /**
     * @param  array<int, Comment|null>  $comments
     */
    protected function commentText(array $comments): string
    {
        return implode("\n", array_map(
            fn (Comment $c) => $c->getText(),
            array_values(array_filter($comments, fn (?Comment $c) => $c instanceof Comment)),
        ))."\n";
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

    protected function isExcludedNamespace(string $namespace): bool
    {
        if ($namespace === '') {
            return false;
        }

        foreach (explode('\\', $namespace) as $segment) {
            if (in_array($segment, $this->excludedNamespaceSegments, true)) {
                return true;
            }
        }

        return false;
    }

    // ── source/AST helpers (mirrors ParticleOperationBypassAudit's own) ────────────────────────────────

    /**
     * The file's `use` imports, short name => FQN — needed to resolve a bare `BeamUxEntry::on('central')`
     * to its real namespace without a live autoloader.
     *
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
            // An unparseable file is not a pin; a doctor audit that throws on one bad file in a vendor tree
            // reports nothing at all about the other few thousand.
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
                        // `vendor` is skipped only as a NESTED dir: `forApp()` hands us `vendor/splicewire`
                        // as a root directly, and each family package there carries its own dev `vendor/`
                        // tree — re-descending into those re-scans the whole estate once per package.
                        return ! in_array($current->getFilename(), ['vendor', 'node_modules', '.git'], true);
                    }

                    return $current->getExtension() === 'php';
                },
            ),
        );

        foreach ($iterator as $file) {
            // An empty directory is yielded as a LEAF by RecursiveIteratorIterator, so the extension
            // filter above never sees it — without this guard it reaches `file_get_contents()` as a dir.
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
