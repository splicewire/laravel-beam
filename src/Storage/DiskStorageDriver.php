<?php

namespace Splicewire\Beam\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * The **filesystem projection** tier of the storage seam (beam-storage-driver charter) — the disk half
 * of the default {@see StackedStorageDriver}, and the generalization of the schema registry's committed
 * filesystem tier. Bodies are stored as JSON files at `$key` (a relative path the caller derives — for
 * beam-ux, from its paid `FilePlacement` policy) on a Laravel {@see Filesystem} disk.
 *
 * `list($namespace)` scans the disk and returns items whose stored `namespace` matches (the namespace
 * rides in the JSON envelope, since a key/path is not itself the grouping coordinate — ADR-0165 keeps
 * namespace→disk a *derivation*, not an identity). `staleness` compares the disk file's mtime against a
 * candidate — the seam an operator's config-gated `update-from-newer` reads to decide if disk is newer.
 */
class DiskStorageDriver implements StorageDriver
{
    public function __construct(protected Filesystem $disk) {}

    public function read(string $key): ?StorageItem
    {
        if (! $this->disk->exists($key)) {
            return null;
        }

        return $this->itemFrom($key, (string) $this->disk->get($key));
    }

    public function write(string $key, array $body, ?string $namespace = null): StorageItem
    {
        $envelope = ['namespace' => $namespace, 'body' => $body];
        $this->disk->put($key, (string) json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return new StorageItem($key, $body, $namespace, $this->modifiedAt($key));
    }

    public function list(?string $namespace = null): array
    {
        $items = [];

        foreach ($this->disk->allFiles() as $path) {
            $item = $this->read($path);
            if ($item === null) {
                continue;
            }
            if ($namespace !== null && $item->namespace !== $namespace) {
                continue;
            }
            $items[] = $item;
        }

        return $items;
    }

    public function staleness(string $key, int $candidateModifiedAt): int
    {
        $stored = $this->modifiedAt($key);
        if ($stored === null) {
            return 0;
        }

        return $candidateModifiedAt <=> $stored;
    }

    protected function itemFrom(string $key, string $contents): StorageItem
    {
        $decoded = json_decode($contents, true);
        $body = is_array($decoded['body'] ?? null) ? $decoded['body'] : (is_array($decoded) ? $decoded : []);
        $namespace = is_string($decoded['namespace'] ?? null) ? $decoded['namespace'] : null;

        return new StorageItem($key, $body, $namespace, $this->modifiedAt($key));
    }

    protected function modifiedAt(string $key): ?int
    {
        return $this->disk->exists($key) ? $this->disk->lastModified($key) : null;
    }
}
