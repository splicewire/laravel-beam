<?php

namespace Splicewire\Beam\Surgeon;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\UseItem;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Rushing\Surgeon\Operation\FixableFinding;
use Rushing\Surgeon\Operation\OperationSuggestion;
use Rushing\Surgeon\Operation\SuggestsOperations;
use Splicewire\Beam\BeamManager;
use Splicewire\Beam\Doctor\Support\FacadeConformanceScope;

/**
 * **The composed accessor written out longhand**: `config($key, Beam::table($stem))`, which
 * {@see BeamManager::tableFor()} exists to collapse (beam-facade tickets 02, 04 and 19).
 *
 * This is the estate's *only* unambiguous composed accessor — ticket 02's census surfaced exactly one
 * shape clearing the bar's leg (ii) on authored origins alone, and ticket 04 licensed `tableFor()` for
 * it at 12 authored sites across 3 repos. So the shape is not a hypothesis about future drift; it is a
 * measured idiom, and this check keeps the collapse from un-collapsing.
 *
 * ## Two exceptions the census forced, both mechanical rather than listed
 * - **`BeamManager.php`'s own docblock** spells the shape out verbatim in order to document what
 *   `tableFor()` replaces. It is shape 3's canonical form sitting inside shape 3's fix — the check must
 *   except its own documentation. Handled by the owning-package exclusion in
 *   {@see FacadeConformanceScope::isOwningPackageSource()}, and doubly by working on the AST, where a
 *   docblock is not an expression at all.
 * - **`laravel-beam/tests/Facade/BeamFacadeTest.php:72,77,81`** asserts on already-conformant
 *   `Beam::tableFor(...)` calls, which any loose "`Beam::table` near `config`" rule would catch despite
 *   their being the fix. Handled by the `tests/` jurisdiction rule, and again structurally: this reads
 *   `config()`/`env()` calls whose *default argument* is `Beam::table()`, which a `tableFor()` assertion
 *   is not.
 *
 * ## `env()` is in this audit's grammar, not the config audit's
 * Ticket 10 left that split open. It belongs here: this check keys on the **shape** — a lookup whose
 * fallback is a prefixed table name — and `env('BEAM_THREADS_TABLE', Beam::table('threads'))` is that
 * shape wearing a different verb. {@see \Splicewire\Beam\Doctor\ConfigFacadeReferenceAudit} keys on
 * **where** (a config template evaluated before the container exists), not on what the expression says.
 * A composed `env()` default sitting in a package config file is legitimately both, flagged twice for
 * two different reasons — a load-order bomb *and* an un-collapsed idiom. The one live specimen of that
 * exact form was `beam-threads`' config, which ticket 17 deleted.
 *
 * ## Advisory
 * Ticket 17's resolution is why the remaining population is not zero: dropping `beam.threads.tables`
 * left all 13 downstream readers working byte-identically, because their `Beam::table()` fallback simply
 * became the live path. Correct, and still the longhand — `Beam::tableFor($key, $stem)` says the same
 * thing in one call and keeps the argument order that makes transposition impossible to write by
 * accident. A burn-down list, registered advisory.
 *
 * The suggestion is {@see OperationSuggestion::advisory()} rather than a deterministic operation: the
 * rewrite is a two-argument collapse and genuinely mechanical, but no operation in the estate handles
 * it, and deciding a repetition deserves one is the agent's call — the seam
 * {@see OperationSuggestion} was built to preserve.
 */
class ComposedTableConfigAudit implements DoctorAudit, SuggestsOperations
{
    public const CHECK = 'beam.facade.composed-table-config';

    /** The lookup verbs whose *default* argument this check reads. See the class docblock on `env()`. */
    public const LOOKUP_FUNCTIONS = ['config', 'env'];

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
        $rows = $this->compositions();

        if ($rows === []) {
            return [new FixableFinding(Finding::pass(
                self::CHECK,
                'No call site writes config($key, Beam::table($stem)) longhand — the composed accessor holds.',
            ))];
        }

        return array_map(fn (array $row): FixableFinding => new FixableFinding(
            Finding::warn(self::CHECK, sprintf(
                "%s:%d writes %s('%s', Beam::table('%s')) longhand.",
                $row['file'],
                $row['line'],
                $row['verb'],
                $row['key'],
                $row['stem'],
            )),
            OperationSuggestion::advisory(sprintf(
                "Collapse to Beam::tableFor('%s', '%s') — same result, one call, and the argument order ".
                'is the one the seam declares (key first, stem second: transposing it yields a wrong '.
                'table name silently, which is the failure class the seam exists to prevent).',
                $row['key'],
                $row['stem'],
            ), 'splicewire/laravel-beam'),
        ), $rows);
    }

    /**
     * Every longhand composition, as sorted rows — the artifact payload and the work-list.
     *
     * @return list<array{file: string, line: int, verb: string, key: string, stem: string}>
     */
    public function compositions(): array
    {
        $rows = [];

        foreach ($this->scope->sourcesContaining(['Beam::table(']) as $path => $source) {
            if (FacadeConformanceScope::isOwningPackageSource($path)) {
                continue;
            }

            foreach ($this->compositionsInSource($source) as $row) {
                $rows[] = ['file' => FacadeConformanceScope::displayPath($path)] + $row;
            }
        }

        usort($rows, fn (array $a, array $b): int => [$a['file'], $a['line']] <=> [$b['file'], $b['line']]);

        return $rows;
    }

    /**
     * Parse ONE file. Pure over source — no disk, container, or DB.
     *
     * @return list<array{line: int, verb: string, key: string, stem: string}>
     */
    public function compositionsInSource(string $source): array
    {
        $ast = $this->parse($source);

        if ($ast === null) {
            return [];
        }

        $imports = $this->importsOf($ast);
        $rows = [];

        /** @var list<FuncCall> $calls */
        $calls = (new NodeFinder)->findInstanceOf($ast, FuncCall::class);

        foreach ($calls as $call) {
            if (! $call->name instanceof Node\Name) {
                continue;
            }

            $verb = $call->name->toString();

            if (! in_array($verb, self::LOOKUP_FUNCTIONS, true)) {
                continue;
            }

            $args = $call->getArgs();

            if (count($args) < 2) {
                continue;
            }

            $stem = $this->tableStem($args[1]->value, $imports);

            if ($stem === null) {
                continue;
            }

            $rows[] = [
                'line' => $call->getStartLine(),
                'verb' => $verb,
                'key' => $args[0]->value instanceof Node\Scalar\String_ ? $args[0]->value->value : '…',
                'stem' => $stem,
            ];
        }

        usort($rows, fn (array $a, array $b): int => $a['line'] <=> $b['line']);

        return $rows;
    }

    /**
     * The stem in `Beam::table('stem')` when the expression is exactly that, else `null`. Accepts the
     * bridge as well as the facade: a longhand composition on the deleted class is doubly wrong, and
     * reporting it under one check beats splitting the same expression across two.
     *
     * @param  array<string, string>  $imports
     */
    protected function tableStem(Node\Expr $expr, array $imports): ?string
    {
        if (! $expr instanceof StaticCall || ! $expr->class instanceof Node\Name) {
            return null;
        }

        if (! $expr->name instanceof Node\Identifier || $expr->name->toString() !== 'table') {
            return null;
        }

        $class = $this->resolveName($expr->class, $imports);

        if (! in_array($class, [FacadeConformanceScope::FACADE_CLASS, FacadeConformanceScope::BRIDGE_CLASS], true)) {
            return null;
        }

        $args = $expr->getArgs();

        if ($args === [] || ! $args[0]->value instanceof Node\Scalar\String_) {
            return '…';
        }

        return $args[0]->value->value;
    }

    /**
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
