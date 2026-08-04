<?php

namespace Splicewire\Beam\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Splicewire\Beam\Source\ParticleRouteManifestSource;
use Splicewire\Beam\Source\RouteManifestSource;

/**
 * The client-SDK codegen (client-sdk-codegen PRD, PROMOTED into laravel-beam from the platform app). The
 * *second* generation dimension beside Spatie's `typescript:transform`: where that emits shapes
 * (`generated.d.ts`), this reads the enriched route manifest and emits the client *access layer*:
 *
 *   - `routes.ts`      — the `defaults`/`adminDefaults` route-name maps.
 *   - `hooks/<domain>` — a typed @tanstack/react-query hook per return-typed entry.
 *   - `stores/<domain>`— an opt-in per-resource zustand store over the same typed fetch (config-gated OFF).
 *   - `aliases.ts`     — the `type X = App.Data.Y` bridges.
 *
 * SOURCE-AGNOSTIC (the promotion's whole point). The generator no longer knows Tower: it reads
 * {@see RouteManifestSource} instances resolved from `beam.client.sources.{defaults,admin}`. The platform
 * binds a Tower-backed source (its `Tenant`/`AdminRouteManifest`); a satellite binds the package's
 * {@see ParticleRouteManifestSource} (which reads the app's mounted particle
 * routes). The admin/second tier is OPTIONAL — a satellite has one tier, so an unbound admin source yields
 * an empty `adminDefaults` map and no admin hooks.
 *
 * Both sources resolve in-process (no HTTP) off the live route table, so the committed output IS the
 * backend's mounted truth — route drift becomes a reviewable build/CI diff, not a runtime-only surprise.
 * Every generated file carries an AUTO-GENERATED banner and is git-committed like `generated.d.ts`.
 *
 * A route surfaces `returns` (its response Data class) via the source (e.g. the particle source derives it
 * from the resource's output DTO). Entries with no `returns` get a route-map entry only — their hooks stay
 * hand-written.
 */
class GenerateClientSdkCommand extends Command
{
    protected $signature = 'splicewire:beam:generate-client';

    protected $description = 'Generate the committed client SDK (route map + typed access layer) from the route manifest';

    public function __construct(private readonly Container $container)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dir = $this->outDir();
        $this->ensureDir($dir);

        $tenantMap = $this->source('defaults')?->toArray() ?? [];
        $adminMap = $this->source('admin')?->toArray() ?? [];

        file_put_contents($dir.'/routes.ts', $this->renderRoutes($tenantMap, $adminMap));

        // The typed access layer, over every entry that surfaced a `returns` type.
        $typed = array_merge(
            $this->typedEntries($tenantMap, 'tenant'),
            $this->typedEntries($adminMap, 'admin'),
        );

        $this->emitHooks($dir, $typed);

        if ((bool) config('beam.client.emit_stores', false)) {
            $this->emitStores($dir, $typed);
        }

        $this->emitAliases($dir, $typed);

        // Canonicalise to the repo's Prettier so the committed artifact is byte-identical to a manual
        // `prettier --write` — a `generate`-then-check CI guard stays honest without a format step in the
        // pipeline. Replicating Prettier's line-wrap + quote-props rules in PHP would be brittle.
        $this->prettier($dir);

        $domains = count(array_unique(array_column($typed, 'domainKey')));
        $this->components->info(sprintf(
            'Wrote %s: routes.ts + %d typed hook(s) across %d domain(s).',
            $this->relativeOutDir(),
            count($typed),
            $domains,
        ));

        return self::SUCCESS;
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

    // ── routes.ts ────────────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, array{path: string, methods: list<string>}>  $tenant
     * @param  array<string, array{path: string, methods: list<string>}>  $admin
     */
    private function renderRoutes(array $tenant, array $admin): string
    {
        return $this->banner()
            ."import type { RouteMap } from '{$this->routesImport()}';\n\n"
            .$this->renderMap('defaults', 'the tenant route-name map', $tenant)
            ."\n"
            .$this->renderMap('adminDefaults', 'the admin route-name map (empty when the host has no admin tier)', $admin);
    }

    /**
     * @param  array<string, array{path: string, methods: list<string>}>  $map
     */
    private function renderMap(string $name, string $doc, array $map): string
    {
        $lines = "/** AUTO-GENERATED — {$doc}. */\nexport const {$name}: RouteMap = {\n";

        foreach ($map as $routeName => $entry) {
            $lines .= '    '.$this->tsKey($routeName).": '{$entry['path']}',\n";
        }

        return $lines."};\n";
    }

    // ── typed entries ────────────────────────────────────────────────────────────────────────────

    /**
     * Reduce a manifest to the return-typed entries, deriving each one's client-facing shape (domain
     * bucket, hook name, HTTP verb, path params).
     *
     * @param  array<string, array{path: string, methods: list<string>, returns?: string, returnsMany?: bool}>  $map
     * @return list<array<string, mixed>>
     */
    private function typedEntries(array $map, string $realm): array
    {
        $out = [];

        foreach ($map as $name => $entry) {
            if (! isset($entry['returns'])) {
                continue;
            }

            // Drop a realm prefix so the domain/action read cleanly (`api.admin.registry.index` →
            // `registry.index`; `api.v1.splice.compositions.index` → `splice.compositions.index`). A
            // satellite's particle names (`library-lyrics.index`) carry no prefix and pass through.
            $remainder = $realm === 'admin'
                ? preg_replace('/^api\.admin\./', '', $name)
                : preg_replace('/^api\.v1\./', '', $name);

            $segments = explode('.', $remainder);
            $action = array_pop($segments);
            $domainSlug = implode('-', $segments) ?: 'root';

            preg_match_all('/\{(\w+)\}/', $entry['path'], $paramMatches);

            $out[] = [
                'name' => $name,
                'realm' => $realm,
                // The value type a hook/store yields: `App.Data.X[]` for a list endpoint, else `App.Data.X`.
                'type' => $entry['returns'].(($entry['returnsMany'] ?? false) ? '[]' : ''),
                // The bare DTO (no `[]`) — the friendly alias re-exports this.
                'returns' => $entry['returns'],
                'domainKey' => $domainSlug,
                'hookName' => 'use'.Str::studly($domainSlug).Str::studly($action),
                'params' => $paramMatches[1],
                'isWrite' => (bool) array_intersect($entry['methods'], ['POST', 'PUT', 'PATCH', 'DELETE']),
                'writeVerb' => strtolower((string) (array_values(array_intersect(
                    ['POST', 'PUT', 'PATCH', 'DELETE'],
                    $entry['methods'],
                ))[0] ?? 'post')),
            ];
        }

        return $out;
    }

    // ── hooks/<domain>.ts ─────────────────────────────────────────────────────────────────────────

    /**
     * @param  list<array<string, mixed>>  $typed
     */
    private function emitHooks(string $dir, array $typed): void
    {
        $this->resetDir($dir.'/hooks');

        foreach ($this->byDomain($typed) as $domainKey => $entries) {
            $realm = $entries[0]['realm'];
            [$client, $routeFn] = $this->realmBindings($realm);
            $hasQuery = $this->hasQuery($entries);
            $hasMutation = $this->hasMutation($entries);

            // Import the hook fns + their option types (only the ones this domain uses).
            $named = array_filter([
                $hasMutation ? 'useMutation' : null,
                $hasQuery ? 'useQuery' : null,
                $hasQuery ? 'type UseQueryOptions' : null,
                $hasMutation ? 'type UseMutationOptions' : null,
            ]);

            $body = $this->banner()
                .'import { '.implode(', ', $named)." } from '@tanstack/react-query';\n"
                ."import { {$client} } from '{$this->clientImport()}';\n"
                ."import { {$routeFn} } from '{$this->routesImport()}';\n\n"
                .$this->optionTypes($hasQuery, $hasMutation);

            foreach ($entries as $entry) {
                $body .= $this->renderHook($entry, $client, $routeFn)."\n";
            }

            file_put_contents($dir.'/hooks/'.$domainKey.'.ts', rtrim($body)."\n");
        }
    }

    /**
     * The per-file option-passthrough type aliases: caller-supplied React Query options minus the fields
     * the generated hook owns (queryKey/queryFn/mutationFn), so a migrating call site keeps its options.
     */
    private function optionTypes(bool $hasQuery, bool $hasMutation): string
    {
        $out = '';

        if ($hasQuery) {
            $out .= "type QueryOpts<T> = Omit<Partial<UseQueryOptions<T, Error, T>>, 'queryKey' | 'queryFn'>;\n";
        }

        if ($hasMutation) {
            $out .= "type MutationVars = { params?: Record<string, string | number>; body?: unknown };\n"
                ."type MutationOpts<T> = Omit<Partial<UseMutationOptions<T, Error, MutationVars>>, 'mutationFn'>;\n";
        }

        return $out === '' ? '' : $out."\n";
    }

    /**
     * @param  array<string, mixed>  $e
     */
    private function renderHook(array $e, string $client, string $routeFn): string
    {
        $type = $e['type'];
        $hasParams = ! empty($e['params']);

        if (! $e['isWrite']) {
            // `options` passes through to useQuery (staleTime, enabled, placeholderData, refetch…) AFTER
            // queryKey/queryFn, so a call site keeps its behaviour when it migrates — the generated hook is
            // a faithful drop-in, not a lossy one. queryKey/queryFn stay non-overridable defaults.
            $params = $hasParams ? 'params: Record<string, string | number>, ' : '';
            $sig = "{$params}options?: QueryOpts<{$type}>";
            $key = $hasParams ? "['{$e['name']}', params]" : "['{$e['name']}']";
            $routeCall = $hasParams ? "{$routeFn}('{$e['name']}', params)" : "{$routeFn}('{$e['name']}')";

            return "export function {$e['hookName']}({$sig}) {\n"
                ."    return useQuery({\n"
                ."        queryKey: {$key},\n"
                ."        queryFn: async () => {\n"
                ."            const res = await {$client}.get({$routeCall});\n"
                ."            return res.data.data as {$type};\n"
                ."        },\n"
                ."        ...options,\n"
                ."    });\n"
                ."}\n";
        }

        // Mutation: params (URL) + body (payload) both optional, carried on the mutate vars. `options`
        // passes through to useMutation (onSuccess for invalidation, onError, retry…) after mutationFn.
        $routeCall = $hasParams ? "{$routeFn}('{$e['name']}', vars.params ?? {})" : "{$routeFn}('{$e['name']}')";
        $call = $e['writeVerb'] === 'delete'
            ? "{$client}.delete({$routeCall})"
            : "{$client}.{$e['writeVerb']}({$routeCall}, vars.body)";

        return "export function {$e['hookName']}(options?: MutationOpts<{$type}>) {\n"
            ."    return useMutation({\n"
            ."        mutationFn: async (vars: MutationVars) => {\n"
            ."            const res = await {$call};\n"
            ."            return res.data.data as {$type};\n"
            ."        },\n"
            ."        ...options,\n"
            ."    });\n"
            ."}\n";
    }

    // ── stores/<domain>.ts (opt-in — zustand) ─────────────────────────────────────────────────────

    /**
     * Opt-in per-resource zustand store over the same typed fetch — DISABLED by default
     * (`beam.client.emit_stores`). Only read (GET) entries get a store; writes stay hook-only.
     *
     * @param  list<array<string, mixed>>  $typed
     */
    private function emitStores(string $dir, array $typed): void
    {
        $this->resetDir($dir.'/stores');

        foreach ($this->byDomain($typed) as $domainKey => $entries) {
            $reads = array_values(array_filter($entries, fn ($e) => ! $e['isWrite'] && empty($e['params'])));

            if (empty($reads)) {
                continue;
            }

            $realm = $reads[0]['realm'];
            [$client, $routeFn] = $this->realmBindings($realm);

            $body = $this->banner()
                ."import { create } from 'zustand';\n"
                ."import { {$client} } from '{$this->clientImport()}';\n"
                ."import { {$routeFn} } from '{$this->routesImport()}';\n\n";

            foreach ($reads as $entry) {
                $body .= $this->renderStore($entry, $client, $routeFn)."\n";
            }

            file_put_contents($dir.'/stores/'.$domainKey.'.ts', rtrim($body)."\n");
        }
    }

    /**
     * @param  array<string, mixed>  $e
     */
    private function renderStore(array $e, string $client, string $routeFn): string
    {
        $type = $e['type'];
        $storeName = $e['hookName'].'Store';
        $stateType = Str::studly(preg_replace('/^use/', '', $e['hookName'])).'State';

        return "type {$stateType} = {\n"
            ."    data: {$type} | null;\n"
            ."    loading: boolean;\n"
            ."    error: unknown;\n"
            ."    fetch: () => Promise<void>;\n"
            ."};\n\n"
            ."export const {$storeName} = create<{$stateType}>((set) => ({\n"
            ."    data: null,\n"
            ."    loading: false,\n"
            ."    error: null,\n"
            ."    fetch: async () => {\n"
            ."        set({ loading: true, error: null });\n"
            ."        try {\n"
            ."            const res = await {$client}.get({$routeFn}('{$e['name']}'));\n"
            ."            set({ data: res.data.data as {$type}, loading: false });\n"
            ."        } catch (error) {\n"
            ."            set({ error, loading: false });\n"
            ."        }\n"
            ."    },\n"
            ."}));\n";
    }

    // ── aliases.ts ───────────────────────────────────────────────────────────────────────────────

    /**
     * @param  list<array<string, mixed>>  $typed
     */
    private function emitAliases(string $dir, array $typed): void
    {
        $aliases = [];
        foreach ($typed as $e) {
            $leaf = substr($e['returns'], strrpos($e['returns'], '.') + 1);

            // The alias is keyed by the DTO's basename. Two return types that share a basename but sit in
            // different `App.Data.*` sub-namespaces would collide on one `export type <leaf>`. Warn rather
            // than silently keep the last — a swallowed name clash is exactly the codegen bug that hides.
            if (isset($aliases[$leaf]) && $aliases[$leaf] !== $e['returns']) {
                $this->components->warn(
                    "aliases.ts: '{$leaf}' basename clash ({$aliases[$leaf]} vs {$e['returns']}); keeping the latter."
                );
            }

            $aliases[$leaf] = $e['returns'];
        }
        ksort($aliases);

        $body = $this->banner()
            ."// Friendly, non-namespaced re-exports of each return DTO (the `type X = App.Data.Y` bridges).\n\n";

        foreach ($aliases as $leaf => $type) {
            $body .= "export type {$leaf} = {$type};\n";
        }

        file_put_contents($dir.'/aliases.ts', $body);
    }

    // ── shared helpers ───────────────────────────────────────────────────────────────────────────

    /** @return array{0: string, 1: string} [client, routeFn] for the realm. */
    private function realmBindings(string $realm): array
    {
        return $realm === 'admin' ? ['adminApi', 'adminRoute'] : ['api', 'route'];
    }

    /**
     * @param  list<array<string, mixed>>  $typed
     * @return array<string, list<array<string, mixed>>>
     */
    private function byDomain(array $typed): array
    {
        $grouped = [];
        foreach ($typed as $e) {
            $grouped[$e['domainKey']][] = $e;
        }

        return $grouped;
    }

    /** @param list<array<string, mixed>> $entries */
    private function hasQuery(array $entries): bool
    {
        return (bool) array_filter($entries, fn ($e) => ! $e['isWrite']);
    }

    /** @param list<array<string, mixed>> $entries */
    private function hasMutation(array $entries): bool
    {
        return (bool) array_filter($entries, fn ($e) => $e['isWrite']);
    }

    private function tsKey(string $key): string
    {
        return "'".str_replace("'", "\\'", $key)."'";
    }

    private function banner(): string
    {
        return "// AUTO-GENERATED by `php artisan splicewire:beam:generate-client` — DO NOT EDIT BY HAND.\n"
            ."//\n"
            ."// Regenerate after any backend route change; the diff is the drift signal (client-sdk-codegen).\n"
            ."// Source of truth: the in-process route manifest (a RouteManifestSource).\n\n";
    }

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
