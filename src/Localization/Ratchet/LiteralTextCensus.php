<?php

namespace Splicewire\Beam\Localization\Ratchet;

use PhpToken;
use Splicewire\Beam\Localization\Catalog\SourceScanner;

/**
 * The PHP ratchet's instrument (localization 12; its follow-up, coord-lead ruling 22:26Z): every user-facing string a
 * package's `src/` passes to a call that reaches a person, as HITS (file, line, enclosing method, pattern), from a closed
 * list of sinks:
 *
 * - `abort()`, `abort_if()`, `abort_unless()`: the MESSAGE argument only (a literal in the condition is not text);
 * - `new …HttpException(…)`;
 * - `ValidationException::withMessages([…])`, `->withErrors(…)`: values, never keys;
 * - `response()->json([…])` and `new JsonResponse([…])`: the `message` and `error` values;
 * - mail: `->subject()`, `->greeting()`, `->line()`, `->salutation()`, and `->action()`'s text, in a file that names
 *   `MailMessage`;
 * - notifications: the string values a `toArray()`, `toDatabase()` or `toBroadcast()` returns, in a file whose class
 *   extends `Notification`;
 * - flash: `->flash($key, '…')`, `Session::flash($key, '…')`: the message;
 * - a runtime Data object: `new …Data(…)` with a copy-named argument (`title:`, `message:`, `note:`, `label:`,
 *   `description:`, `summary:`), and `…Data::from([…])` with a copy-named key.
 *
 * A hit is a constant string, an INTERPOLATED string (`"… {$x} …"`), or a FORWARDED exception message (`->getMessage()`)
 * in a sink's text position. A literal inside `__()`, `trans()`, `trans_choice()` or `Lang::…()` is a KEY, never counted.
 *
 * **Exclusions** (`lang-ratchet-exclusions.json` at the package root, hand-maintained and reviewed): a file plus a
 * method or a line, each with a REASON. An excluded hit is not counted but is REPORTED with its reason, never silently
 * dropped, and an entry that matches no hit is stale and fails the compare ({@see TextRatchetExclusions}).
 *
 * **Recorded limitations** (not caught without false positives; each package's sweep classifies them by hand): Blade
 * views (a different grammar); text built by a package's own helper (`$this->fail("…")`); a generic `->with()` (also
 * eager loading); a raw `Response` body (a machine boundary, e.g. a webhook); a DTO wrapper's fields; a runtime `match`
 * or summary arm; a message forwarded through a variable (`abort(422, $reason)`).
 */
final class LiteralTextCensus
{
    public const POPULATION = 'PHP source under src/: constant, interpolated and forwarded (->getMessage()) text passed '
        .'to abort* (message only), HttpException, withMessages/withErrors, response()->json and new JsonResponse '
        .'message/error, MailMessage subject/greeting/line/salutation/action, Notification toArray/toDatabase/toBroadcast '
        .'values, flash messages, and copy-named arguments of runtime Data objects; exclusions reported with their reasons. '
        .'Not read: Blade views, helper-built text, ->with(), raw Response bodies, DTO wrappers, match/summary arms, '
        .'variable-forwarded messages (each sweep classifies these by hand).';

    private const ABORTS = ['abort', 'abort_if', 'abort_unless'];

    private const MAIL = ['subject', 'greeting', 'line', 'salutation', 'action'];

    private const NOTIFICATION_METHODS = ['toArray', 'toDatabase', 'toBroadcast'];

    private const KEY_CALLS = ['__', 'trans', 'trans_choice'];

    /** The argument names (and keys) of a runtime Data object that carry copy a person reads. */
    private const DATA_COPY = ['title', 'message', 'note', 'label', 'description', 'summary'];

    /**
     * Per file (relative to `$root`), the count of hits that are NOT excluded; files with none are left out.
     *
     * @return array<string, int> sorted by path
     */
    public static function count(string $root): array
    {
        $exclusions = TextRatchetExclusions::load($root);
        $counts = [];
        foreach (self::hits($root) as $hit) {
            if ($exclusions->match($hit) === null) {
                $counts[$hit['file']] = ($counts[$hit['file']] ?? 0) + 1;
            }
        }
        ksort($counts);

        return $counts;
    }

    /**
     * Every hit under `$root/src`, excluded ones included.
     *
     * @return list<array{file: string, line: int, method: ?string, kind: string}>
     */
    public static function hits(string $root): array
    {
        $root = rtrim($root, '/');
        $hits = [];
        foreach (SourceScanner::files([$root.'/src']) as $path) {
            $file = substr($path, strlen($root) + 1);
            foreach (self::hitsInSource((string) file_get_contents($path)) as $hit) {
                $hits[] = ['file' => $file] + $hit;
            }
        }

        return $hits;
    }

    public static function countFile(string $source): int
    {
        return count(self::hitsInSource($source));
    }

    /**
     * @return list<array{line: int, method: ?string, kind: string}>
     */
    public static function hitsInSource(string $source): array
    {
        $tokens = array_values(array_filter(
            PhpToken::tokenize($source),
            static fn (PhpToken $t): bool => ! $t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
        ));
        $methods = self::methodRanges($tokens);
        $isMail = str_contains($source, 'MailMessage');
        $isNotification = preg_match('/\bextends\s+\\\\?(?:Illuminate\\\\Notifications\\\\)?Notification\b/', $source) === 1;
        $at = [];
        $n = count($tokens);

        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            $next = $tokens[$i + 1] ?? null;
            $prev = $tokens[$i - 1] ?? null;

            // A notification payload method: the string VALUES in its body.
            if ($isNotification && $t->is(T_FUNCTION) && isset($tokens[$i + 1]) && in_array($tokens[$i + 1]->text, self::NOTIFICATION_METHODS, true)) {
                $open = self::find($tokens, $i, '{');
                if ($open !== null) {
                    $close = self::match($tokens, $open, '{', '}');
                    array_push($at, ...self::values($tokens, $open + 1, $close, 'notification'));
                    $i = $close;
                }

                continue;
            }

            if ($next === null || $next->text !== '(' || ! $t->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                continue;
            }
            $name = ltrim($t->text, '\\');
            $member = $prev !== null && $prev->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR]);
            $static = $prev !== null && $prev->is(T_DOUBLE_COLON);
            $new = $prev !== null && $prev->is(T_NEW);
            $close = self::match($tokens, $i + 1, '(', ')');

            $found = match (true) {
                ! $member && ! $static && in_array($name, self::ABORTS, true) => self::topLevel($tokens, $i + 2, $close, 'abort', skipArguments: $name === 'abort' ? 1 : 2),
                $new && str_ends_with($name, 'HttpException') => self::topLevel($tokens, $i + 2, $close, 'http-exception'),
                ($static || $member) && in_array($name, ['withMessages', 'withErrors'], true) => self::values($tokens, $i + 2, $close, 'validation'),
                ($member && $name === 'json' && self::isResponse($tokens, $i)) || ($new && str_ends_with($name, 'JsonResponse')) => self::keyedValues($tokens, $i + 2, $close, ['message', 'error'], 'json'),
                $member && $isMail && in_array($name, self::MAIL, true) => self::topLevel($tokens, $i + 2, $close, 'mail', firstOnly: $name === 'action'),
                ($member || $static) && $name === 'flash' => self::topLevel($tokens, $i + 2, $close, 'flash', skipArguments: 1),
                $new && str_ends_with($name, 'Data') => self::namedArguments($tokens, $i + 2, $close, self::DATA_COPY, 'data'),
                $static && $name === 'from' && str_ends_with(ltrim((string) ($tokens[$i - 2] ?? null)?->text, '\\'), 'Data') => self::keyedValues($tokens, $i + 2, $close, self::DATA_COPY, 'data'),
                default => null,
            };
            if ($found === null) {
                continue;
            }
            array_push($at, ...$found);
            $i = $close;
        }

        return array_map(static fn (array $h): array => [
            'line' => $tokens[$h[0]]->line,
            'method' => self::methodAt($methods, $h[0]),
            'kind' => $h[1],
        ], $at);
    }

    /**
     * Hits at the call's own depth (its arguments), outside any translation call.
     *
     * @param  array<int, PhpToken>  $tokens
     * @return list<array{0: int, 1: string}> token index, kind
     */
    private static function topLevel(array $tokens, int $from, int $to, string $kind, bool $firstOnly = false, int $skipArguments = 0): array
    {
        $hits = [];
        $depth = 0;
        $argument = 0;
        for ($i = $from; $i < $to; $i++) {
            $t = $tokens[$i];
            if (self::isKeyCall($tokens, $i)) {
                $i = self::match($tokens, $i + 1, '(', ')');

                continue;
            }
            if (in_array($t->text, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($t->text, [')', ']', '}'], true)) {
                $depth--;
            } elseif ($depth === 0 && $t->text === ',') {
                if ($firstOnly) {
                    break;
                }
                $argument++;
            } elseif ($depth === 0 && $argument >= $skipArguments && self::isForwarded($tokens, $i)) {
                $hits[] = [$i, $kind.':forwarded'];
            } elseif ($depth === 0 && ($end = self::stringEnd($tokens, $i)) !== null) {
                if ($argument >= $skipArguments) {
                    $hits[] = [$i, $kind];
                }
                $i = self::concatEnd($tokens, $end, $to);
            }
        }

        return $hits;
    }

    /**
     * Every string that is a VALUE (not followed by `=>`), at any depth, outside any translation call; and a forwarded
     * exception message.
     *
     * @param  array<int, PhpToken>  $tokens
     * @return list<array{0: int, 1: string}>
     */
    private static function values(array $tokens, int $from, int $to, string $kind): array
    {
        $hits = [];
        for ($i = $from; $i < $to; $i++) {
            if (self::isKeyCall($tokens, $i)) {
                $i = self::match($tokens, $i + 1, '(', ')');

                continue;
            }
            if (self::isForwarded($tokens, $i)) {
                $hits[] = [$i, $kind.':forwarded'];

                continue;
            }
            if (($end = self::stringEnd($tokens, $i)) !== null) {
                $end = self::concatEnd($tokens, $end, $to);
                if (($tokens[$end + 1] ?? null)?->is(T_DOUBLE_ARROW) !== true) {
                    $hits[] = [$i, $kind];
                }
                $i = $end;
            }
        }

        return $hits;
    }

    /**
     * The values of the named keys (`'message' => '…'`), a string or a forwarded message.
     *
     * @param  array<int, PhpToken>  $tokens
     * @param  list<string>  $keys
     * @return list<array{0: int, 1: string}>
     */
    private static function keyedValues(array $tokens, int $from, int $to, array $keys, string $kind): array
    {
        $hits = [];
        for ($i = $from; $i + 2 < $to; $i++) {
            if (self::isString($tokens[$i]) && in_array(SourceScanner::unquote($tokens[$i]->text), $keys, true) && $tokens[$i + 1]->is(T_DOUBLE_ARROW)) {
                if (self::stringEnd($tokens, $i + 2) !== null) {
                    $hits[] = [$i + 2, $kind];
                } elseif (self::isForwarded($tokens, $i + 2)) {
                    $hits[] = [$i + 2, $kind.':forwarded'];
                }
            }
        }

        return $hits;
    }

    /**
     * The values of copy-named NAMED arguments (`title: '…'`) at the call's own depth.
     *
     * @param  array<int, PhpToken>  $tokens
     * @param  list<string>  $names
     * @return list<array{0: int, 1: string}>
     */
    private static function namedArguments(array $tokens, int $from, int $to, array $names, string $kind): array
    {
        $hits = [];
        $depth = 0;
        for ($i = $from; $i + 2 < $to; $i++) {
            $t = $tokens[$i];
            if (in_array($t->text, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($t->text, [')', ']', '}'], true)) {
                $depth--;
            } elseif ($depth === 0 && $t->is(T_STRING) && in_array($t->text, $names, true) && $tokens[$i + 1]->text === ':') {
                if (self::stringEnd($tokens, $i + 2) !== null) {
                    $hits[] = [$i + 2, $kind];
                } elseif (self::isForwarded($tokens, $i + 2)) {
                    $hits[] = [$i + 2, $kind.':forwarded'];
                }
            }
        }

        return $hits;
    }

    /**
     * A forwarded exception message at `$i`: `$e->getMessage()` (the variable, `->`, `getMessage`, `(`). Text that
     * reaches a person from an exception is user-facing whatever threw it; a coded error forwards `->message()` instead.
     *
     * @param  array<int, PhpToken>  $tokens
     */
    private static function isForwarded(array $tokens, int $i): bool
    {
        if (! $tokens[$i]->is(T_VARIABLE)
            || ($tokens[$i + 1] ?? null)?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR]) !== true
            || ($tokens[$i + 2] ?? null)?->text !== 'getMessage'
            || ($tokens[$i + 3] ?? null)?->text !== '(') {
            return false;
        }

        // A message already resolved through the translator is not raw text: a variable caught as a
        // ValidationException (the framework's, or a CodedValidationException) or another Coded* exception. A union catch
        // is skipped only when EVERY arm is one of those (REVIEW-B7 S1): one raw arm can forward display text, whatever
        // order the arms are spelled in.
        $caught = self::caughtAs($tokens, $i);
        if ($caught === null) {
            return true;
        }
        foreach (explode('|', $caught) as $type) {
            $short = substr((string) strrchr('\\'.$type, '\\'), 1);
            if (! str_ends_with($short, 'ValidationException') && ! str_starts_with($short, 'Coded')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The class a variable was caught as (`catch (ValidationException $e)`), searching back from its use, or null.
     *
     * @param  array<int, PhpToken>  $tokens
     */
    private static function caughtAs(array $tokens, int $i): ?string
    {
        $variable = $tokens[$i]->text;
        for ($j = $i - 1; $j > 0; $j--) {
            if ($tokens[$j]->is(T_VARIABLE) && $tokens[$j]->text === $variable && ($tokens[$j + 1] ?? null)?->text === ')') {
                $types = [];
                for ($k = $j - 1; $k > 0 && $tokens[$k]->text !== '('; $k--) {
                    if ($tokens[$k]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                        $types[] = ltrim($tokens[$k]->text, '\\');
                    }
                }
                if ($k > 0 && ($tokens[$k - 1] ?? null)?->is(T_CATCH) === true) {
                    return $types === [] ? null : implode('|', array_reverse($types));
                }

                return null;
            }
        }

        return null;
    }

    /**
     * Where a string's `.`-concatenated continuation ends, so `'Unsupported format: '.implode(', ', $x).'.'` is ONE hit:
     * operands are skipped at their own depth up to the next `,`, `]`, `)`, `;` or `=>`.
     *
     * @param  array<int, PhpToken>  $tokens
     */
    private static function concatEnd(array $tokens, int $end, int $to): int
    {
        while (($tokens[$end + 1] ?? null)?->text === '.' && $end + 2 < $to) {
            $depth = 0;
            for ($j = $end + 2; $j < $to; $j++) {
                $text = $tokens[$j]->text;
                if (in_array($text, ['(', '['], true) || $tokens[$j]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                    $depth++;
                } elseif (in_array($text, [')', ']', '}'], true)) {
                    if ($depth === 0) {
                        break;
                    }
                    $depth--;
                } elseif ($depth === 0 && (in_array($text, [',', ';', '.'], true) || $tokens[$j]->is(T_DOUBLE_ARROW))) {
                    break;
                } elseif ($depth === 0 && $text === '"' && ($close = self::stringEnd($tokens, $j)) !== null) {
                    $j = $close;
                }
            }
            $end = $j - 1;
        }

        return $end;
    }

    /** @param  array<int, PhpToken>  $tokens */
    private static function isResponse(array $tokens, int $json): bool
    {
        // `response()->json(` : the tokens before `->json` are `response ( )`.
        return ($tokens[$json - 2] ?? null)?->text === ')' && ($tokens[$json - 3] ?? null)?->text === '('
            && ($tokens[$json - 4] ?? null)?->text === 'response';
    }

    /** @param  array<int, PhpToken>  $tokens */
    private static function isKeyCall(array $tokens, int $i): bool
    {
        $t = $tokens[$i];
        if (($tokens[$i + 1] ?? null)?->text !== '(') {
            return false;
        }
        if ($t->is(T_STRING) && in_array($t->text, self::KEY_CALLS, true)) {
            return true;
        }

        return $t->is(T_STRING) && ($tokens[$i - 1] ?? null)?->is(T_DOUBLE_COLON) === true
            && in_array(ltrim((string) ($tokens[$i - 2] ?? null)?->text, '\\'), ['Lang', 'Illuminate\\Support\\Facades\\Lang'], true);
    }

    private static function isString(PhpToken $t): bool
    {
        return $t->is(T_CONSTANT_ENCAPSED_STRING) && strlen($t->text) > 2;
    }

    /**
     * Where a string at `$i` ends: the same index for a constant string, the closing quote's index for an INTERPOLATED
     * one (`"The `{$key}` resource …"`, which tokenizes as `"` … `"`), else null for a token that is not a string.
     *
     * @param  array<int, PhpToken>  $tokens
     */
    private static function stringEnd(array $tokens, int $i): ?int
    {
        if (self::isString($tokens[$i])) {
            return $i;
        }
        if ($tokens[$i]->text !== '"') {
            return null;
        }
        for ($j = $i + 1; isset($tokens[$j]); $j++) {
            if ($tokens[$j]->text === '"') {
                return $j;
            }
        }

        return null;
    }

    /**
     * Each named function's body as a token range, innermost found by {@see methodAt()}.
     *
     * @param  array<int, PhpToken>  $tokens
     * @return list<array{0: int, 1: int, 2: string}> open brace, close brace, name
     */
    private static function methodRanges(array $tokens): array
    {
        $ranges = [];
        foreach ($tokens as $i => $t) {
            if (! $t->is(T_FUNCTION) || ! ($tokens[$i + 1] ?? null)?->is(T_STRING)) {
                continue;
            }
            $open = self::find($tokens, $i, '{');
            if ($open !== null) {
                $ranges[] = [$open, self::match($tokens, $open, '{', '}'), $tokens[$i + 1]->text];
            }
        }

        return $ranges;
    }

    /** @param  list<array{0: int, 1: int, 2: string}>  $ranges */
    private static function methodAt(array $ranges, int $index): ?string
    {
        $found = null;
        foreach ($ranges as [$open, $close, $name]) {
            if ($index > $open && $index < $close && ($found === null || $open > $found[0])) {
                $found = [$open, $name];
            }
        }

        return $found[1] ?? null;
    }

    /** @param  array<int, PhpToken>  $tokens */
    private static function find(array $tokens, int $from, string $text): ?int
    {
        for ($i = $from; isset($tokens[$i]); $i++) {
            if ($tokens[$i]->text === $text) {
                return $i;
            }
            if ($tokens[$i]->text === ';') {
                return null;
            }
        }

        return null;
    }

    /** @param  array<int, PhpToken>  $tokens the index of the bracket that closes the one at `$open` */
    private static function match(array $tokens, int $open, string $left, string $right): int
    {
        $depth = 0;
        for ($i = $open; isset($tokens[$i]); $i++) {
            if ($tokens[$i]->text === $left || ($left === '{' && $tokens[$i]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES]))) {
                $depth++;
            } elseif ($tokens[$i]->text === $right) {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return count($tokens) - 1;
    }
}
