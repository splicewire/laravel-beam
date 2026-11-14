<?php

namespace Splicewire\Beam\Localization\Client;

use JsonException;
use stdClass;

/**
 * RFC 8785 (JSON Canonicalization Scheme) and the client-catalog bridge's JSON admission (localization 16; ruling 02
 * §3–§4). It is at parity with schemastud's `messages/bin/jcs.mjs`, proven against its committed fixture (jcs.json,
 * digest 630e49af…).
 *
 * {@see canonicalize()}:
 * - objects have their members sorted by the keys' UTF-16 code units (§3.2.3; not code points, not UTF-8 bytes);
 * - arrays keep their order;
 * - strings use JSON.stringify's escaping (only `"`, `\` and C0 controls; never `/`, non-ASCII or U+2028/U+2029);
 * - numbers use ES6 Number::toString over the shortest round-trip digits (§3.2.2.3);
 * - there is no whitespace.
 *
 * {@see parse()} admits only what the JS bridge admits, so both runtimes canonicalize the same value. It refuses duplicate
 * keys (an escaped spelling included), integers outside ±(2^53−1), non-finite numbers, lone surrogates, raw control
 * characters and anything that isn't strict JSON. `__proto__` is plain data. It's a tokenizer, not json_decode, which
 * lets the last duplicate win silently.
 */
final class Jcs
{
    private const MAX_SAFE_INTEGER = 9007199254740991;

    public static function canonicalize(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            $value === true => 'true',
            $value === false => 'false',
            is_int($value) => self::integer($value),
            is_float($value) => self::number($value),
            is_string($value) => self::string($value),
            $value instanceof JsonObject => self::object($value->members()),
            $value instanceof stdClass => self::object(get_object_vars($value)),
            is_array($value) && array_is_list($value) => '['.implode(',', array_map(self::canonicalize(...), $value)).']',
            is_array($value) => self::object($value),
            $value instanceof \JsonSerializable => self::canonicalize($value->jsonSerialize()),
            default => throw new InvalidClientCatalog('JCS cannot canonicalize a '.get_debug_type($value).'.'),
        };
    }

    /** The SHA-256 (hex) of a value's canonical UTF-8 bytes. */
    public static function hash(mixed $value): string
    {
        return hash('sha256', self::canonicalize($value));
    }

    /**
     * Admit JSON text (localization 16): objects become {@see JsonObject}, and arrays become PHP lists.
     *
     * @throws InvalidClientCatalog
     */
    public static function parse(string $json): mixed
    {
        if (! mb_check_encoding($json, 'UTF-8')) {
            throw new InvalidClientCatalog('Client catalog JSON is not valid UTF-8.');
        }
        $at = 0;
        $value = self::value($json, $at, 0);
        self::space($json, $at);
        if ($at !== strlen($json)) {
            throw new InvalidClientCatalog("Unexpected trailing content at byte {$at}.");
        }

        return $value;
    }

    /** @param  array<array-key, mixed>  $members */
    private static function object(array $members): string
    {
        $keys = array_map('strval', array_keys($members));
        usort($keys, static fn (string $a, string $b): int => strcmp(self::utf16($a), self::utf16($b)));

        return '{'.implode(',', array_map(static fn (string $key): string => self::string($key).':'.self::canonicalize($members[$key]), $keys)).'}';
    }

    private static function utf16(string $s): string
    {
        return mb_convert_encoding($s, 'UTF-16BE', 'UTF-8');
    }

    private static function string(string $s): string
    {
        try {
            return json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidClientCatalog('JCS cannot encode a string that is not valid UTF-8.', 0, $e);
        }
    }

    private static function integer(int $n): string
    {
        if (abs($n) > self::MAX_SAFE_INTEGER) {
            return self::number((float) $n);
        }

        return (string) $n;
    }

    /** ES6 Number::toString over PHP's shortest round-trip digits. */
    private static function number(float $n): string
    {
        if (! is_finite($n)) {
            throw new InvalidClientCatalog('JCS refuses a non-finite number.');
        }
        if ($n == 0.0) {
            return '0';
        }
        $sign = $n < 0 ? '-' : '';
        $previous = ini_get('serialize_precision');
        ini_set('serialize_precision', '-1');
        try {
            $repr = (string) json_encode(abs($n));
        } finally {
            ini_set('serialize_precision', (string) $previous);
        }
        if (preg_match('/^(\d+)(?:\.(\d+))?(?:[eE]([+-]?\d+))?$/', $repr, $m) !== 1) {
            throw new InvalidClientCatalog('Unexpected float representation '.$repr.'.');
        }
        $all = $m[1].($m[2] ?? '');
        $trimmed = ltrim($all, '0');
        // value = 0.<digits> × 10^point
        $point = (int) ($m[3] ?? 0) + strlen($m[1]) - (strlen($all) - strlen($trimmed));
        $digits = rtrim($trimmed, '0');
        $k = strlen($digits);
        if ($k <= $point && $point <= 21) {
            return $sign.$digits.str_repeat('0', $point - $k);
        }
        if ($point > 0 && $point <= 21) {
            return $sign.substr($digits, 0, $point).'.'.substr($digits, $point);
        }
        if ($point > -6 && $point <= 0) {
            return $sign.'0.'.str_repeat('0', -$point).$digits;
        }
        $e = $point - 1;

        return $sign.$digits[0].($k > 1 ? '.'.substr($digits, 1) : '').'e'.($e >= 0 ? '+' : '-').abs($e);
    }

    private static function space(string $s, int &$at): void
    {
        $at += strspn($s, " \t\n\r", $at);
    }

    private static function value(string $s, int &$at, int $depth): mixed
    {
        if ($depth > 512) {
            throw new InvalidClientCatalog('Client catalog JSON nests too deeply.');
        }
        self::space($s, $at);
        $c = $s[$at] ?? '';

        return match (true) {
            $c === '{' => self::parseObject($s, $at, $depth),
            $c === '[' => self::parseArray($s, $at, $depth),
            $c === '"' => self::parseString($s, $at),
            $c === 't' && substr($s, $at, 4) === 'true' => self::advance($at, 4, true),
            $c === 'f' && substr($s, $at, 5) === 'false' => self::advance($at, 5, false),
            $c === 'n' && substr($s, $at, 4) === 'null' => self::advance($at, 4, null),
            $c === '-' || ctype_digit($c) => self::parseNumber($s, $at),
            default => throw new InvalidClientCatalog("Unexpected JSON at byte {$at}."),
        };
    }

    private static function advance(int &$at, int $by, mixed $value): mixed
    {
        $at += $by;

        return $value;
    }

    private static function parseObject(string $s, int &$at, int $depth): JsonObject
    {
        $at++;
        $members = [];
        self::space($s, $at);
        if (($s[$at] ?? '') === '}') {
            $at++;

            return new JsonObject;
        }
        while (true) {
            self::space($s, $at);
            if (($s[$at] ?? '') !== '"') {
                throw new InvalidClientCatalog("Expected an object key at byte {$at}.");
            }
            $key = self::parseString($s, $at);
            if (array_key_exists($key, $members)) {
                throw new InvalidClientCatalog("Duplicate object key [{$key}].");
            }
            self::space($s, $at);
            if (($s[$at] ?? '') !== ':') {
                throw new InvalidClientCatalog("Expected ':' at byte {$at}.");
            }
            $at++;
            $members[$key] = self::value($s, $at, $depth + 1);
            self::space($s, $at);
            $c = $s[$at++] ?? '';
            if ($c === '}') {
                return new JsonObject($members);
            }
            if ($c !== ',') {
                throw new InvalidClientCatalog('Expected \',\' or \'}\' at byte '.($at - 1).'.');
            }
        }
    }

    /** @return list<mixed> */
    private static function parseArray(string $s, int &$at, int $depth): array
    {
        $at++;
        $items = [];
        self::space($s, $at);
        if (($s[$at] ?? '') === ']') {
            $at++;

            return [];
        }
        while (true) {
            $items[] = self::value($s, $at, $depth + 1);
            self::space($s, $at);
            $c = $s[$at++] ?? '';
            if ($c === ']') {
                return $items;
            }
            if ($c !== ',') {
                throw new InvalidClientCatalog('Expected \',\' or \']\' at byte '.($at - 1).'.');
            }
        }
    }

    private static function parseString(string $s, int &$at): string
    {
        $at++;
        $out = '';
        $length = strlen($s);
        while ($at < $length) {
            $c = $s[$at];
            if ($c === '"') {
                $at++;

                return $out;
            }
            if (ord($c) < 0x20) {
                throw new InvalidClientCatalog("Raw control character in a string at byte {$at}.");
            }
            if ($c !== '\\') {
                $run = strcspn($s, "\"\\\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f", $at);
                $out .= substr($s, $at, $run);
                $at += $run;

                continue;
            }
            $e = $s[$at + 1] ?? '';
            $simple = ['"' => '"', '\\' => '\\', '/' => '/', 'b' => "\x08", 'f' => "\f", 'n' => "\n", 'r' => "\r", 't' => "\t"];
            if (isset($simple[$e])) {
                $out .= $simple[$e];
                $at += 2;

                continue;
            }
            if ($e !== 'u') {
                throw new InvalidClientCatalog("Invalid escape at byte {$at}.");
            }
            $unit = self::hex4($s, $at + 2);
            $at += 6;
            if ($unit >= 0xD800 && $unit <= 0xDBFF) {
                if (substr($s, $at, 2) !== '\\u') {
                    throw new InvalidClientCatalog('Lone high surrogate in a string.');
                }
                $low = self::hex4($s, $at + 2);
                if ($low < 0xDC00 || $low > 0xDFFF) {
                    throw new InvalidClientCatalog('Lone high surrogate in a string.');
                }
                $at += 6;
                $out .= mb_chr(0x10000 + (($unit - 0xD800) << 10) + ($low - 0xDC00), 'UTF-8');

                continue;
            }
            if ($unit >= 0xDC00 && $unit <= 0xDFFF) {
                throw new InvalidClientCatalog('Lone low surrogate in a string.');
            }
            $out .= mb_chr($unit, 'UTF-8');
        }

        throw new InvalidClientCatalog('Unterminated string.');
    }

    private static function hex4(string $s, int $at): int
    {
        $hex = substr($s, $at, 4);
        if (strlen($hex) !== 4 || ! ctype_xdigit($hex)) {
            throw new InvalidClientCatalog("Invalid \\u escape at byte {$at}.");
        }

        return (int) hexdec($hex);
    }

    private static function parseNumber(string $s, int &$at): int|float
    {
        if (preg_match('/-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?/A', $s, $m, 0, $at) !== 1) {
            throw new InvalidClientCatalog("Invalid number at byte {$at}.");
        }
        $text = $m[0];
        $at += strlen($text);
        if (ctype_digit($s[$at] ?? '')) {
            throw new InvalidClientCatalog('A number has a leading zero.');
        }
        if (preg_match('/^-?\d+$/', $text) === 1) {
            $digits = ltrim($text, '-');
            if (strlen($digits) > 16 || (int) $digits > self::MAX_SAFE_INTEGER) {
                throw new InvalidClientCatalog("Integer {$text} is outside the safe range.");
            }

            return (int) $text;
        }
        $float = (float) $text;
        if (! is_finite($float)) {
            throw new InvalidClientCatalog("Number {$text} is not finite.");
        }

        return $float;
    }
}
