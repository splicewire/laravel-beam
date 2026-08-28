<?php

declare(strict_types=1);

namespace Splicewire\Beam\Tests\Doctor;

/**
 * A throwaway host tree for the two family-Tailwind audits: a CSS entry, a `vite.config.ts`, and
 * family packages SYMLINKED into `node_modules` from outside the root — which is the whole point,
 * since the defect both audits exist for is that Tailwind does not follow that symlink.
 *
 * ⚠️ The scratch root is pid- AND uniqid-keyed. A fixed `sys_get_temp_dir()` name collides across
 * concurrent sessions and parallel workers, and that wreckage reads as a real regression (it once
 * accounted for 29 of a 35-failure delta in this estate).
 */
final class FamilyTailwindHost
{
    public readonly string $root;

    private readonly string $scratch;

    public function __construct()
    {
        $this->scratch = sys_get_temp_dir().'/beam-family-tw-'.getmypid().'-'.uniqid();
        $this->root = $this->scratch.'/host';

        @mkdir($this->root.'/node_modules', 0777, true);
        @mkdir($this->scratch.'/workspace', 0777, true);
    }

    /** Write a file under the host root, creating parents. */
    public function write(string $relative, string $contents): string
    {
        $path = $this->root.'/'.ltrim($relative, '/');
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * A family package living OUTSIDE the host, symlinked into `node_modules` — the real resolution
     * shape. `$files` is relative-path => contents under the package's `dist`.
     *
     * @param  array<string, string>  $files
     */
    public function package(string $name, array $files): string
    {
        $real = $this->scratch.'/workspace/'.str_replace('/', '__', $name);

        foreach ($files as $relative => $contents) {
            $path = $real.'/dist/'.ltrim($relative, '/');
            @mkdir(dirname($path), 0777, true);
            file_put_contents($path, $contents);
        }

        if ($files === []) {
            @mkdir($real, 0777, true);
        }

        [$scope, $short] = explode('/', $name);
        @mkdir($this->root.'/node_modules/'.$scope, 0777, true);
        @symlink($real, $this->root.'/node_modules/'.$scope.'/'.$short);

        return $real;
    }

    public function __destruct()
    {
        $this->remove($this->scratch);
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path.'/'.$entry);
            }
        }

        @rmdir($path);
    }
}
