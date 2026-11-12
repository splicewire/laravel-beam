<?php

namespace Splicewire\Beam\Localization;

/**
 * The locale chain of the ladder's OUTER loop (localization ruling 05 §2): the requested locale, each parent
 * language it has, then the fallback locale and its parents. Deterministic and duplicate-free.
 *
 * Locales are canonical hyphen tags (`es-MX`, `zh-Hant-TW`): an underscore spelling (`es_MX`, the framework's
 * habit) is read as the same tag. A parent is the tag with its last subtag removed, so `zh-Hant-TW` → `zh-Hant` →
 * `zh`. The requested locale's own spelling is kept first, because a host's lang directory is named the way the host
 * named it; the canonical spelling and the parents follow.
 */
final class LocaleChain
{
    /**
     * @return list<string>
     */
    public static function for(string $locale, ?string $fallback): array
    {
        $chain = [];

        foreach ([$locale, $fallback] as $start) {
            if ($start === null || $start === '') {
                continue;
            }

            $chain[] = $start;
            foreach (self::lineage(self::canonical($start)) as $tag) {
                $chain[] = $tag;
            }
        }

        return array_values(array_unique($chain));
    }

    /**
     * The canonical hyphen spelling: `es_mx` → `es-MX`, `ZH_hant_tw` → `zh-Hant-TW`. Language lower-case, a four-letter
     * script title-case, a two-letter region upper-case; any other subtag (a digit region like `419`) as written.
     */
    public static function canonical(string $locale): string
    {
        $parts = preg_split('/[-_]/', trim($locale)) ?: [];
        $out = [];

        foreach (array_values(array_filter($parts, static fn (string $p): bool => $p !== '')) as $i => $part) {
            $out[] = match (true) {
                $i === 0 => strtolower($part),
                strlen($part) === 4 && ctype_alpha($part) => ucfirst(strtolower($part)),
                strlen($part) === 2 && ctype_alpha($part) => strtoupper($part),
                default => $part,
            };
        }

        return implode('-', $out);
    }

    /**
     * The tag and each parent, most specific first: `es-MX` → [`es-MX`, `es`].
     *
     * @return list<string>
     */
    public static function lineage(string $canonical): array
    {
        $parts = explode('-', $canonical);
        $lineage = [];

        for ($n = count($parts); $n > 0; $n--) {
            $lineage[] = implode('-', array_slice($parts, 0, $n));
        }

        return $lineage;
    }
}
