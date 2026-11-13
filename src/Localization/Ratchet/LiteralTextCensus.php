<?php

namespace Splicewire\Beam\Localization\Ratchet;

use PhpToken;
use Splicewire\Beam\Localization\Catalog\SourceScanner;

/**
 * The PHP ratchet's instrument (localization 12): how many user-facing string LITERALS a package's `src/` passes to
 * calls that reach a person. A closed list (coord-lead ruling (c) on 12):
 *
 * - `abort()`, `abort_if()`, `abort_unless()`;
 * - `new …HttpException(…)`;
 * - `ValidationException::withMessages([…])`, `->withErrors(…)` (values, never keys);
 * - `response()->json([...'message' => '…'])` (the `message` and `error` values);
 * - mail: `->subject()`, `->greeting()`, `->line()`, `->salutation()`, and `->action()`'s text, in a file that names
 *   `MailMessage`;
 * - notifications: the string values a `toArray()`, `toDatabase()` or `toBroadcast()` returns, in a file whose class
 *   extends `Notification`.
 *
 * A literal inside `__()`, `trans()`, `trans_choice()` or `Lang::…()` is a KEY, never counted. Blade templates are a
 * different grammar and are not read (a recorded limitation; each package's sweep, tickets 21–27, owns its views).
 * A count is an instrument, not a verdict: a notification's machine value (`'type' => 'invoice.paid'`) counts too, and
 * the baseline absorbs it; the ratchet's only promise is that the number never rises.
 */
final class LiteralTextCensus
{
    public const POPULATION = 'PHP source under src/: literals passed to abort*, HttpException, withMessages/withErrors, '
        .'response()->json message/error, MailMessage subject/greeting/line/salutation/action, and Notification '
        .'toArray/toDatabase/toBroadcast values. Blade views are not read (a different grammar; owned by sweeps 21-27).';

    private const ABORTS = ['abort', 'abort_if', 'abort_unless'];

    private const MAIL = ['subject', 'greeting', 'line', 'salutation', 'action'];

    private const NOTIFICATION_METHODS = ['toArray', 'toDatabase', 'toBroadcast'];

    private const KEY_CALLS = ['__', 'trans', 'trans_choice'];

    /**
     * Per file (relative to `$root`), the count of counted literals; files with none are left out.
     *
     * @return array<string, int> sorted by path
     */
    public static function count(string $root): array
    {
        $counts = [];
        $root = rtrim($root, '/');
        foreach (SourceScanner::files([$root.'/src']) as $file) {
            $n = self::countFile((string) file_get_contents($file));
            if ($n > 0) {
                $counts[substr($file, strlen($root) + 1)] = $n;
            }
        }
        ksort($counts);

        return $counts;
    }

    public static function countFile(string $source): int
    {
        $tokens = array_values(array_filter(
            PhpToken::tokenize($source),
            static fn (PhpToken $t): bool => ! $t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
        ));
        $isMail = str_contains($source, 'MailMessage');
        $isNotification = preg_match('/\bextends\s+\\\\?(?:Illuminate\\\\Notifications\\\\)?Notification\b/', $source) === 1;
        $count = 0;
        $n = count($tokens);

        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            $next = $tokens[$i + 1] ?? null;
            $prev = $tokens[$i - 1] ?? null;

            // A notification payload method: count the string VALUES in its body.
            if ($isNotification && $t->is(T_FUNCTION) && isset($tokens[$i + 1]) && in_array($tokens[$i + 1]->text, self::NOTIFICATION_METHODS, true)) {
                $open = self::find($tokens, $i, '{');
                if ($open !== null) {
                    $close = self::match($tokens, $open, '{', '}');
                    $count += self::values($tokens, $open + 1, $close);
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
            $close = self::match($tokens, $i + 1, '(', ')');

            if (! $member && ! $static && in_array($name, self::ABORTS, true)) {
                $count += self::topLevel($tokens, $i + 2, $close);
            } elseif ($prev !== null && $prev->is(T_NEW) && str_ends_with($name, 'HttpException')) {
                $count += self::topLevel($tokens, $i + 2, $close);
            } elseif (($static || $member) && in_array($name, ['withMessages', 'withErrors'], true)) {
                $count += self::values($tokens, $i + 2, $close);
            } elseif ($member && $name === 'json' && self::isResponse($tokens, $i)) {
                $count += self::keyedValues($tokens, $i + 2, $close, ['message', 'error']);
            } elseif ($member && $isMail && in_array($name, self::MAIL, true)) {
                $count += $name === 'action' ? self::topLevel($tokens, $i + 2, $close, firstOnly: true) : self::topLevel($tokens, $i + 2, $close);
            } else {
                continue;
            }
            $i = $close;
        }

        return $count;
    }

    /**
     * Literals at the call's own depth (its arguments), outside any translation call.
     *
     * @param  list<PhpToken>  $tokens
     */
    private static function topLevel(array $tokens, int $from, int $to, bool $firstOnly = false): int
    {
        $count = 0;
        $depth = 0;
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
            } elseif ($depth === 0 && $t->text === ',' && $firstOnly) {
                break;
            } elseif ($depth === 0 && self::isString($t)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Every string literal that is a VALUE (not followed by `=>`), at any depth, outside any translation call.
     *
     * @param  list<PhpToken>  $tokens
     */
    private static function values(array $tokens, int $from, int $to): int
    {
        $count = 0;
        for ($i = $from; $i < $to; $i++) {
            if (self::isKeyCall($tokens, $i)) {
                $i = self::match($tokens, $i + 1, '(', ')');

                continue;
            }
            if (self::isString($tokens[$i]) && ($tokens[$i + 1] ?? null)?->is(T_DOUBLE_ARROW) !== true) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * The literal values of the named keys (`'message' => '…'`).
     *
     * @param  list<PhpToken>  $tokens
     * @param  list<string>  $keys
     */
    private static function keyedValues(array $tokens, int $from, int $to, array $keys): int
    {
        $count = 0;
        for ($i = $from; $i + 2 < $to; $i++) {
            if (self::isString($tokens[$i]) && in_array(SourceScanner::unquote($tokens[$i]->text), $keys, true)
                && $tokens[$i + 1]->is(T_DOUBLE_ARROW) && self::isString($tokens[$i + 2])) {
                $count++;
            }
        }

        return $count;
    }

    /** @param  list<PhpToken>  $tokens */
    private static function isResponse(array $tokens, int $json): bool
    {
        // `response()->json(` : the tokens before `->json` are `response ( )`.
        return ($tokens[$json - 2] ?? null)?->text === ')' && ($tokens[$json - 3] ?? null)?->text === '('
            && ($tokens[$json - 4] ?? null)?->text === 'response';
    }

    /** @param  list<PhpToken>  $tokens */
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

    /** @param  list<PhpToken>  $tokens */
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

    /** @param  list<PhpToken>  $tokens the index of the bracket that closes the one at `$open` */
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
