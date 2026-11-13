<?php

namespace Splicewire\Beam\Localization;

/**
 * The pseudo-locale transform (localization 06; ruling 02/03, `en-XA`): English, marked and padded, so any text that did
 * not come through the catalog shows up as plain English on screen. A pure function: no catalog, no files.
 *
 * - Each plural branch (`|`) is transformed on its own, and a leading condition (`{0}`, `[2,*]`, `{1,2}`) is kept as
 *   written, so selection still works on the transformed line.
 * - Letters are swapped for accented look-alikes, the branch is padded by about a third of its length (text grows in
 *   most languages), and it is wrapped in `⟦…⟧`, so a truncated or concatenated string is visible.
 * - A `:parameter` and an HTML tag are left byte-identical, so replacement and markup keep working.
 */
final class PseudoLocale
{
    public const OPEN = '⟦';

    public const CLOSE = '⟧';

    private const LETTERS = [
        'a' => 'á', 'c' => 'ç', 'e' => 'é', 'i' => 'í', 'n' => 'ñ', 'o' => 'ó', 'u' => 'ú', 'y' => 'ý',
        'A' => 'Å', 'C' => 'Ç', 'E' => 'É', 'I' => 'Í', 'N' => 'Ñ', 'O' => 'Ó', 'U' => 'Ú', 'Y' => 'Ý',
    ];

    public static function transform(string $line): string
    {
        if ($line === '') {
            return $line;
        }

        return implode('|', array_map(self::branch(...), explode('|', $line)));
    }

    /** Whether a text carries the pseudo-locale's marks. */
    public static function isMarked(string $text): bool
    {
        return str_contains($text, self::OPEN) && str_contains($text, self::CLOSE);
    }

    private static function branch(string $branch): string
    {
        $condition = '';
        if (preg_match('/^\s*([\{\[][^\}\]]*[\}\]])\s*/u', $branch, $m) === 1) {
            $condition = $m[0];
            $branch = substr($branch, strlen($m[0]));
        }

        // Split into kept tokens (a :parameter, an HTML tag) and text; only text is transformed.
        $parts = preg_split('/(:[A-Za-z_][A-Za-z0-9_]*|<[^>]*>)/u', $branch, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$branch];
        $out = '';
        $letters = 0;
        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) {
                $out .= $part;

                continue;
            }
            $out .= strtr($part, self::LETTERS);
            $letters += mb_strlen(trim($part));
        }

        $padding = str_repeat('·', (int) ceil($letters * 0.3));

        return $condition.self::OPEN.$out.$padding.self::CLOSE;
    }
}
