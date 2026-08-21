<?php

namespace Splicewire\Beam\Surgeon;

use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\UseItem;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Rushing\Surgeon\Operation\FixableFinding;
use Rushing\Surgeon\Operation\OperationSuggestion;
use Rushing\Surgeon\Operation\SuggestsOperations;
use Splicewire\Beam\BeamManager;
use Splicewire\Beam\BeamServiceProvider;
use Splicewire\Beam\Doctor\ConfigFacadeReferenceAudit;
use Splicewire\Beam\Doctor\StubStaticReferenceAudit;
use Splicewire\Beam\Doctor\Support\FacadeConformanceScope;
use Splicewire\Beam\Write\ParticleWriter;

/**
 * **The write seam being bypassed**: a call site that service-locates {@see ParticleWriter} and calls
 * `->write()` on it, where `Beam::write()` is the declared seam (beam-facade tickets 04, 10 §6 and 19).
 * Carries a second, distinct finding type — a call site outside the owning package naming
 * {@see BeamManager} directly, which is 06's own drift shape and did not exist as a
 * category when ticket 10 was written.
 *
 * **This audit is what supersedes `StaticBridgeAudit`** — and "supersedes" is the accurate verb rather
 * than "replaces". Ticket 07 believed the bridge audit was the estate's progress meter about to go
 * quiet; ticket 10 found it was 32 lines performing no scanning of any kind, its entire mechanism a
 * `class_exists()`. It could not see a call site, and flipped to Pass the instant ticket 18 deleted the
 * file regardless of what still imported it. What went away was a one-bit tripwire that had already
 * served its purpose. What arrives here is the instrument it was mistaken for.
 *
 * ## Never key on the class name — the construction constraint, not a preference
 * Ticket 10's census measured the naive shapes and one result decided this audit's whole design: the
 * "`ParticleWriter` reached by constructor DI" shape was **9 naive hits and 9 false positives, 100%**.
 * Every one was a promoted constructor property — precisely the pattern the facade exists to preserve,
 * and precisely ticket 04's `SchemaTargetResolver` rejection ("read the position of a hit, not just the
 * count") made mechanical. A name-keyed check is wrong nine times out of nine, which is why this is an
 * AST audit keyed on the **resolution verb followed by a `->write()` chain** and nothing else.
 *
 * Two consequences worth stating because they read as gaps and are not:
 * - `new ParticleWriter(...)` is **never** flagged. The estate's direct instantiations pass a specific
 *   write gate per call, which is uncollapsible by construction — `Beam::write()` resolves the
 *   container-bound writer, and `ParticleWriter` is bound `bind()` precisely so a gate can be rebound
 *   per call (ticket 05). `ParticleFrameResourceHandler` and `BeamUxEntryBodyController` are the two
 *   ticket 10 named; the population has grown since and the rule scales with it for free.
 * - A resolution verb whose result is **passed as an argument** rather than written through —
 *   `new ParticleStorageDriver($app->make(ParticleWriter::class))` in `BeamUxServiceProvider`, the
 *   binding in `BeamServiceProvider` — is not a bypass but ordinary wiring. Requiring the `->write()`
 *   chain excludes it structurally rather than by an exception list.
 *
 * ## Advisory, and expected to have real findings
 * Ticket 19's acceptance predicted near-zero here on the grounds that the sweep had run. That
 * expectation was wrong, and the audit is right: the sweep (tickets 13–18) was a **`use`-line move** in
 * every population without exception — 07 planned `surgeon:move`, not `surgeon:rewrite`, and 18
 * confirmed one import line per file across ~190 files. Collapsing `app(ParticleWriter::class)->write()`
 * into `Beam::write()` is an expression rewrite that was never in the sweep's scope, so ticket 04's
 * authored `write()` sites are still written the long way. They are a burn-down backlog, which is what
 * advisory registration is for.
 *
 * Every finding is {@see OperationSuggestion::advisory()}: the collapse is mechanical in shape but
 * touches imports and, at 3 of the authored sites, named arguments (`after:`), and no deterministic
 * operation in the estate handles it. Nominating one is a judgment call this regime deliberately leaves
 * to an agent — the same posture {@see ParticleOperationBypassAudit} takes.
 *
 * Sibling of the two text-level doctor checks ({@see StubStaticReferenceAudit} and
 * {@see ConfigFacadeReferenceAudit}); registered advisory in
 * {@see BeamServiceProvider::registerFacadeConformanceAudits()}.
 */
class ParticleWriteBypassAudit implements DoctorAudit, SuggestsOperations
{
    public const CHECK = 'beam.facade.write-bypass';

    /** The container-resolution verbs. `app()`/`resolve()` as functions; `make()`/`makeWith()` on a container. */
    public const RESOLUTION_FUNCTIONS = ['app', 'resolve'];

    public const RESOLUTION_METHODS = ['make', 'makeWith', 'resolve'];

    /** The seam's own class. Matched by short name against the file's imports, never by bare mention. */
    public const WRITER_CLASS = ParticleWriter::class;

    public function __construct(protected FacadeConformanceScope $scope) {}

    public static function forApp(?FacadeConformanceScope $scope = null): self
    {
        return new self($scope ?? FacadeConformanceScope::forApp());
    }

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        return array_map(fn (FixableFinding $f): Finding => $f->finding, $this->suggestOperations());
    }

    /**
     * @return list<FixableFinding>
     */
    public function suggestOperations(): array
    {
        $rows = $this->bypasses();

        if ($rows === []) {
            return [new FixableFinding(Finding::pass(
                self::CHECK,
                'No call site service-locates ParticleWriter to write, and none names BeamManager directly.',
            ))];
        }

        return array_map(fn (array $row): FixableFinding => new FixableFinding(
            Finding::warn(self::CHECK, $this->detail($row)),
            OperationSuggestion::advisory($this->suggestion($row), 'splicewire/laravel-beam'),
        ), $rows);
    }

    /**
     * Every bypass found, as sorted rows — the artifact payload and the work-list.
     *
     * @return list<array{file: string, line: int, kind: string, detail: string}>
     */
    public function bypasses(): array
    {
        $rows = [];

        $needles = ['ParticleWriter', 'BeamManager'];

        foreach ($this->scope->sourcesContaining($needles) as $path => $source) {
            // The owning package's `src/` holds BeamManager::write()'s body — `$this->container->
            // make(ParticleWriter::class)->write(...)`, this shape verbatim — and BeamManager is
            // obviously named there. The fix would flag itself.
            if (FacadeConformanceScope::isOwningPackageSource($path)) {
                continue;
            }

            foreach ($this->bypassesInSource($source) as $row) {
                $rows[] = ['file' => FacadeConformanceScope::displayPath($path)] + $row;
            }
        }

        usort($rows, fn (array $a, array $b): int => [$a['file'], $a['line']] <=> [$b['file'], $b['line']]);

        return $rows;
    }

    /**
     * Parse ONE file. Pure over source — no disk, container, or DB.
     *
     * @return list<array{line: int, kind: string, detail: string}>
     */
    public function bypassesInSource(string $source): array
    {
        $ast = $this->parse($source);

        if ($ast === null) {
            return [];
        }

        $imports = $this->importsOf($ast);
        $finder = new NodeFinder;
        $rows = [];

        /** @var list<MethodCall> $calls */
        $calls = $finder->findInstanceOf($ast, MethodCall::class);

        foreach ($calls as $call) {
            if (! $call->name instanceof Node\Identifier || $call->name->toString() !== 'write') {
                continue;
            }

            if (! $this->resolvesTheWriter($call->var, $imports)) {
                continue;
            }

            $rows[] = [
                'line' => $call->getStartLine(),
                'kind' => 'service-location',
                'detail' => 'resolves ParticleWriter from the container and calls ->write() on it',
            ];
        }

        foreach ($this->managerReferences($ast, $imports) as $line) {
            $rows[] = [
                'line' => $line,
                'kind' => 'manager-reference',
                'detail' => 'names BeamManager directly instead of going through the facade',
            ];
        }

        usort($rows, fn (array $a, array $b): int => $a['line'] <=> $b['line']);

        return $rows;
    }

    /**
     * Whether an expression is a container resolution of {@see ParticleWriter} — `app(X::class)`,
     * `resolve(X::class)`, `$app->make(X::class)`, `$this->app->make(X::class)`. The `::class` argument is
     * what makes this a resolution rather than a mention.
     *
     * @param  array<string, string>  $imports
     */
    protected function resolvesTheWriter(Node\Expr $expr, array $imports): bool
    {
        if ($expr instanceof FuncCall) {
            if (! $expr->name instanceof Node\Name) {
                return false;
            }

            if (! in_array($expr->name->toString(), self::RESOLUTION_FUNCTIONS, true)) {
                return false;
            }

            return $this->firstArgIsWriterClass($expr->getArgs(), $imports);
        }

        if ($expr instanceof MethodCall) {
            if (! $expr->name instanceof Node\Identifier) {
                return false;
            }

            if (! in_array($expr->name->toString(), self::RESOLUTION_METHODS, true)) {
                return false;
            }

            return $this->firstArgIsWriterClass($expr->getArgs(), $imports);
        }

        return false;
    }

    /**
     * @param  list<Node\Arg>  $args
     * @param  array<string, string>  $imports
     */
    protected function firstArgIsWriterClass(array $args, array $imports): bool
    {
        if ($args === []) {
            return false;
        }

        $value = $args[0]->value;

        if (! $value instanceof ClassConstFetch || ! $value->class instanceof Node\Name) {
            return false;
        }

        if (! $value->name instanceof Node\Identifier || strtolower($value->name->toString()) !== 'class') {
            return false;
        }

        return $this->resolveName($value->class, $imports) === self::WRITER_CLASS;
    }

    /**
     * Lines naming {@see BeamManager} — 06's drift shape. The instance took a distinct
     * name precisely so the facade could keep the short one; a call site reaching past the facade to the
     * manager is naming an implementation detail whose whole purpose was to stay unnamed.
     *
     * @param  list<Node>  $ast
     * @param  array<string, string>  $imports
     * @return list<int>
     */
    protected function managerReferences(array $ast, array $imports): array
    {
        $lines = [];

        foreach ((new NodeFinder)->findInstanceOf($ast, Node\Name::class) as $name) {
            /** @var Node\Name $name */
            if ($this->resolveName($name, $imports) === FacadeConformanceScope::MANAGER_CLASS) {
                $lines[$name->getStartLine()] = true;
            }
        }

        $lines = array_keys($lines);
        sort($lines);

        return $lines;
    }

    /**
     * The file's `use` map, short name => FQCN, so a short-name reference resolves without a full
     * name-resolver pass. Mirrors the technique in {@see CentralPinJustificationAudit}.
     *
     * @param  list<Node>  $ast
     * @return array<string, string>
     */
    protected function importsOf(array $ast): array
    {
        $imports = [];

        foreach ((new NodeFinder)->findInstanceOf($ast, UseItem::class) as $use) {
            /** @var UseItem $use */
            $fqcn = $use->name->toString();
            $alias = $use->alias?->toString() ?? $use->name->getLast();
            $imports[$alias] = $fqcn;
        }

        return $imports;
    }

    /**
     * @param  array<string, string>  $imports
     */
    protected function resolveName(Node\Name $name, array $imports): string
    {
        if ($name->isFullyQualified()) {
            return ltrim($name->toString(), '\\');
        }

        $parts = explode('\\', $name->toString());
        $head = array_shift($parts);

        if (isset($imports[$head])) {
            return $parts === [] ? $imports[$head] : $imports[$head].'\\'.implode('\\', $parts);
        }

        return $name->toString();
    }

    /**
     * @param  array{file: string, line: int, kind: string, detail: string}  $row
     */
    protected function detail(array $row): string
    {
        return sprintf('%s:%d %s.', $row['file'], $row['line'], $row['detail']);
    }

    /**
     * @param  array{file: string, line: int, kind: string, detail: string}  $row
     */
    protected function suggestion(array $row): string
    {
        if ($row['kind'] === 'manager-reference') {
            return sprintf(
                'Reach BeamManager through %s instead of naming it — the manager carries the distinct name '.
                'only because PHP forbids one class declaring an instance and a static method of the same '.
                'name (ticket 06); the facade is the surface.',
                FacadeConformanceScope::FACADE_CLASS,
            );
        }

        return sprintf(
            'Collapse to Beam::write(...) at %s:%d — the facade forwards variadically to the same '.
            'container-resolved ParticleWriter, so named arguments (`after:`) carry through unchanged. '.
            'Leave it alone if this site deliberately rebinds the write gate.',
            $row['file'],
            $row['line'],
        );
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
