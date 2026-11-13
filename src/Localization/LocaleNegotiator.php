<?php

namespace Splicewire\Beam\Localization;

/**
 * `Accept-Language` negotiated against a supported list (ruling 03 §2).
 *
 * - Candidates are tried in descending q order (ties keep their header order); q=0 excludes a tag.
 * - Each candidate tries its exact tag, then its parents by right truncation (es-MX → es); the first supported wins.
 * - A wildcard (`*`) chooses nothing: it continues to the next source rather than picking an arbitrary language.
 * - An invalid or unsupported candidate is skipped.
 */
final class LocaleNegotiator
{
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
            $q = 1.0;
            foreach (array_slice($pieces, 1) as $parameter) {
                if (preg_match('/^q\s*=\s*([01](?:\.\d{0,3})?)$/i', $parameter, $m) === 1) {
                    $q = (float) $m[1];
                }
            }
            if ($tag === '' || $tag === '*' || $q <= 0.0 || preg_match('/^[A-Za-z]{1,8}(?:[-_][A-Za-z0-9]{1,8})*$/', $tag) !== 1) {
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
