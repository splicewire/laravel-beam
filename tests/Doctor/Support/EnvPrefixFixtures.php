<?php

namespace Splicewire\Beam\Tests\Doctor\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Disposable copies of the env-inventory 02 fixture packages (`tests/Doctor/Fixtures/EnvPrefix`), so a test may add an
 * `env()` call or a baseline without touching the committed fixtures. Each copy lives in its own temp directory and
 * {@see self::reap()} removes it.
 */
final class EnvPrefixFixtures
{
    private const SOURCE = __DIR__.'/../Fixtures/EnvPrefix';

    /** @var list<string> */
    private array $made = [];

    /**
     * Copy the named fixture packages (`acme-widgets` → `acme/widgets`) into one fresh temp directory.
     *
     * @return array<string, string> composer name => package root
     */
    public function copy(string ...$fixtures): array
    {
        $base = sys_get_temp_dir().'/beam-env-prefix-'.bin2hex(random_bytes(6));
        mkdir($base, 0700);
        $this->made[] = $base;
        $packages = [];

        foreach ($fixtures as $fixture) {
            $target = $base.'/'.$fixture;
            $this->copyTree(self::SOURCE.'/'.$fixture, $target);
            $packages[str_replace('acme-', 'acme/', $fixture)] = (string) realpath($target);
        }

        return $packages;
    }

    /**
     * Every file below `$root`, relative path => sha256, so a test can prove nothing was written.
     *
     * @return array<string, string>
     */
    public static function snapshot(string $root): array
    {
        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $entry) {
            /** @var SplFileInfo $entry */
            $files[substr($entry->getPathname(), strlen($root) + 1)] = (string) hash_file('sha256', $entry->getPathname());
        }

        ksort($files);

        return $files;
    }

    public function reap(): void
    {
        foreach ($this->made as $base) {
            $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

            foreach ($entries as $entry) {
                /** @var SplFileInfo $entry */
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }

            rmdir($base);
        }

        $this->made = [];
    }

    private function copyTree(string $from, string $to): void
    {
        mkdir($to, 0700, true);

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $entry) {
            /** @var SplFileInfo $entry */
            $target = $to.'/'.substr($entry->getPathname(), strlen($from) + 1);
            $entry->isDir() ? mkdir($target, 0700, true) : copy($entry->getPathname(), $target);
        }
    }
}
