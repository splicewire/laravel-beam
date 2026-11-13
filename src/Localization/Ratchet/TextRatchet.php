<?php

namespace Splicewire\Beam\Localization\Ratchet;

use JsonException;

/**
 * A package's committed literal-text baseline, `lang-ratchet.json` at its root (localization 12). The count may only
 * fall: {@see compare()} reports a file that rose and a file that fell (re-baseline it), so the committed number tracks
 * the code. Written only by `beam:lang:ratchet --write`; a test only compares.
 */
final class TextRatchet
{
    public const FILE = 'lang-ratchet.json';

    /** @return array{total: int, files: array<string, int>} the counts now (exclusions applied) */
    public static function measure(string $root): array
    {
        $files = LiteralTextCensus::count($root);

        return ['total' => array_sum($files), 'files' => $files];
    }

    /**
     * Every hit an exclusion removed, with its reason: what the ratchet reports instead of silently dropping it.
     *
     * @return list<array{file: string, line: int, method: ?string, kind: string, reason: string}>
     */
    public static function excluded(string $root): array
    {
        $exclusions = TextRatchetExclusions::load($root);
        $excluded = [];
        foreach (LiteralTextCensus::hits($root) as $hit) {
            if (($entry = $exclusions->match($hit)) !== null) {
                $excluded[] = $hit + ['reason' => $entry['reason']];
            }
        }

        return $excluded;
    }

    public static function write(string $root): string
    {
        $path = rtrim($root, '/').'/'.self::FILE;
        $baseline = [
            'about' => 'localization 12 ratchet: user-facing string literals in response-reaching calls. May only fall. '
                .'Regenerate with `php artisan beam:lang:ratchet --write` (or the package\'s equivalent); never edit by hand.',
            'population' => LiteralTextCensus::POPULATION,
            ...self::measure($root),
            // Reported, never silent: each excluded hit and the reviewed reason (lang-ratchet-exclusions.json).
            'excluded' => self::excluded($root),
        ];
        file_put_contents($path, json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        return $path;
    }

    /**
     * @return array{rose: array<string, array{0: int, 1: int}>, fell: array<string, array{0: int, 1: int}>, missing: bool,
     *               stale: list<array<string, mixed>>, invalid: list<string>}
     *                                                                     rose/fell: file => [baseline, now]
     */
    public static function compare(string $root): array
    {
        $exclusions = TextRatchetExclusions::load($root);
        $stale = $exclusions->stale(LiteralTextCensus::hits($root));
        $path = rtrim($root, '/').'/'.self::FILE;
        if (! is_file($path)) {
            return ['rose' => [], 'fell' => [], 'missing' => true, 'stale' => $stale, 'invalid' => $exclusions->errors];
        }
        try {
            $baseline = (array) (json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)['files'] ?? []);
        } catch (JsonException) {
            return ['rose' => [], 'fell' => [], 'missing' => true, 'stale' => $stale, 'invalid' => $exclusions->errors];
        }

        $now = self::measure($root)['files'];
        $rose = [];
        $fell = [];
        foreach (array_unique([...array_keys($baseline), ...array_keys($now)]) as $file) {
            $was = (int) ($baseline[$file] ?? 0);
            $is = (int) ($now[$file] ?? 0);
            if ($is > $was) {
                $rose[$file] = [$was, $is];
            } elseif ($is < $was) {
                $fell[$file] = [$was, $is];
            }
        }
        ksort($rose);
        ksort($fell);

        return ['rose' => $rose, 'fell' => $fell, 'missing' => false, 'stale' => $stale, 'invalid' => $exclusions->errors];
    }
}
