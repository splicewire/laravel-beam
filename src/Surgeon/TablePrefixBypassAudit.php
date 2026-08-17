<?php

namespace Splicewire\Beam\Surgeon;

use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Stmt\Property;
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
 * **The table-prefix seam being bypassed**: a literal `beam_`-prefixed table name written where
 * {@see BeamManager::table()} is the single resolver (beam-facade tickets 10 §4 and 19).
 *
 * The seam's whole claim is that a retrofit host — Beam dropped into an app that already owns `posts`
 * or `teams` — changes one config value and every model `getTable()`, every migration, and every
 * `->constrained()` follows. A hardcoded `'beam_ux_entries'` silently opts that table out. It is the
 * one shape on this regime that is not drift *away from a new facade* but a leak in the seam the facade
 * was always about, which is why ticket 10 called it "a different and arguably higher-value check".
 *
 * ## Five checks wearing one regex — why the shape list is the audit
 * A bare `'beam_` search is **~91% false positives** (155 naive hits, ~141 of which must not be
 * flagged), because that token also spells:
 * - morph-map aliases — `Relation::morphMap(['beam_particle' => …])`, which are wire identifiers, not tables;
 * - marketplace listing *kinds* — `'beam_extension'`;
 * - index names;
 * - legacy `Schema::rename('beam_market_packages_releases', …)` targets, which **must** stay literal or
 *   the rename stops finding the table it is renaming;
 * - and actual table names, the only ones that are findings.
 *
 * So the check is constructed as a closed list of **positions where a string is a table name** —
 * `protected $table =`, `Schema::create|table|hasTable|dropIfExists(...)`, `DB::table(...)` — and
 * nothing else. `Schema::rename()` is absent from that list deliberately, not accidentally.
 *
 * ## Expected population: 10, enumerated in advance
 * Ticket 20 measured it with exactly these exclusions applied and predicted **10 findings / 6 files / 4
 * packages** — `beam_ux_entries` ×5 (a stub ×4 plus its model), `beam_billable` ×4 (a stub ×2 plus two
 * models), and `beam_thread_message_sidecar_null` ×1. Two readings ride with the number:
 * - **The stub-plus-its-model pairs are the audit working as designed.** A stub that creates a table and
 *   the model that reads it are two authored origins of one bypass and both need the fix. Do not dedupe
 *   them into one finding; fixing the migration alone leaves the model pointing at the unprefixed name.
 * - **`SidecarNull` is a null-object sentinel** — `beam_thread_message_sidecar_null` is a name no table
 *   ever bears. It is this shape's one judgment call rather than a hit, and it is reported anyway: an
 *   advisory check that pre-decides which of its own findings the reader may dismiss has started making
 *   the judgment 04 reserved for a human ("read the position of a hit, not just the count").
 *
 * ## What is deliberately NOT here
 * Ticket 20 ruled the `beam.tables.submissions` leak — 8 calls across 9 files in 5 hosts — **out of
 * scope, and explicitly declined a predicate for it**, on grounds that are not false-positive grounds: a
 * key-name predicate on `config('beam.tables.*')` would be zero-FP. It was declined because **the
 * finding would nominate the wrong repair**. Those files are orphaned forks of the retired
 * `laravel-beam-submissions` migration carrying a divergent DDL, so the fix is to delete the file and
 * reconcile against the canonical stub, not to hand-edit an expression inside it. Covering it would also
 * mean carving an exception into the dated-migration rule this whole regime's construction rests on.
 * **Do not "fix" the exclusion that omits them.**
 *
 * Advisory, and unlikely ever to gate: this is a burn-down list whose every item is a real schema
 * decision about a table that already exists in deployed databases.
 */
class TablePrefixBypassAudit implements DoctorAudit, SuggestsOperations
{
    public const CHECK = 'beam.facade.table-prefix-bypass';

    /** The prefix the seam owns by default. `Beam::table('x')` resolves to `<prefix>x`. */
    public const DEFAULT_PREFIX = 'beam_';

    /**
     * The `Schema::` verbs whose first string argument IS a table name. `rename` is absent by design —
     * its arguments must stay literal or the rename cannot find its target.
     *
     * @var list<string>
     */
    public const SCHEMA_TABLE_METHODS = ['create', 'table', 'hasTable', 'dropIfExists', 'drop'];

    /** The `DB::` verbs whose first string argument is a table name. */
    public const DB_TABLE_METHODS = ['table'];

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
                'No hardcoded beam_-prefixed table name in a table position — the prefix seam holds.',
            ))];
        }

        return array_map(fn (array $row): FixableFinding => new FixableFinding(
            Finding::warn(self::CHECK, sprintf(
                "%s:%d hardcodes '%s' in a %s position, bypassing the table-prefix seam.",
                $row['file'],
                $row['line'],
                $row['table'],
                $row['form'],
            )),
            OperationSuggestion::advisory(sprintf(
                "Resolve through the seam: Beam::table('%s'). A retrofit host that sets ".
                "beam.core.table_prefix (to a custom prefix, or to '') changes every other Beam table and ".
                'leaves this one behind — a wrong-table bug that surfaces as a missing table at runtime, not '.
                'at migrate time.',
                $row['stem'],
            ), 'splicewire/laravel-beam'),
        ), $rows);
    }

    /**
     * Every prefix bypass found, as sorted rows — the artifact payload and the work-list.
     *
     * @return list<array{file: string, line: int, form: string, table: string, stem: string}>
     */
    public function bypasses(): array
    {
        $rows = [];

        foreach ($this->scope->sourcesContaining(["'".self::DEFAULT_PREFIX, '"'.self::DEFAULT_PREFIX]) as $path => $source) {
            if (FacadeConformanceScope::isOwningPackageSource($path)) {
                continue;
            }

            foreach ($this->bypassesInSource($source) as $row) {
                $rows[] = ['file' => FacadeConformanceScope::displayPath($path)] + $row;
            }
        }

        usort($rows, fn (array $a, array $b): int => [$a['file'], $a['line'], $a['form']] <=> [$b['file'], $b['line'], $b['form']]);

        return $rows;
    }

    /**
     * Parse ONE file. Pure over source — no disk, container, or DB.
     *
     * @return list<array{line: int, form: string, table: string, stem: string}>
     */
    public function bypassesInSource(string $source): array
    {
        $ast = $this->parse($source);

        if ($ast === null) {
            return [];
        }

        $finder = new NodeFinder;
        $rows = [];

        // Form 1 — `protected $table = 'beam_…'`.
        foreach ($finder->findInstanceOf($ast, Property::class) as $property) {
            /** @var Property $property */
            foreach ($property->props as $prop) {
                if ($prop->name->toString() !== 'table' || ! $prop->default instanceof Node\Scalar\String_) {
                    continue;
                }

                if ($row = $this->row($prop->default->value, $prop->default->getStartLine(), 'protected $table')) {
                    $rows[] = $row;
                }
            }
        }

        // Forms 2 and 3 — `Schema::create|table|hasTable|dropIfExists|drop('beam_…')` and `DB::table('beam_…')`.
        foreach ($finder->findInstanceOf($ast, StaticCall::class) as $call) {
            /** @var StaticCall $call */
            $form = $this->tableCallForm($call);

            if ($form === null) {
                continue;
            }

            $args = $call->getArgs();

            if ($args === [] || ! $args[0]->value instanceof Node\Scalar\String_) {
                continue;
            }

            if ($row = $this->row($args[0]->value->value, $call->getStartLine(), $form)) {
                $rows[] = $row;
            }
        }

        usort($rows, fn (array $a, array $b): int => [$a['line'], $a['form']] <=> [$b['line'], $b['form']]);

        return $rows;
    }

    /**
     * The `Schema::`/`DB::` form this call is, or `null` when it is neither. Matched on the short class
     * name so an aliased or unimported facade still reads — these two are Laravel facades whose short
     * names are effectively reserved, unlike the family class names position keeps this audit away from.
     */
    protected function tableCallForm(StaticCall $call): ?string
    {
        if (! $call->class instanceof Node\Name || ! $call->name instanceof Node\Identifier) {
            return null;
        }

        $class = $call->class->getLast();
        $method = $call->name->toString();

        if ($class === 'Schema' && in_array($method, self::SCHEMA_TABLE_METHODS, true)) {
            return "Schema::{$method}()";
        }

        if ($class === 'DB' && in_array($method, self::DB_TABLE_METHODS, true)) {
            return "DB::{$method}()";
        }

        return null;
    }

    /**
     * @return array{line: int, form: string, table: string, stem: string}|null
     */
    protected function row(string $value, int $line, string $form): ?array
    {
        if (! str_starts_with($value, self::DEFAULT_PREFIX)) {
            return null;
        }

        return [
            'line' => $line,
            'form' => $form,
            'table' => $value,
            'stem' => substr($value, strlen(self::DEFAULT_PREFIX)),
        ];
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
