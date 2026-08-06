<?php

namespace Splicewire\Beam\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Process;
use Splicewire\Beam\Codegen\RouteManifestModelSource;
use Splicewire\Beam\Codegen\TsClientGenerator;
use Splicewire\Beam\Source\RouteManifestSource;

/**
 * The client-SDK codegen (client-sdk-codegen PRD; PROMOTED into laravel-beam from the platform app),
 * refactored onto the codegen substrate seam (codegen-substrate #05). The *rendering* now lives in a
 * reusable {@see TsClientGenerator} (a `rushing/codegen` generator, stack `ts-client`); this command is
 * the host that resolves the route manifest, projects it into the canonical model via
 * {@see RouteManifestModelSource}, and owns the disk/format concerns (out dir, orphan-clearing,
 * Prettier). Output is byte-identical to the former string-concat command — the generator ports the
 * emission verbatim, and `GenerateClientSdkCommandTest` + the committed `ui/src/generated` tree guard
 * parity.
 *
 * SOURCE-AGNOSTIC (the promotion's whole point): it reads {@see RouteManifestSource} instances resolved
 * from `beam.client.sources.{defaults,admin}`. The platform binds a Tower-backed source; a satellite
 * binds the package's `ParticleRouteManifestSource`. The admin tier is optional — an unbound admin
 * source yields an empty `adminDefaults` map and no admin hooks.
 */
class GenerateClientSdkCommand extends Command
{
    protected $signature = 'splicewire:beam:generate:client';

    protected $description = 'Generate the committed client SDK (route map + typed access layer) from the route manifest';

    public function __construct(private readonly Container $container)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dir = $this->outDir();

        // Project the two realm manifests into the canonical model, then render via the generator.
        $model = (new RouteManifestModelSource(
            $this->source('defaults'),
            $this->source('admin'),
        ))->model();

        $result = (new TsClientGenerator)->invoke([
            'model' => $model->toArray(),
            'options' => [
                'client_import' => $this->clientImport(),
                'routes_import' => $this->routesImport(),
                'emit_stores' => (bool) config('beam.client.emit_stores', false),
            ],
        ]);

        $this->writeFiles($dir, $result['files'], (bool) config('beam.client.emit_stores', false));

        foreach ($result['warnings'] ?? [] as $warning) {
            $this->components->warn($warning);
        }

        // Canonicalise to the repo's Prettier so the committed artifact is byte-identical to a manual
        // `prettier --write` — a `generate`-then-check CI guard stays honest without a format step in the
        // pipeline.
        $this->prettier($dir);

        $stats = $result['stats'] ?? ['typed' => 0, 'domains' => 0];
        $this->components->info(sprintf(
            'Wrote %s: routes.ts + %d typed hook(s) across %d domain(s).',
            $this->relativeOutDir(),
            $stats['typed'],
            $stats['domains'],
        ));

        return self::SUCCESS;
    }

    /**
     * Write the generator's file map under the out dir. Hooks (and stores, when enabled) are reset
     * first so a removed route leaves no orphan file — the drift guard depends on it.
     *
     * @param  array<string, string>  $files
     */
    private function writeFiles(string $dir, array $files, bool $emitStores): void
    {
        $this->ensureDir($dir);
        $this->resetDir($dir.'/hooks');

        if ($emitStores) {
            $this->resetDir($dir.'/stores');
        }

        foreach ($files as $relative => $contents) {
            $path = $dir.'/'.$relative;
            $this->ensureDir(dirname($path));
            file_put_contents($path, $contents);
        }
    }

    // ── config-driven bits ───────────────────────────────────────────────────────────────────────

    /** Resolve a bound {@see RouteManifestSource} for a realm, or null when the host doesn't bind one. */
    private function source(string $realm): ?RouteManifestSource
    {
        $binding = config("beam.client.sources.{$realm}");

        if (! is_string($binding) || $binding === '') {
            return null;
        }

        $source = $this->container->make($binding);

        return $source instanceof RouteManifestSource ? $source : null;
    }

    /** The absolute generated-SDK root. Default: the satellite's `resources/js/generated`. */
    private function outDir(): string
    {
        $configured = config('beam.client.out_dir');

        return is_string($configured) && $configured !== ''
            ? $configured
            : base_path('resources/js/generated');
    }

    /** The out dir rendered relative to the app base path, for the info line. */
    private function relativeOutDir(): string
    {
        $dir = $this->outDir();
        $base = base_path().DIRECTORY_SEPARATOR;

        return str_starts_with($dir, $base) ? substr($dir, strlen($base)) : $dir;
    }

    /** The generated-code module specifier for the http clients (`api`/`adminApi`). */
    private function clientImport(): string
    {
        $v = config('beam.client.client_import');

        return is_string($v) && $v !== '' ? $v : '@/lib/api';
    }

    /** The generated-code module specifier for the route resolvers (`route`/`adminRoute`, `RouteMap`). */
    private function routesImport(): string
    {
        $v = config('beam.client.routes_import');

        return is_string($v) && $v !== '' ? $v : '@/lib/routes';
    }

    // ── disk helpers ───────────────────────────────────────────────────────────────────────────────

    /** Run the repo's Prettier over the generated tree; skip gracefully if it isn't installed. */
    private function prettier(string $dir): void
    {
        // Discover Prettier at the app root, or (platform npm-workspace layout) under `ui/`.
        $bin = collect([base_path('node_modules/.bin/prettier'), base_path('ui/node_modules/.bin/prettier')])
            ->first(fn ($path) => is_executable($path));

        if ($bin === null) {
            $this->components->warn('Prettier not found under node_modules — generated files left unformatted.');

            return;
        }

        $result = Process::path(base_path())->run([$bin, '--write', '--log-level=warn', $dir]);

        if (! $result->successful()) {
            $this->components->warn('Prettier exited non-zero on the generated tree: '.trim($result->errorOutput()));
        }
    }

    private function ensureDir(string $dir): void
    {
        if (! is_dir($dir)) {
            mkdir($dir, 0o755, recursive: true);
        }
    }

    /** Clear a generated subdirectory so removed routes don't leave orphaned files. */
    private function resetDir(string $dir): void
    {
        if (is_dir($dir)) {
            foreach (glob($dir.'/*.ts') ?: [] as $file) {
                unlink($file);
            }
        } else {
            mkdir($dir, 0o755, recursive: true);
        }
    }
}
