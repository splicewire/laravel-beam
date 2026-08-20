<?php

namespace Splicewire\Beam\OpenApi;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;
use Knuckles\Scribe\Writing\Writer;
use Symfony\Component\Yaml\Yaml;

/**
 * The default {@see OpenApiSpecSource}: one artifact on disk at `beam.core.openapi.artifact`, served
 * as-is for YAML and derived for JSON (ADR-0211 §2/§6).
 *
 * ## Why a configured DISK PATH and not Scribe's own resolution
 *
 * Reading the location back off Scribe's internal `$this->paths` resolution would couple beam to
 * something it does not control. Hard-coding it would foreclose the pre-generated-variant future the
 * §3 seam exists to keep open. A disk path is also not a URL, so it does not reopen the docs-path
 * question ticket 02 settled — the public path is fixed and package-owned.
 *
 * ## Why the JSON derivation is cached on MTIME
 *
 * Parsing a half-megabyte YAML document and re-encoding it on every request is real work, and both
 * representations must be incapable of drifting apart. Keying the cache entry on the artifact's mtime
 * gives both properties for free: a regeneration changes the mtime, which changes the key, so the next
 * request derives afresh and no invalidation call is needed anywhere. The cost is one live key per
 * regeneration — the superseded entries are dead weight the store evicts, never stale answers.
 *
 * A public `GET` still writes NOTHING to storage (ADR-0211 §4). The cache is a cache.
 */
class ConfiguredArtifactSpecSource implements OpenApiSpecSource
{
    public function __construct(private CacheRepository $cache) {}

    public function spec(SpecFormat $format, Request $request): ?OpenApiSpec
    {
        $path = $this->artifactPath();

        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $mtime = (int) (filemtime($path) ?: 0);

        $body = match ($format) {
            SpecFormat::Yaml => (string) file_get_contents($path),
            SpecFormat::Json => $this->deriveJson($path, $mtime),
        };

        return new OpenApiSpec($body, $format, $mtime);
    }

    /**
     * The configured artifact path, or — when unset — where Scribe's `laravel` output type actually
     * writes it.
     *
     * ## The default is DERIVED, and it has to be (correction to ADR-0211 §6)
     *
     * The ADR named a literal `storage_path('app/scribe/openapi.yaml')`. On a real bare host that is
     * WRONG and 404s out of the box: Scribe writes through `Storage::disk('local')`
     * ({@see Writer::writeOpenAPISpec()}), and the Laravel 11+ skeleton roots the
     * local disk at `storage/app/private`, not `storage/app`. A fresh `laravel-beam-starter` generated its
     * spec to `storage/app/private/scribe/openapi.yaml` while beam looked for it one directory up.
     *
     * Deriving from `filesystems.disks.local.root` is not the coupling §6 warned against — that was about
     * reading Scribe's INTERNAL `$this->paths` resolution. The local disk root is ordinary published
     * Laravel config, it is the same value Scribe itself resolves through, and it follows a host that
     * repoints the disk. The `scribe/` subdirectory is Scribe's default output dir (`--scribe-dir`
     * overrides it, at which point a host sets `beam.core.openapi.artifact` explicitly).
     */
    public function artifactPath(): string
    {
        $configured = config('beam.core.openapi.artifact');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $root = config('filesystems.disks.local.root');
        $root = is_string($root) && $root !== '' ? $root : storage_path('app');

        return rtrim($root, '/').'/scribe/openapi.yaml';
    }

    /**
     * YAML → JSON, memoized against the artifact's mtime.
     *
     * A malformed artifact throws out of here rather than being swallowed into a 404: "no spec" and
     * "a broken spec" are different operator problems, and only one of them is fixed by running the
     * generator. Nothing is cached when the parse throws.
     */
    private function deriveJson(string $path, int $mtime): string
    {
        $key = 'beam:openapi:json:'.$mtime.':'.md5($path);

        return (string) $this->cache->rememberForever($key, static function () use ($path): string {
            return (string) json_encode(
                Yaml::parseFile($path),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        });
    }
}
