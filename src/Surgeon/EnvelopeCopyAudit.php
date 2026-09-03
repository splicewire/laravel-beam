<?php

namespace Splicewire\Beam\Surgeon;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\UseItem;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Data\RendersJsonSafely;
use Splicewire\Beam\Data\ResponseBody;
use Splicewire\Beam\Doctor\Support\FacadeConformanceScope;
use Splicewire\Beam\Http\Particle\ParticleOperationController;

/**
 * A host that carries its own copy of the JSON envelope, or bends the one it inherited (api-surface-coherence
 * ticket 131, from 110's question 3: *"is there a doctor/surgeon audit shape that would catch the twelfth?"*).
 *
 * ## The defect, stated as three shapes
 *
 * Eleven hosts copied `ResponseBody`. Nine were reparented onto {@see ResponseBody} by ticket 130; the two
 * that remain (`prahsys-gateway`, `prognosix-api`) are non-beam hosts ruled out of scope by the owner. Deleting
 * copies does not stop a twelfth, and **a copied envelope is how eleven happened** — so this lint reads what a
 * class DECLARES, not what a host imports:
 *
 *  1. **`envelope-copy`** — a class named `ResponseBody` (or a `*ResponseBody` that declares a role-rule
 *     member — a payload DTO merely NAMED for its response is not an envelope) which does not extend
 *     beam's. That is the copy itself, and it is a row whether or not the copy is otherwise correct: the
 *     estate's one envelope lives in beam, and a second definition drifts by construction.
 *  2. **`envelope-copy.role`** — the role rule from beam's own docblock, on a copy or a subclass: a factory
 *     taking source material is STATIC (`success`, `exception`, `paginated`); a status modifier is FLUENT
 *     (`created`, `accepted`, `updated`, `deleted`, `invalid`, `badRequest`, `failure`, `notFound`,
 *     `forbidden`, `conflict`, `unauthorized`, `with*`). Conflating the two — `created($data)` as a static
 *     constructor — is precisely what made 130's reparent fatal (`Cannot make non static method ::created()
 *     static`), so a subclass re-declaring a member under the wrong keyword is reported before PHP does.
 *  3. **`envelope-copy.render`** — ticket 109's defect as a shape: a `toResponse()` that builds a
 *     `JsonResponse` directly (`new JsonResponse(...)`, `response()->json(...)`) rather than through
 *     {@see RendersJsonSafely::jsonResponseThatCannotThrow()}. The error envelope's own encoding failure is
 *     then the response, and the failure it existed to report is gone.
 *
 * ## What is NOT a finding, and why the lint reads declarations rather than call sites
 *
 * An operation that RETURNS a `ResponseBody` is legal and expected: beam-facade 194 made
 * {@see ParticleOperationController::finish()} pass the envelope through ahead of the payload rule, so a
 * projector may hand back `ResponseBody::success($x)->created()` and reach the wire as a 201. This lint never
 * looks at a return statement or a call chain — only at classes that declare an envelope, extend one, or
 * render one — so that path cannot produce a row, and the test suite pins it.
 *
 * ## Advisory, permanently, and the did-not-look counter
 *
 * Whether a host still declares its own envelope is a fact about the HOST, so by the estate's standing rule
 * every row here is a `Warn` and never a `Fail`. Reach is {@see FacadeConformanceScope}: the host's own
 * `app/`/`src/`/`routes/`/`database/` plus every family package it composes through the overlay, tests
 * excluded. That is the ticket's stated reach limit and it is written here on purpose: **a twelfth BEAM host
 * is caught automatically the moment it runs its doctor; a twelfth NON-beam host is caught only when someone
 * points this scope at that tree** — neither doctor nor surgeon is installed there, and this audit cannot
 * see a tree it does not live in.
 *
 * A file the parser cannot read is COUNTED, never warned about and never silently dropped — an audit that
 * enumerates what it could not inspect reads as thorough exactly where it is weakest (AGENTS.md "reach
 * before precision"). And an empty population is inconclusive, not a pass: with no envelope-shaped class
 * and no `toResponse()` renderer in scope, nothing was measured (ticket 128's shape).
 */
class EnvelopeCopyAudit implements DoctorAudit
{
    public const CHECK = 'beam.http.envelope-copy';

    public const CHECK_ROLE = 'beam.http.envelope-copy.role';

    public const CHECK_RENDER = 'beam.http.envelope-copy.render';

    /** The one envelope. Resolved by FQCN against a file's imports, never by bare mention. */
    public const ENVELOPE_CLASS = ResponseBody::class;

    /** The members that PRODUCE an envelope from source material — static, by the role rule. */
    public const STATIC_MEMBERS = ['success', 'exception', 'paginated'];

    /** The members that ADJUST an envelope that already exists — fluent, by the role rule. */
    public const FLUENT_MEMBERS = [
        'created', 'accepted', 'updated', 'deleted', 'invalid', 'badRequest', 'failure', 'notFound',
        'forbidden', 'conflict', 'unauthorized', 'withData', 'withMessage', 'withMeta', 'withDebug',
    ];

    /** The safe renderer. A `toResponse()` that calls this is the fix, not the defect. */
    public const SAFE_RENDER = 'jsonResponseThatCannotThrow';

    /** The prefilter needles — a file naming neither has nothing this lint inspects. */
    public const NEEDLES = ['ResponseBody', 'toResponse'];

    public function __construct(protected FacadeConformanceScope $scope) {}

    public static function forApp(?FacadeConformanceScope $scope = null): self
    {
        return new self($scope ?? FacadeConformanceScope::forApp());
    }

    /** @return list<Finding> */
    public function run(): array
    {
        $census = $this->census();
        $findings = [];

        foreach ($census['rows'] as $row) {
            $findings[] = Finding::warn($row['check'], $row['detail']);
        }

        if ($census['inspected'] === 0) {
            // Inconclusive, not Pass (ticket 128): an empty population is "nothing here", not "measured
            // clean". The candidate count is what separates the two readings — "read 100 files naming the
            // envelope and none declares one" is a fact; "found nothing" on its own is indistinguishable
            // from not having looked.
            return [Finding::inconclusive(self::CHECK, sprintf(
                'Read %d file%s in scope naming `ResponseBody` or `toResponse`; none declares an envelope of '
                .'its own, extends `%s`, or renders JSON through its own `toResponse()` — this host relies '
                .'on beam\'s envelope and there was nothing to inspect%s. %s',
                $census['candidates'],
                $census['candidates'] === 1 ? '' : 's',
                self::ENVELOPE_CLASS,
                $census['unparsed'] > 0
                    ? sprintf(' — and %d file%s could not be parsed and %s NOT inspected', $census['unparsed'], $census['unparsed'] === 1 ? '' : 's', $census['unparsed'] === 1 ? 'was' : 'were')
                    : '',
                'Reach is the host plus the family packages it composes; a non-beam host is reached only by '
                .'pointing the scope at its tree.',
            ))];
        }

        if ($findings === []) {
            return [Finding::pass(self::CHECK, sprintf(
                '%d class%s inspected (%d envelope-shaped, %d `toResponse()` renderer%s): no copied envelope, '
                .'every role-rule member under the right keyword, every renderer routed through '
                .'`jsonResponseThatCannotThrow()`.%s',
                $census['inspected'],
                $census['inspected'] === 1 ? '' : 'es',
                $census['envelopes'],
                $census['renderers'],
                $census['renderers'] === 1 ? '' : 's',
                $census['unparsed'] > 0
                    ? sprintf(' %d file%s could not be parsed and %s NOT inspected.', $census['unparsed'], $census['unparsed'] === 1 ? '' : 's', $census['unparsed'] === 1 ? 'was' : 'were')
                    : '',
            ))];
        }

        if ($census['unparsed'] > 0) {
            $findings[] = Finding::inconclusive(self::CHECK, sprintf(
                '%d file%s in scope could not be parsed and %s NOT inspected — the rows above are what the '
                .'readable %d class%s showed, not the whole population.',
                $census['unparsed'],
                $census['unparsed'] === 1 ? '' : 's',
                $census['unparsed'] === 1 ? 'was' : 'were',
                $census['inspected'],
                $census['inspected'] === 1 ? '' : 'es',
            ));
        }

        return $findings;
    }

    /**
     * The whole reading: rows, the candidate files read, the inspected population by kind, and the
     * did-not-look counter.
     *
     * @return array{rows: list<array{check: string, detail: string, file: string, line: int}>, candidates: int, inspected: int, envelopes: int, renderers: int, unparsed: int}
     */
    public function census(): array
    {
        $rows = [];
        $inspected = $envelopes = $renderers = $unparsed = $candidates = 0;

        foreach ($this->scope->sourcesContaining(self::NEEDLES) as $path => $source) {
            // Beam's own `src/` declares the envelope this lint measures everything else against; the
            // definition would flag itself as a copy.
            if (FacadeConformanceScope::isOwningPackageSource($path)) {
                continue;
            }

            $candidates++;

            $reading = $this->inspectSource($source, FacadeConformanceScope::displayPath($path));

            if ($reading === null) {
                $unparsed++;

                continue;
            }

            $inspected += $reading['inspected'];
            $envelopes += $reading['envelopes'];
            $renderers += $reading['renderers'];

            foreach ($reading['rows'] as $row) {
                $rows[] = $row;
            }
        }

        usort($rows, fn (array $a, array $b): int => [$a['file'], $a['line']] <=> [$b['file'], $b['line']]);

        return [
            'rows' => $rows,
            'candidates' => $candidates,
            'inspected' => $inspected,
            'envelopes' => $envelopes,
            'renderers' => $renderers,
            'unparsed' => $unparsed,
        ];
    }

    /**
     * Inspect ONE file. Pure over source — no disk, container, or DB. `null` means the parser could not
     * read it, which the caller counts rather than reports.
     *
     * @return array{rows: list<array{check: string, detail: string, file: string, line: int}>, inspected: int, envelopes: int, renderers: int}|null
     */
    public function inspectSource(string $source, string $file = '(source)'): ?array
    {
        $ast = $this->parse($source);

        if ($ast === null) {
            return null;
        }

        $imports = $this->importsOf($ast);
        $rows = [];
        $inspected = $envelopes = $renderers = 0;

        foreach ($this->classesOf($ast) as [$namespace, $class]) {
            /** @var Class_ $class */
            $short = $class->name?->toString();

            if ($short === null) {
                continue;
            }

            $fqcn = $namespace === null ? $short : $namespace.'\\'.$short;
            $parent = $class->extends === null ? null : $this->resolveName($class->extends, $imports, $namespace);

            // Envelope-shaped is the NAME `ResponseBody`, or a `*ResponseBody` that declares at least one
            // role-rule member. The second clause is precision, not reach: `SaleResponseBody` at a
            // non-beam host is a payload DTO named for the response it carries, not an envelope, and a
            // name-only test read eleven such DTOs as copies (measured 2026-09-02 against
            // `prahsys-gateway`). A wrong label on a real finding is tolerable; a fabricated finding is not.
            $envelopeShaped = $short === 'ResponseBody'
                || (str_ends_with($short, 'ResponseBody') && $this->declaresRoleMember($class));
            $extendsEnvelope = $parent === self::ENVELOPE_CLASS;
            $isCopy = $envelopeShaped && ! $extendsEnvelope;
            $render = $this->renderer($class, $imports, $namespace);

            if (! $envelopeShaped && ! $extendsEnvelope && $render === null) {
                continue;
            }

            $inspected++;

            if ($envelopeShaped || $extendsEnvelope) {
                $envelopes++;
            }

            if ($render !== null) {
                $renderers++;
            }

            $roleBreaks = ($envelopeShaped || $extendsEnvelope) ? $this->roleBreaks($class) : [];
            $unsafeRender = $render !== null && ! $render['safe'];

            if ($isCopy) {
                $rows[] = [
                    'check' => self::CHECK,
                    'file' => $file,
                    'line' => $class->getStartLine(),
                    'detail' => sprintf(
                        '%s:%d `%s` declares its own envelope rather than importing `%s`. A copied envelope is '
                        .'how eleven hosts came to carry one (api-surface-coherence 110/130): reparent it, or '
                        .'delete it and import beam\'s.%s%s',
                        $file,
                        $class->getStartLine(),
                        $fqcn,
                        self::ENVELOPE_CLASS,
                        $roleBreaks === [] ? '' : ' Role rule: '.$this->describeRoleBreaks($roleBreaks).'.',
                        $unsafeRender ? ' Its `toResponse()` builds the JsonResponse directly, so an encoding failure replaces the error it reports.' : '',
                    ),
                ];

                continue;
            }

            if ($roleBreaks !== []) {
                $rows[] = [
                    'check' => self::CHECK_ROLE,
                    'file' => $file,
                    'line' => $class->getStartLine(),
                    'detail' => sprintf(
                        '%s:%d `%s` extends the envelope and re-declares a member under the wrong keyword — %s. '
                        .'Static PRODUCES from source material; fluent ADJUSTS an envelope that exists. PHP '
                        .'fatals on the mismatch the moment the parent is loaded.',
                        $file,
                        $class->getStartLine(),
                        $fqcn,
                        $this->describeRoleBreaks($roleBreaks),
                    ),
                ];
            }

            if ($unsafeRender) {
                $rows[] = [
                    'check' => self::CHECK_RENDER,
                    'file' => $file,
                    'line' => $render['line'],
                    'detail' => sprintf(
                        '%s:%d `%s::toResponse()` builds its JsonResponse directly rather than through '
                        .'`%s()`, so a payload the encoder cannot serialise becomes the response in place of '
                        .'the error it was carrying (api-surface-coherence 109). Use the `%s` trait.',
                        $file,
                        $render['line'],
                        $fqcn,
                        self::SAFE_RENDER,
                        'RendersJsonSafely',
                    ),
                ];
            }
        }

        return ['rows' => $rows, 'inspected' => $inspected, 'envelopes' => $envelopes, 'renderers' => $renderers];
    }

    /**
     * A class's `toResponse()` and whether it renders safely; null when the class declares none.
     *
     * "Renders JSON directly" is `new JsonResponse(...)` (resolved by import, or bare when unimported) or a
     * `->json(...)` call. A body that calls the safe renderer is safe whatever else it contains; a body that
     * builds nothing JSON-shaped (a redirect, a delegation to `parent::`) is not this lint's concern.
     *
     * @param  array<string, string>  $imports
     * @return array{line: int, safe: bool}|null
     */
    protected function renderer(Class_ $class, array $imports, ?string $namespace): ?array
    {
        foreach ($class->getMethods() as $method) {
            if ($method->name->toString() !== 'toResponse' || $method->stmts === null) {
                continue;
            }

            $finder = new NodeFinder;
            $safe = false;
            $direct = false;

            foreach ($finder->findInstanceOf($method->stmts, MethodCall::class) as $call) {
                /** @var MethodCall $call */
                $name = $call->name instanceof Node\Identifier ? $call->name->toString() : null;

                if ($name === self::SAFE_RENDER) {
                    $safe = true;
                }

                if ($name === 'json') {
                    $direct = true;
                }
            }

            foreach ($finder->findInstanceOf($method->stmts, StaticCall::class) as $call) {
                /** @var StaticCall $call */
                if ($call->name instanceof Node\Identifier && $call->name->toString() === self::SAFE_RENDER) {
                    $safe = true;
                }
            }

            foreach ($finder->findInstanceOf($method->stmts, New_::class) as $new) {
                /** @var New_ $new */
                if (! $new->class instanceof Node\Name) {
                    continue;
                }

                $resolved = $this->resolveName($new->class, $imports, $namespace);

                if ($resolved === 'Illuminate\Http\JsonResponse' || $new->class->getLast() === 'JsonResponse') {
                    $direct = true;
                }
            }

            return ['line' => $method->getStartLine(), 'safe' => $safe || ! $direct];
        }

        return null;
    }

    /** Whether a class declares any member the role rule names — the envelope's own vocabulary. */
    protected function declaresRoleMember(Class_ $class): bool
    {
        foreach ($class->getMethods() as $method) {
            $name = $method->name->toString();

            if (in_array($name, self::FLUENT_MEMBERS, true) || in_array($name, self::STATIC_MEMBERS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Members declared under the keyword the role rule forbids.
     *
     * @return list<array{member: string, declared: string, expected: string}>
     */
    protected function roleBreaks(Class_ $class): array
    {
        $breaks = [];

        foreach ($class->getMethods() as $method) {
            /** @var ClassMethod $method */
            $name = $method->name->toString();

            if (in_array($name, self::FLUENT_MEMBERS, true) && $method->isStatic()) {
                $breaks[] = ['member' => $name, 'declared' => 'static', 'expected' => 'fluent'];
            }

            if (in_array($name, self::STATIC_MEMBERS, true) && ! $method->isStatic()) {
                $breaks[] = ['member' => $name, 'declared' => 'fluent', 'expected' => 'static'];
            }
        }

        return $breaks;
    }

    /** @param  list<array{member: string, declared: string, expected: string}>  $breaks */
    protected function describeRoleBreaks(array $breaks): string
    {
        return implode(', ', array_map(
            fn (array $b): string => sprintf('`%s()` is %s and must be %s', $b['member'], $b['declared'], $b['expected']),
            $breaks,
        ));
    }

    /**
     * Every class declaration with its namespace, top-level or inside a `namespace` block.
     *
     * @param  list<Node>  $ast
     * @return list<array{0: string|null, 1: Class_}>
     */
    protected function classesOf(array $ast): array
    {
        $out = [];

        foreach ($ast as $stmt) {
            if ($stmt instanceof Namespace_) {
                $namespace = $stmt->name?->toString();

                foreach ((new NodeFinder)->findInstanceOf($stmt->stmts ?? [], Class_::class) as $class) {
                    $out[] = [$namespace, $class];
                }

                continue;
            }

            foreach ((new NodeFinder)->findInstanceOf([$stmt], Class_::class) as $class) {
                $out[] = [null, $class];
            }
        }

        return $out;
    }

    /**
     * The file's `use` map, short name => FQCN. Mirrors {@see ParticleWriteBypassAudit::importsOf()}.
     *
     * @param  list<Node>  $ast
     * @return array<string, string>
     */
    protected function importsOf(array $ast): array
    {
        $imports = [];

        foreach ((new NodeFinder)->findInstanceOf($ast, UseItem::class) as $use) {
            /** @var UseItem $use */
            $imports[$use->alias?->toString() ?? $use->name->getLast()] = $use->name->toString();
        }

        return $imports;
    }

    /**
     * @param  array<string, string>  $imports
     */
    protected function resolveName(Node\Name $name, array $imports, ?string $namespace): string
    {
        if ($name->isFullyQualified()) {
            return ltrim($name->toString(), '\\');
        }

        $parts = explode('\\', $name->toString());
        $head = array_shift($parts);

        if (isset($imports[$head])) {
            return $parts === [] ? $imports[$head] : $imports[$head].'\\'.implode('\\', $parts);
        }

        return $namespace === null ? $name->toString() : $namespace.'\\'.$name->toString();
    }

    /**
     * @return list<Node>|null
     */
    protected function parse(string $source): ?array
    {
        try {
            return (new ParserFactory)->createForNewestSupportedVersion()->parse($source);
        } catch (\Throwable) {
            return null;
        }
    }
}
