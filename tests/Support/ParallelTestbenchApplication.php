<?php

namespace Splicewire\Beam\Tests\Support;

use FilesystemIterator;
use RuntimeException;

final class ParallelTestbenchApplication
{
    private static ?string $basePath = null;

    public static function basePath(string $source): string
    {
        if (self::$basePath !== null) {
            return self::$basePath;
        }

        self::removeAbandonedApplications();

        $token = getenv('UNIQUE_TEST_TOKEN');

        if (! is_string($token) || $token === '') {
            $token = getenv('TEST_TOKEN').'-'.getmypid();
        }

        $basePath = sys_get_temp_dir().'/laravel-beam-testbench-'.sha1($source."\0".$token).'-'.getmypid();

        register_shutdown_function(static function () use ($basePath): void {
            self::deleteDirectory($basePath);
        });

        try {
            self::copyDirectory($source, $basePath);
        } catch (\Throwable $exception) {
            self::deleteDirectory($basePath);

            throw $exception;
        }

        self::$basePath = $basePath;

        return $basePath;
    }

    private static function removeAbandonedApplications(): void
    {
        $paths = glob(sys_get_temp_dir().'/laravel-beam-testbench-*-*', GLOB_ONLYDIR);

        if ($paths === false) {
            return;
        }

        foreach ($paths as $path) {
            if (! preg_match('/^laravel-beam-testbench-[0-9a-f]{40}-(\d+)$/', basename($path), $matches)) {
                continue;
            }

            $pid = (int) $matches[1];

            if ($pid === getmypid() || ! function_exists('posix_kill') || @posix_kill($pid, 0)) {
                continue;
            }

            $claimed = $path.'.reaping-'.getmypid();

            if (@rename($path, $claimed)) {
                self::deleteDirectory($claimed);
            }
        }
    }

    private static function copyDirectory(string $source, string $destination): void
    {
        if (! is_dir($destination) && ! mkdir($destination, 0777, true) && ! is_dir($destination)) {
            throw new RuntimeException("Unable to create isolated Testbench directory [{$destination}].");
        }

        foreach (new FilesystemIterator($source, FilesystemIterator::SKIP_DOTS) as $item) {
            $target = $destination.'/'.$item->getBasename();

            if ($item->isLink()) {
                $link = readlink($item->getPathname());

                if ($link === false || ! symlink($link, $target)) {
                    throw new RuntimeException("Unable to copy Testbench symlink [{$target}].");
                }
            } elseif ($item->isDir()) {
                self::copyDirectory($item->getPathname(), $target);
            } elseif (! copy($item->getPathname(), $target)) {
                throw new RuntimeException("Unable to copy Testbench file [{$target}].");
            }
        }
    }

    private static function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $item) {
            if ($item->isLink() || $item->isFile()) {
                @unlink($item->getPathname());
            } else {
                self::deleteDirectory($item->getPathname());
            }
        }

        @rmdir($directory);
    }
}
