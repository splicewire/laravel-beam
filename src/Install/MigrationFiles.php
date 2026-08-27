<?php

namespace Splicewire\Beam\Install;

use Illuminate\Contracts\Foundation\Application;

/**
 * Every migration file the migrator would run, as [prefix, stem, absolute path].
 *
 * Extracted (beam-facade ticket 84) because the install now asks TWO questions of the same population
 * between publish and migrate — {@see TableOwnershipResolver} asks whose FILENAME wins, and
 * {@see ConvergencePreflight} asks whether each declared SHAPE can land — and the two must be looking at
 * the same files or one of them is answering about migrations the other run will not see.
 *
 * THE ENUMERATION MATCHES `Illuminate\Database\Migrations\Migrator` EXACTLY, and that is the whole
 * contract: non-recursive per path, globbed `*_*.php`. A subdirectory is only ever scanned because
 * something registered it as its own path, which is why beam's `database/migrations/shared/` is seen at
 * all and why a `tenant/` directory that only stancl knows about is correctly NOT — a preflight that
 * rehearsed tenant stubs against the central connection would report on a pass this migrate never makes.
 */
class MigrationFiles
{
    /** The paths the next `migrate` will read: the app's own, plus everything a provider registered. */
    public static function pathsFor(Application $app): array
    {
        $paths = [$app->databasePath('migrations')];

        if ($app->bound('migrator')) {
            $paths = array_merge($paths, $app->make('migrator')->paths());
        }

        return array_values(array_unique($paths));
    }

    /**
     * @param  list<string>  $paths
     * @return list<array{0: string, 1: string, 2: string}> [prefix, stem, absolute path]
     */
    public static function in(array $paths): array
    {
        $seen = [];
        $files = [];

        foreach ($paths as $path) {
            foreach (glob(rtrim($path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'*_*.php') ?: [] as $file) {
                $real = realpath($file) ?: $file;

                if (isset($seen[$real])) {
                    continue;
                }

                $seen[$real] = true;

                $name = basename($file, '.php');

                if (! preg_match('/^(\d{4}_\d{2}_\d{2}_\d+)_(.+)$/', $name, $m)) {
                    continue;
                }

                $files[] = [$m[1], $m[2], $file];
            }
        }

        return $files;
    }
}
