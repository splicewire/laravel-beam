<?php

namespace Splicewire\Beam\Localization\Client;

use Illuminate\Translation\FileLoader;

/**
 * The content fingerprints of everything the localization ladder can read (localization 16; ruling 02 §4; coord-lead
 * ruling (a), 00:51Z on SIGNATURES-16). They key the client-catalog memo, so a changed line yields a new catalog hash.
 *
 * - **Translations:** sha256 over the sorted `(path, sha256(contents))` of every `.php` and `.json` file under the host's
 *   lang path, every base path the loader reads (`addPath()` ones included), every hinted namespace path and every JSON
 *   path the translator loader knows (realm rungs included), plus the ORDERED source list itself, since the loader's
 *   precedence follows it (B11-B3). CONTENTS, never mtimes.
 * - **Config:** sha256 of JCS over the realm and localization configuration the ladder reads.
 *
 * `php artisan beam:lang:fingerprint` writes the translation fingerprint to {@see artifactPath()}; it's hooked into
 * `optimize`, and `--clear` into `optimize:clear`. That's the explicit-invalidation build artifact. With no artifact
 * (development, or after `optimize:clear`), it's recomputed from contents on every call, so a changed line is seen in the
 * same process.
 */
final class LangFingerprint
{
    public static function artifactPath(): string
    {
        return app()->bootstrapPath('cache/beam-lang-fingerprint.php');
    }

    /** The translation fingerprint: the artifact's when one exists, else computed from contents now. */
    public static function translations(): string
    {
        $artifact = self::artifactPath();
        if (is_file($artifact)) {
            $stored = require $artifact;
            if (is_array($stored) && is_string($stored['translations'] ?? null)) {
                return $stored['translations'];
            }
        }

        return self::computeTranslations();
    }

    public static function computeTranslations(): string
    {
        $lines = [];
        $directories = self::directories();
        foreach ($directories as $directory) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->isFile() && in_array($file->getExtension(), ['php', 'json'], true)) {
                    $lines[$file->getPathname()] = hash_file('sha256', $file->getPathname());
                }
            }
        }
        ksort($lines);

        $rows = ['sources'."\0".implode("\0", $directories)];
        foreach ($lines as $path => $hash) {
            $rows[] = $path."\0".$hash;
        }

        return hash('sha256', implode("\n", $rows));
    }

    /** The realm and localization configuration the ladder reads (realm rungs, supported list, fallback, pseudo). */
    public static function config(): string
    {
        return Jcs::hash([
            'realms' => self::plain(config('beam.core.realms', [])),
            'localization' => self::plain(config('beam.localization', [])),
        ]);
    }

    /** @return list<string> */
    private static function directories(): array
    {
        $directories = [app()->langPath(), base_path('lang')];
        $loader = app('translator')->getLoader();
        if ($loader instanceof FileLoader) {
            $directories = [...$directories, ...$loader->paths(), ...array_values($loader->namespaces()), ...$loader->jsonPaths()];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $dir): ?string => is_string($dir) && is_dir($dir) ? (realpath($dir) ?: $dir) : null,
            $directories,
        ))));
    }

    /** Config as JCS-safe plain data: objects become arrays, and closures and objects become their class name. */
    private static function plain(mixed $value): mixed
    {
        return match (true) {
            is_array($value) => array_map(self::plain(...), $value),
            is_object($value) => $value::class,
            is_float($value) && ! is_finite($value) => (string) $value,
            default => $value,
        };
    }
}
