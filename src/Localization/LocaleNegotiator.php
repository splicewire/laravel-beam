<?php

namespace Splicewire\Beam\Localization;

/**
 * `Accept-Language` negotiated against a supported list (ruling 03 §2).
 *
 * - Candidates are tried in descending q order (ties keep their header order); q=0 excludes a tag.
 * - Each candidate tries its exact tag, then its parents by right truncation (es-MX → es); the first supported wins.
 * - A wildcard (`*`) chooses nothing: it continues to the next source rather than picking an arbitrary language.
 * - An invalid or unsupported candidate is skipped, and so is one whose `q` is not a valid qvalue (RFC 9110).
 */
final class LocaleNegotiator
{
    /**
     * A candidate's weight (RFC 9110 §12.4.2: `qvalue = ( "0" [ "." 0*3DIGIT ] ) / ( "1" [ "." 0*3("0") ] )`): 1 when
     * no `q` is given; null when a `q` is given but is not a valid qvalue (malformed, above 1, more than three decimals),
     * which DISCARDS the candidate (REVIEW-B6 S1) rather than promoting it to the maximum.
     *
     * @param  list<string>  $parameters
     */
    private static function weight(array $parameters): ?float
    {
        foreach ($parameters as $parameter) {
            if (preg_match('/^q\s*=\s*(.*)$/i', $parameter, $m) !== 1) {
                continue;
            }

            return preg_match('/^(0(\.\d{0,3})?|1(\.0{0,3})?)$/', trim($m[1])) === 1 ? (float) trim($m[1]) : null;
        }

        return 1.0;
    }

    /**
     * @param  list<string>  $supported  canonical tags
     */
    public static function negotiate(?string $header, array $supported): ?string
    {
        if ($header === null || trim($header) === '' || $supported === []) {
            return null;
        }

        $candidates = [];
        foreach (explode(',', $header) as $position => $part) {
            $pieces = array_map('trim', explode(';', $part));
            $tag = $pieces[0];
            $q = self::weight(array_slice($pieces, 1));
            if ($q === null || $tag === '' || $tag === '*' || $q <= 0.0 || preg_match('/^[A-Za-z]{1,8}(?:[-_][A-Za-z0-9]{1,8})*$/', $tag) !== 1) {
                continue;
            }
            $candidates[] = ['tag' => $tag, 'q' => $q, 'position' => $position];
        }

        usort($candidates, static fn (array $a, array $b): int => $b['q'] <=> $a['q'] ?: $a['position'] <=> $b['position']);

        foreach ($candidates as $candidate) {
            foreach (LocaleChain::lineage(LocaleChain::canonical($candidate['tag'])) as $tag) {
                if (in_array($tag, $supported, true)) {
                    return $tag;
                }
            }
        }

        return null;
    }
}
