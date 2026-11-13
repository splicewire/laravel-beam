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

    /** @return array{total: int, files: array<string, int>} the counts now */
    public static function measure(string $root): array
    {
        $files = LiteralTextCensus::count($root);

        return ['total' => array_sum($files), 'files' => $files];
    }

    public static function write(string $root): string
    {
        $path = rtrim($root, '/').'/'.self::FILE;
        $baseline = [
            'about' => 'localization 12 ratchet: user-facing string literals in response-reaching calls. May only fall. '
                .'Regenerate with `php artisan beam:lang:ratchet --write` (or the package\'s equivalent); never edit by hand.',
            'population' => LiteralTextCensus::POPULATION,
            ...self::measure($root),
        ];
        file_put_contents($path, json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        return $path;
    }

    /**
     * @return array{rose: array<string, array{0: int, 1: int}>, fell: array<string, array{0: int, 1: int}>, missing: bool}
     *                                                                                                                      file => [baseline, now]
     */
    public static function compare(string $root): array
    {
        $path = rtrim($root, '/').'/'.self::FILE;
        if (! is_file($path)) {
            return ['rose' => [], 'fell' => [], 'missing' => true];
        }
        try {
            $baseline = (array) (json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)['files'] ?? []);
        } catch (JsonException) {
            return ['rose' => [], 'fell' => [], 'missing' => true];
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

        return ['rose' => $rose, 'fell' => $fell, 'missing' => false];
    }
}
