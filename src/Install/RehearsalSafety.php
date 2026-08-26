<?php

namespace Splicewire\Beam\Install;

use Splicewire\Beam\Doctor\UnrehearsableStubAudit;

/**
 * Whether a migration body is a pure convergent declaration, and therefore safe to REHEARSE.
 *
 * Extracted from {@see ConvergencePreflight} (beam-facade ticket 109) because a second instrument now
 * asks the same question of the same population: the preflight asks it at install time about the files
 * one `migrate` is about to run, and {@see UnrehearsableStubAudit} asks it as a
 * standing report so the count is watched rather than rediscovered per host by an operator reading a
 * `?` line. A second copy of this heuristic would drift, and the estate has already paid for that
 * mistake once — so there is one predicate and both callers hold it.
 *
 * ## Why the answer matters
 *
 * `ConvergentTable::rehearse()` puts every convergent terminal into report mode and then invokes the
 * body FOR REAL — there is nothing else to run, because the guard is constructed inside the migration
 * and a caller has nothing to hold. Rehearsal therefore neutralises convergent guards and NOTHING ELSE.
 * A `DB::statement()` or a post-create `Schema::table()` sitting beside a guard would execute during a
 * pass that promised to write nothing. So a body this class cannot prove is pure is skipped and SAID,
 * never quietly run and never quietly dropped.
 *
 * ## It is source text, and that is deliberate
 *
 * Approximate in the direction beam-facade ticket 77 named — it reads what a file says about itself.
 * It errs the safe way: the cost of a false positive is one migration reported unrehearsable, and the
 * cost of a false negative is a stray write during a pass that promised none. **Do not "fix" it by
 * loosening it.**
 *
 * The scan runs over everything EXCEPT `down()`, which is the stricter of the two available scopings
 * and the reason it is written this way. A stub's real work is routinely delegated to a private helper
 * (beam's own `create_activity_log_table` resolves its presence question through one), so an `up()`-only
 * scan would read a two-line body and miss everything it calls. `down()` is excised because
 * `Schema::dropIfExists()` is exactly what belongs there and never runs from a rehearsal. Ticket 144
 * measured the same scoping decision one instrument over and found it load-bearing rather than hygiene:
 * a whole-file read cancels a repair out against its own `down()`.
 */
class RehearsalSafety
{
    /**
     * The write shapes a rehearsal cannot neutralise, keyed by the phrase an operator reads.
     *
     * @var array<string, string>
     */
    protected const WRITES = [
        'a Schema write outside a convergent guard' => '/Schema::\s*(?:connection\([^)]*\)\s*->\s*)?(?:create|table|drop|dropIfExists|dropColumns|dropAllTables|rename)\s*\(/i',
        'a raw database statement' => '/DB::\s*(?:connection\([^)]*\)\s*->\s*)?(?:statement|unprepared|insert|update|delete)\s*\(/i',
        'a row write' => '/->\s*(?:insertOrIgnore|insertGetId|insert|updateOrInsert|upsert|update|delete|truncate)\s*\(/i',
    ];

    /** Whether the source is a convergent declaration at all — the population both callers scope to. */
    public static function isConvergent(string $source): bool
    {
        return str_contains($source, 'ConvergentTable');
    }

    /** The bare phrase naming what makes this body unsafe, or null when it is pure. */
    public static function reasonFor(string $source): ?string
    {
        $body = static::withoutDownMethod($source);

        foreach (static::WRITES as $reason => $pattern) {
            if (preg_match($pattern, $body)) {
                return $reason;
            }
        }

        return null;
    }

    /** The same answer as a sentence, for a report an operator reads. */
    public static function explain(string $source): ?string
    {
        $reason = static::reasonFor($source);

        return $reason === null
            ? null
            : "not rehearsed — it carries {$reason}, which a rehearsal would run for real";
    }

    /**
     * The source with the `down()` method's body removed, by brace matching from its opening `{`.
     *
     * Brace-matched rather than regexed because `down()` bodies contain braces (closures, arrays) and a
     * non-greedy match to the first `}` would leave the rest of the method in the scanned text — which
     * fails OPEN, letting a real write through.
     */
    protected static function withoutDownMethod(string $source): string
    {
        if (! preg_match('/function\s+down\s*\([^)]*\)\s*(?::\s*\w+\s*)?\{/i', $source, $m, PREG_OFFSET_CAPTURE)) {
            return $source;
        }

        $open = $m[0][1] + strlen($m[0][0]) - 1;
        $depth = 0;

        for ($i = $open, $len = strlen($source); $i < $len; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($source, 0, $m[0][1]).substr($source, $i + 1);
                }
            }
        }

        // Unbalanced — fail closed by scanning the whole file, which at worst over-reports.
        return $source;
    }
}
