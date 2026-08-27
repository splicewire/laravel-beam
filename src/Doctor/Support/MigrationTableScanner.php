<?php

namespace Splicewire\Beam\Doctor\Support;

use Illuminate\Support\Str;
use Rushing\SchemaConvergence\ConvergentTable;
use Splicewire\Beam\Doctor\MigrationOrderingAudit;
use Splicewire\Beam\Facades\Beam;

/**
 * Reads out of a migration which tables it CREATES, which it ALTERS, and which it POINTS AT with a
 * foreign key — the three channels {@see MigrationOrderingAudit} joins against filename order.
 *
 * ## Why this replaced a two-line regex
 * The audit used to read table identity with `/Schema::create\(\s*'([a-z0-9_]+)'/`. Measured
 * 2026-08-27 across every `~/Workspaces/php/packages/&#42;/&#42;/database/migrations` directory, that
 * pattern matches **0 sites**: the convergence sweep of 2026-08-18
 * ({@see ConvergentTable}) removed the last shape it could see, and the estate now declares **157**
 * creates as `ConvergentTable::named(…)`. The audit was returning an unconditional `pass` — a check
 * that reports success by not running.
 *
 * ## Three channels, because convergence collapsed the old two
 * A convergent create and a convergent top-up are the SAME call: whoever runs first creates, whoever
 * runs second adds the missing columns. So `ALTER-after-CREATE`, the only shape the old audit modelled,
 * is largely no longer a defect at all — a column top-up that lands first simply becomes the create.
 * What convergence cannot dissolve is a **foreign key**: `->constrained('silos')` needs `silos` to
 * exist as a real table at the moment the constraint is added, and no amount of topping up conjures it.
 * That is why `references()` exists and is the channel that carries the live defect.
 *
 * ## What it resolves, and the two things it deliberately will not
 * The argument grammar is closed and small — anything outside it yields a {@see MigrationTableRef}
 * with a `null` name rather than a guess:
 *
 * | written                                     | answer                                  |
 * |---------------------------------------------|-----------------------------------------|
 * | `ConvergentTable::named('silos')`           | `silos`, literal                        |
 * | `ConvergentTable::named(Beam::table('hooks'))` | `hooks`, **prefixed** — caller resolves |
 * | `$table->foreignUuid('silo_id')->constrained()` | `silos`, via the column (Laravel's own rule) |
 * | `ConvergentTable::named($this->target())`   | `null` — opaque                          |
 * | `ConvergentTable::named($tableNames['roles'])` | `null` — opaque                       |
 * | `ConvergentTable::named($lunarPrefix.'products')` | `null` — opaque                     |
 *
 * The **prefixed** row is the one that earns the rewrite: `Beam::table('x')` is not unresolvable, it is
 * unresolvable *from source*. The scanner stays pure and hands the literal up; the audit, which runs in
 * a booted host, calls {@see Beam::table()} and gets the answer the host will actually use. Splitting
 * the refusal that way is what recovers the estate's dynamic half without inventing a single name.
 *
 * The **opaque** rows keep `MigrationOrderingAudit::tablesIn()`'s original posture verbatim, and it was
 * always correct: an advisory audit under-reports rather than misleads. What changed is that the
 * silence is now *counted* — the audit reports how many sites it declined to read, so an empty result
 * cannot be mistaken for coverage.
 *
 * Pure over source: no disk, no container, no DB. An unparseable file yields no rows, inheriting
 * {@see SchemaCreateScanner}'s tolerance verbatim.
 */
class MigrationTableScanner
{
    public const SCHEMA_FACADE = 'Illuminate\Support\Facades\Schema';

    public const CONVERGENT_TABLE = 'Rushing\SchemaConvergence\ConvergentTable';

    public const BEAM_FACADE = 'Splicewire\Beam\Facades\Beam';

    /**
     * Column builders whose argument names a foreign key column, so a bare `->constrained()` can be
     * resolved the way Laravel itself resolves it. `foreignIdFor()` takes a model class rather than a
     * column and is deliberately absent — resolving it would mean instantiating the model.
     */
    private const FOREIGN_ID_METHODS = ['foreignId', 'foreignUuid', 'foreignUlid'];

    /**
     * Tables this migration creates.
     *
     * Both dialects, because the estate is mid-sweep and a host's published tree holds files from both
     * sides of 2026-08-18: `ConvergentTable::named(…)` is today's shape and `Schema::create(…)` is the
     * shape {@see UnguardedCreateAudit} exists to flag but which still creates a table while it lives.
     *
     * @return list<MigrationTableRef>
     */
    public static function creates(string $source): array
    {
        return static::sorted(array_merge(
            static::staticCalls($source, static::CONVERGENT_TABLE, ['named']),
            static::staticCalls($source, static::SCHEMA_FACADE, ['create']),
        ));
    }

    /**
     * Tables this migration alters without claiming to create.
     *
     * `ConvergentTable::named()` is NOT here even though half its uses are top-ups, and that is the
     * point: it converges, so it cannot be ordered wrongly against a create — it *is* the create when
     * it runs first. Only a bare `Schema::table()` genuinely requires the table to already exist.
     *
     * @return list<MigrationTableRef>
     */
    public static function alters(string $source): array
    {
        return static::sorted(static::staticCalls($source, static::SCHEMA_FACADE, ['table']));
    }

    /**
     * Tables this migration points a foreign key at — the channel that carries the live defect.
     *
     * Three shapes, which is the whole grammar a Blueprint uses to name an FK target:
     * `->constrained('silos')`, `->on('silos')` (after `->references()`), and a bare `->constrained()`
     * whose target Laravel derives from the column name. The derivation is copied from
     * `ForeignIdColumnDefinition`, not guessed: `Str::plural(Str::beforeLast($column, '_id'))`.
     *
     * A name that resolves to no creator produces no finding, so a false read here goes quiet rather
     * than loud — which is what makes including `->on()` safe despite the method name being generic.
     *
     * @return list<MigrationTableRef>
     */
    public static function references(string $source): array
    {
        $tokens = static::tokenize($source);

        if ($tokens === null) {
            return [];
        }

        $beam = array_flip(FacadeReferenceScanner::importedShortNames($source, static::BEAM_FACADE));
        $refs = [];
        $count = count($tokens);
        $column = null;

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];

            if (! is_array($token) || $token[0] !== T_STRING) {
                continue;
            }

            if (static::significantBefore($tokens, $index)?->text !== '->') {
                continue;
            }

            if (in_array($token[1], static::FOREIGN_ID_METHODS, true)) {
                // Remembered so a bare `->constrained()` further down the same chain can be answered.
                // Chain-local by construction: the next foreign-id call overwrites it, and a
                // `constrained()` with no preceding one leaves it null and stays opaque.
                $column = static::literalArgument($tokens, $index);

                continue;
            }

            if (! in_array($token[1], ['constrained', 'on'], true)) {
                continue;
            }

            $ref = static::resolveArgument($tokens, $index, '->'.$token[1], $token[2], $beam);

            if ($ref->isOpaque() && $token[1] === 'constrained' && static::hasNoArguments($tokens, $index)) {
                $ref = $column === null
                    ? $ref
                    : new MigrationTableRef(
                        line: $token[2],
                        shape: '->constrained()',
                        name: Str::plural(Str::beforeLast($column, '_id')),
                        via: 'column',
                    );
            }

            $refs[] = $ref;
        }

        return static::sorted($refs);
    }

    /**
     * Every `Facade::method(…)` and `Facade::connection(…)->method(…)` call site for `$fqcn`, resolved
     * through the file's own import map — the same two-shape walk {@see SchemaCreateScanner} makes, and
     * the same reason: a short name carries no namespace, so without the import map every call is
     * invisible.
     *
     * @param  list<string>  $methods
     * @return list<MigrationTableRef>
     */
    private static function staticCalls(string $source, string $fqcn, array $methods): array
    {
        $tokens = static::tokenize($source);

        if ($tokens === null) {
            return [];
        }

        $aliases = array_flip(FacadeReferenceScanner::importedShortNames($source, $fqcn));
        $beam = array_flip(FacadeReferenceScanner::importedShortNames($source, static::BEAM_FACADE));
        $short = substr($fqcn, strrpos($fqcn, '\\') + 1);
        $rows = [];
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            if (! static::namesClass($tokens[$index], $aliases, $fqcn)) {
                continue;
            }

            $method = static::significantAfter($tokens, $index, '::');

            if ($method === null) {
                continue;
            }

            if (in_array($method->text, $methods, true)) {
                $rows[] = static::resolveArgument($tokens, $method->index, $short.'::'.$method->text, $method->line, $beam);

                continue;
            }

            if ($method->text !== 'connection') {
                continue;
            }

            $after = static::afterBalancedParens($tokens, $method->index);

            if (static::significantAt($tokens, $after)?->text !== '->') {
                continue;
            }

            $chained = static::significantAfter($tokens, $after - 1, '->');

            if ($chained !== null && in_array($chained->text, $methods, true)) {
                $rows[] = static::resolveArgument(
                    $tokens,
                    $chained->index,
                    $short.'::connection()->'.$chained->text,
                    $chained->line,
                    $beam,
                );
            }
        }

        return $rows;
    }

    /**
     * The closed grammar, applied to the first argument of the call whose name token sits at `$index`.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     * @param  array<string, int>  $beam
     */
    private static function resolveArgument(array $tokens, int $index, string $shape, int $line, array $beam): MigrationTableRef
    {
        $argument = static::firstArgument($tokens, $index);

        // `'silos'` — one string token and nothing else.
        if (count($argument) === 1 && static::stringValue($argument[0]) !== null) {
            return new MigrationTableRef($line, $shape, static::stringValue($argument[0]));
        }

        // `Beam::table('hooks')` — the receiver must be the beam facade and the argument a bare literal,
        // so the whole expression is exactly `Beam :: table ( 'x' )` and nothing else. Six tokens rather
        // than five because the inner parentheses are significant tokens too, and requiring the exact
        // count is what keeps `Beam::table('x').$suffix` out.
        if (count($argument) === 6
            && static::namesClass($argument[0], $beam, static::BEAM_FACADE)
            && static::text($argument[1]) === '::'
            && static::text($argument[2]) === 'table'
            && static::text($argument[3]) === '('
            && static::stringValue($argument[4]) !== null
            && static::text($argument[5]) === ')') {
            return new MigrationTableRef($line, $shape, static::stringValue($argument[4]), prefixed: true, via: 'prefixed');
        }

        return new MigrationTableRef($line, $shape, null, via: 'opaque');
    }

    /**
     * The single-quoted literal in a call's first argument, or `null` for anything else — the narrow
     * read `->foreignUuid('silo_id')` needs.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function literalArgument(array $tokens, int $index): ?string
    {
        $argument = static::firstArgument($tokens, $index);

        return count($argument) === 1 ? static::stringValue($argument[0]) : null;
    }

    /**
     * Whether the call opening after `$index` closes immediately — `->constrained()`, the shape whose
     * target has to come from the column instead.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function hasNoArguments(array $tokens, int $index): bool
    {
        $open = static::significantAt($tokens, $index + 1);

        return $open?->text === '(' && static::significantAt($tokens, $open->index + 1)?->text === ')';
    }

    /**
     * The significant tokens of a call's first argument — everything between `(` and the first
     * depth-one `,` or the matching `)`. Empty when no parenthesis opens there.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     * @return list<array{0: int, 1: string, 2: int}|string>
     */
    private static function firstArgument(array $tokens, int $index): array
    {
        $open = static::significantAt($tokens, $index + 1);

        if ($open === null || $open->text !== '(') {
            return [];
        }

        $depth = 0;
        $argument = [];
        $count = count($tokens);

        for ($i = $open->index; $i < $count; $i++) {
            $token = $tokens[$i];
            $text = is_string($token) ? $token : $token[1];

            if ($text === '(' || $text === '[') {
                $depth++;

                if ($depth === 1) {
                    continue;
                }
            } elseif ($text === ')' || $text === ']') {
                $depth--;

                if ($depth === 0) {
                    return $argument;
                }
            } elseif ($text === ',' && $depth === 1) {
                return $argument;
            }

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $argument[] = $token;
        }

        return $argument;
    }

    /**
     * The value of a single-quoted string token, or `null` when the token is not one. Double-quoted
     * strings are excluded on purpose: they interpolate, so their literal text is not their value.
     *
     * @param  array{0: int, 1: string, 2: int}|string  $token
     */
    private static function stringValue(array|string $token): ?string
    {
        if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }

        if (! str_starts_with($token[1], "'")) {
            return null;
        }

        $value = stripcslashes(substr($token[1], 1, -1));

        return preg_match('/^[a-z0-9_]+$/i', $value) === 1 ? $value : null;
    }

    /**
     * @param  array{0: int, 1: string, 2: int}|string  $token
     * @param  array<string, int>  $aliases
     */
    private static function namesClass(array|string $token, array $aliases, string $fqcn): bool
    {
        if (! is_array($token)) {
            return false;
        }

        if ($token[0] === T_STRING) {
            return isset($aliases[$token[1]]);
        }

        return in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
            && ltrim($token[1], '\\') === $fqcn;
    }

    /** @param list<array{0: int, 1: string, 2: int}|string> $tokens */
    private static function significantAt(array $tokens, int $index): ?SignificantToken
    {
        $count = count($tokens);

        for ($i = max($index, 0); $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_string($token)) {
                return new SignificantToken($i, $token, 0);
            }

            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return new SignificantToken($i, $token[1], $token[2]);
        }

        return null;
    }

    /**
     * The nearest significant token BEFORE `$index` — the direction {@see significantAt()} cannot walk,
     * and the one a `->method` check needs: skipping forward from `$index - 1` over whitespace lands
     * back on `$index` itself and reads every method call as unqualified.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function significantBefore(array $tokens, int $index): ?SignificantToken
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];

            if (is_string($token)) {
                return new SignificantToken($i, $token, 0);
            }

            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return new SignificantToken($i, $token[1], $token[2]);
        }

        return null;
    }

    /**
     * A token's text, whichever of `token_get_all()`'s two shapes it arrived in.
     *
     * @param  array{0: int, 1: string, 2: int}|string  $token
     */
    private static function text(array|string $token): string
    {
        return is_string($token) ? $token : $token[1];
    }

    /** @param list<array{0: int, 1: string, 2: int}|string> $tokens */
    private static function significantAfter(array $tokens, int $index, string $operator): ?SignificantToken
    {
        $op = static::significantAt($tokens, $index + 1);

        if ($op === null || $op->text !== $operator) {
            return null;
        }

        return static::significantAt($tokens, $op->index + 1);
    }

    /** @param list<array{0: int, 1: string, 2: int}|string> $tokens */
    private static function afterBalancedParens(array $tokens, int $index): int
    {
        $open = static::significantAt($tokens, $index + 1);

        if ($open === null || $open->text !== '(') {
            return $index;
        }

        $depth = 0;
        $count = count($tokens);

        for ($i = $open->index; $i < $count; $i++) {
            if ($tokens[$i] === '(') {
                $depth++;
            } elseif ($tokens[$i] === ')') {
                $depth--;

                if ($depth === 0) {
                    return $i + 1;
                }
            }
        }

        return $count;
    }

    /**
     * @param  list<MigrationTableRef>  $refs
     * @return list<MigrationTableRef>
     */
    private static function sorted(array $refs): array
    {
        usort($refs, static fn (MigrationTableRef $a, MigrationTableRef $b): int => $a->line <=> $b->line);

        return array_values($refs);
    }

    /** @return list<array{0: int, 1: string, 2: int}|string>|null */
    private static function tokenize(string $source): ?array
    {
        try {
            return @token_get_all($source, TOKEN_PARSE);
        } catch (\Throwable) {
            return null;
        }
    }
}
