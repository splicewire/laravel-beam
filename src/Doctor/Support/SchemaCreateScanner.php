<?php

namespace Splicewire\Beam\Doctor\Support;

use Splicewire\Beam\Doctor\UnguardedCreateAudit;

/**
 * Token-level detection of a **raw `Schema::create()`** in a migration template — the predicate behind
 * {@see UnguardedCreateAudit} (beam-facade ticket 30).
 *
 * ## The predicate is presence-of-create, not absence-of-guard
 * Ticket 22 specified this check as "flag a published stub that ships a bare `Schema::create` with no
 * convergent guard", and construction collapses the two halves into one: a stub on the guard does not
 * *wrap* `Schema::create`, it **replaces** it — `ConvergentTable::named(…)->define(…)->assert()` calls
 * the schema builder itself, from inside the utility. So an executable `Schema::create` in a migration
 * template is exactly the set of unguarded creates, with nothing to say about the guard at all.
 *
 * Three properties fall out of that rather than being designed in, and each one answers a hazard ticket
 * 10 measured or ticket 28 found:
 * - **Never keys on a class name**, so it cannot meet the constructor-DI false-positive class that ran
 *   at 100% in 10's census.
 * - **Never keys on the identity of a table**, so the dynamic-name blindness that would sink any
 *   table-identity check never arises — `Schema::create(Beam::table('submissions'), …)` is a finding
 *   without anyone resolving the argument, which is the same refusal
 *   `MigrationOrderingAudit::tablesIn()` makes deliberately.
 * - **Per call, not per file.** 28 found the defect a presence check cannot see: a guard whose *scope*
 *   is wrong (`create_permission_tables` guarded on `permissions` and returned for all five of its
 *   creates; taxonomy did it twice more). A file half-converted reports its unconverted creates here
 *   and nothing else, because each call is its own row.
 *
 * ## Comment-aware, by substrate
 * `token_get_all()` draws the line between executable position and prose exactly where PHP does, so a
 * docblock explaining what the create used to look like is invisible without an exception for it — the
 * reason 19 built these checks on tokens rather than regex. The `Schema` short name is resolved through
 * the file's own **import map** ({@see FacadeReferenceScanner::importedShortNames()}) for the same
 * reason `Beam::table()` needed one: it tokenizes as a bare name carrying no namespace.
 *
 * Requiring the import is a deliberate narrowing, not an oversight. A migration template lives in the
 * global namespace, so a bare `Schema` with no `use` line resolves to a global `Schema` that does not
 * exist and the file fatals on its own — there is no silent shape hiding behind the requirement.
 */
class SchemaCreateScanner
{
    /** The facade a migration template reaches the schema builder through. */
    public const SCHEMA_FACADE = 'Illuminate\Support\Facades\Schema';

    /**
     * Every executable `Schema::create()` in `$source`, as sorted rows.
     *
     * Two shapes are recognised, which is the whole grammar a migration uses to reach `create`:
     * `Schema::create(…)` and `Schema::connection(…)->create(…)`. Anything else named `create` —
     * `Attribute::create()` seeding a row, `$model->create()` — is not a schema create and is not a
     * finding. The estate carries two live instances of exactly that (a seed stub in
     * `splicewire/laravel-beam-market` calling `Attribute::create()` twice), which is why the receiver
     * is checked rather than the method name alone.
     *
     * Pure over source: no disk, no container, no DB. An unparseable template yields **no rows** rather
     * than a syntax complaint — 19's posture, inherited verbatim.
     *
     * @return list<array{line: int, shape: string}> sorted by line
     */
    public static function createCalls(string $source): array
    {
        $tokens = static::tokenize($source);

        if ($tokens === null) {
            return [];
        }

        $aliases = array_flip(FacadeReferenceScanner::importedShortNames($source, static::SCHEMA_FACADE));
        $rows = [];
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            if (! static::isSchemaName($tokens[$index], $aliases)) {
                continue;
            }

            if (static::significantAt($tokens, $index + 1)?->text !== '::') {
                continue;
            }

            $method = static::significantAfter($tokens, $index, '::');

            if ($method === null) {
                continue;
            }

            if ($method->text === 'create') {
                $rows[] = ['line' => $method->line, 'shape' => 'Schema::create'];

                continue;
            }

            // `Schema::connection($connection)->create(…)` — the same create, reached one hop further
            // out. Skipping the balanced argument list is what keeps a `create` *inside* those
            // arguments from being read as the chained one.
            if ($method->text !== 'connection') {
                continue;
            }

            $after = static::afterBalancedParens($tokens, $method->index);

            if (static::significantAt($tokens, $after)?->text !== '->') {
                continue;
            }

            $chained = static::significantAfter($tokens, $after - 1, '->');

            if ($chained?->text === 'create') {
                $rows[] = ['line' => $chained->line, 'shape' => 'Schema::connection()->create'];
            }
        }

        usort($rows, fn (array $a, array $b): int => $a['line'] <=> $b['line']);

        return $rows;
    }

    /**
     * Whether a token names the schema facade — either a short name the file imported, or the qualified
     * form written inline.
     *
     * @param  array{0: int, 1: string, 2: int}|string  $token
     * @param  array<string, int>  $aliases
     */
    private static function isSchemaName(array|string $token, array $aliases): bool
    {
        if (! is_array($token)) {
            return false;
        }

        if ($token[0] === T_STRING) {
            return isset($aliases[$token[1]]);
        }

        return in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
            && ltrim($token[1], '\\') === static::SCHEMA_FACADE;
    }

    /**
     * The first significant token at or after `$index`, or `null` past the end.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     */
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
     * The significant token following the `$operator` that follows `$index` — i.e. the method name in
     * `Foo::bar` / `$foo->bar`. `null` when the operator is not where it was expected.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function significantAfter(array $tokens, int $index, string $operator): ?SignificantToken
    {
        $op = static::significantAt($tokens, $index + 1);

        if ($op === null || $op->text !== $operator) {
            return null;
        }

        return static::significantAt($tokens, $op->index + 1);
    }

    /**
     * The index just past the balanced `(…)` opening after `$index`. Returns `$index` unchanged when no
     * parenthesis opens there, which the caller reads as "not the shape we were looking for".
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function afterBalancedParens(array $tokens, int $index): int
    {
        $open = static::significantAt($tokens, $index + 1);

        if ($open === null || $open->text !== '(') {
            return $index;
        }

        $depth = 0;
        $count = count($tokens);

        for ($i = $open->index; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token === '(') {
                $depth++;
            } elseif ($token === ')') {
                $depth--;

                if ($depth === 0) {
                    return $i + 1;
                }
            }
        }

        return $count;
    }

    /**
     * Tokenize, or `null` when the source will not tokenize at all — 19's stub tolerance, which is a
     * posture rather than a feature: an advisory drift check has no business reporting on a file's
     * syntax, and a template dialect this regime has not met should go quiet rather than noisy.
     *
     * @return list<array{0: int, 1: string, 2: int}|string>|null
     */
    private static function tokenize(string $source): ?array
    {
        try {
            return @token_get_all($source, TOKEN_PARSE);
        } catch (\Throwable) {
            return null;
        }
    }
}
