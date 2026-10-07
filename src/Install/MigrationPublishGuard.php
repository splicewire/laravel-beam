<?php

namespace Splicewire\Beam\Install;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Migrations\MigrationRepositoryInterface;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;

/**
 * Keeps a package-tools migration stub one migration for the lifetime of a host.
 *
 * Package-tools stamps a stub when its provider boots. A later installer process therefore sees the
 * same source identity with a new destination filename, and Laravel's ordinary existence check cannot
 * recognise the earlier copy. Identity here is the timestamp-free stub stem, checked against both the
 * host's published files and its migration ledger (the file may have been retired after it ran).
 */
final class MigrationPublishGuard
{
    public function __construct(
        private readonly Application $app,
        private readonly Filesystem $files,
    ) {}

    /**
     * @return array{paths: array<string, string>, skipped: list<string>}
     */
    public function pathsFor(string $tag): array
    {
        $known = $this->knownMigrationIdentities();
        $paths = [];
        $skipped = [];

        foreach (ServiceProvider::pathsToPublish(null, $tag) as $source => $destination) {
            $identity = $this->migrationIdentity((string) $source, (string) $destination);

            if ($identity !== null && isset($known[$identity])) {
                $skipped[] = $identity;

                continue;
            }

            $paths[(string) $source] = (string) $destination;

            if ($identity !== null) {
                // A duplicate identity later in the same tag must not be published twice.
                $known[$identity] = true;
            }
        }

        return ['paths' => $paths, 'skipped' => array_values(array_unique($skipped))];
    }

    /** @return array<string, true> */
    private function knownMigrationIdentities(): array
    {
        $known = [];
        $root = $this->app->databasePath('migrations');

        if ($this->files->isDirectory($root)) {
            foreach ($this->files->allFiles($root) as $file) {
                if ($file->getExtension() === 'php') {
                    $known[$this->identityFromStampedName($file->getBasename('.php'))] = true;
                }
            }
        }

        /** @var MigrationRepositoryInterface $repository */
        $repository = $this->app->make('migration.repository');

        if ($repository->repositoryExists()) {
            foreach ($repository->getRan() as $migration) {
                $known[$this->identityFromStampedName((string) $migration)] = true;
            }
        }

        return $known;
    }

    private function migrationIdentity(string $source, string $destination): ?string
    {
        $root = rtrim($this->app->databasePath('migrations'), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        if (! str_ends_with($source, '.php.stub') || ! str_starts_with($destination, $root)) {
            return null;
        }

        return substr(basename($source), 0, -strlen('.php.stub'));
    }

    private function identityFromStampedName(string $name): string
    {
        return (string) preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', $name);
    }
}
