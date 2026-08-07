<?php

namespace Splicewire\Beam\Codegen;

use Illuminate\Support\Str;
use Rushing\Codegen\Contracts\Generator;
use Rushing\Popcorn\Binding;

/**
 * The TypeScript client generator (codegen-substrate #05), refactored onto the codegen seam: it is a
 * {@see Generator} (stack `ts-client`) that consumes the canonical model and returns a file map
 * (`routes.ts`, `hooks/<domain>.ts`, opt-in `stores/<domain>.ts`, `aliases.ts`). The emission strings
 * are ported VERBATIM from the former `GenerateClientSdkCommand` so the committed artifact stays
 * byte-identical — parity is guarded by `GenerateClientSdkCommandTest` and the committed
 * `ui/src/generated` tree.
 *
 * The command now owns only host concerns (resolve the model, write files under `out_dir`, run
 * Prettier). This generator is pure: model in → bytes out, no filesystem, no config reads (options
 * arrive via `input['options']`). That purity is what lets the same seam host a sandboxed
 * other-language generator (#06 Saloon, or a WIT-bindings toolchain) with no special-casing.
 */
class TsClientGenerator implements Generator
{
    /** @var array<string, mixed> */
    private array $options = [];

    public function name(): string
    {
        return 'ts-client';
    }

    public function stack(): string
    {
        return 'ts-client';
    }

    public function binding(): Binding
    {
        return Binding::Local;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function invoke(array $input): array
    {
        $this->options = $input['options'] ?? [];
        $operations = $input['model']['operations'] ?? [];

        [$tenantMap, $adminMap] = $this->realmMaps($operations);

        $files = [];
        $files['routes.ts'] = $this->renderRoutes($tenantMap, $adminMap);

        $typed = array_merge(
            $this->typedEntries($tenantMap, 'tenant'),
            $this->typedEntries($adminMap, 'operator'),
        );

        $streamed = array_merge(
            $this->streamEntries($tenantMap, 'tenant'),
            $this->streamEntries($adminMap, 'operator'),
        );

        $files = $this->emitHooks($files, $typed);
        $files = $this->emitStreamHooks($files, $streamed);

        if ((bool) ($this->options['emit_stores'] ?? false)) {
            $files = $this->emitStores($files, $typed);
        }

        [$files, $warnings] = $this->emitAliases($files, $typed);

        $stats = [
            'typed' => count($typed),
            'domains' => count(array_unique(array_column($typed, 'domainKey'))),
        ];

        return ['files' => $files, 'warnings' => $warnings, 'stats' => $stats];
    }

    /**
     * Project the model's operations back into the per-realm `{name => {path, methods, returns?,
     * returnsMany?}}` shape the original algorithm rendered from — so the rendering below runs
     * unchanged. Order is preserved from the operations list (tenant then admin, manifest order).
     *
     * @param  list<array<string, mixed>>  $operations
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, array<string, mixed>>}
     */
    private function realmMaps(array $operations): array
    {
        $tenant = [];
        $admin = [];

        foreach ($operations as $op) {
            $entry = [
                'path' => $op['path'],
                'methods' => $op['methods'] ?? [$op['method'] ?? 'GET'],
            ];

            if (isset($op['returns']['ref'])) {
                $entry['returns'] = $op['returns']['ref'];
            }

            if (! empty($op['returnsMany'])) {
                $entry['returnsMany'] = true;
            }

            if (isset($op['meta']['streams'])) {
                $entry['streams'] = $op['meta']['streams'];
            }

            if (($op['meta']['realm'] ?? 'tenant') === 'operator') {
                $admin[$op['name']] = $entry;
            } else {
                $tenant[$op['name']] = $entry;
            }
        }

        return [$tenant, $admin];
    }

    // ── config-driven bits (now from options) ─────────────────────────────────────────────────────

    private function clientImport(): string
    {
        $v = $this->options['client_import'] ?? null;

        return is_string($v) && $v !== '' ? $v : '@/lib/api';
    }

    private function routesImport(): string
    {
        $v = $this->options['routes_import'] ?? null;

        return is_string($v) && $v !== '' ? $v : '@/lib/routes';
    }

    // ── routes.ts (verbatim) ───────────────────────────────────────────────────────────────────────

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
            .$this->renderMap('operatorDefaults', 'the operator route-name map (empty when the host has no operator tier)', $admin);
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

    // ── typed entries (verbatim) ─────────────────────────────────────────────────────────────────

    /**
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

            $remainder = $realm === 'operator'
                ? preg_replace('/^api\.operator\./', '', $name)
                : preg_replace('/^api\.v1\./', '', $name);

            $segments = explode('.', $remainder);
            $action = array_pop($segments);
            $domainSlug = implode('-', $segments) ?: 'root';

            preg_match_all('/\{(\w+)\}/', $entry['path'], $paramMatches);

            $out[] = [
                'name' => $name,
                'realm' => $realm,
                'type' => $entry['returns'].(($entry['returnsMany'] ?? false) ? '[]' : ''),
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

    // ── stream entries (surgeon-audit-viability ticket 28) ──────────────────────────────────────────

    /**
     * @param  array<string, array{path: string, methods: list<string>, streams?: array<string, list<string>>}>  $map
     * @return list<array<string, mixed>>
     */
    private function streamEntries(array $map, string $realm): array
    {
        $out = [];

        foreach ($map as $name => $entry) {
            if (! isset($entry['streams'])) {
                continue;
            }

            $remainder = $realm === 'operator'
                ? preg_replace('/^api\.operator\./', '', $name)
                : preg_replace('/^api\.v1\./', '', $name);

            $segments = explode('.', $remainder);
            $action = array_pop($segments);
            $domainSlug = implode('-', $segments) ?: 'root';

            preg_match_all('/\{(\w+)\}/', $entry['path'], $paramMatches);

            $out[] = [
                'name' => $name,
                'realm' => $realm,
                'domainKey' => $domainSlug,
                'hookName' => 'use'.Str::studly($domainSlug).Str::studly($action).'Stream',
                'eventTypeName' => Str::studly($domainSlug).Str::studly($action).'StreamEvent',
                'params' => $paramMatches[1],
                'writeVerb' => strtolower((string) (array_values(array_intersect(
                    ['POST', 'PUT', 'PATCH', 'DELETE'],
                    $entry['methods'],
                ))[0] ?? 'post')),
                'streams' => $entry['streams'],
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $files
     * @param  list<array<string, mixed>>  $streamed
     * @return array<string, string>
     */
    private function emitStreamHooks(array $files, array $streamed): array
    {
        foreach ($this->byDomain($streamed) as $domainKey => $entries) {
            $realm = $entries[0]['realm'];
            [$client, $routeFn] = $this->realmBindings($realm);
            $key = 'hooks/'.$domainKey.'.ts';
            $appending = isset($files[$key]);

            // `client`/`routeFn` are already imported when appending to a file `emitHooks()` already
            // wrote for this domain — only `useSseStream` is new in that case.
            $body = $appending
                ? "import { useSseStream } from '@splicewire/beam-ux/streaming';\n\n"
                : "import { useSseStream } from '@splicewire/beam-ux/streaming';\n"
                    ."import { {$client} } from '{$this->clientImport()}';\n"
                    ."import { {$routeFn} } from '{$this->routesImport()}';\n\n";

            foreach ($entries as $entry) {
                $body .= $this->renderStreamType($entry)."\n";
                $body .= $this->renderStreamHook($entry, $client, $routeFn)."\n";
            }

            $files[$key] = $appending
                ? rtrim($files[$key])."\n\n".rtrim($body)."\n"
                : $this->banner().rtrim($body)."\n";
        }

        return $files;
    }

    /**
     * @param  array<string, mixed>  $e
     */
    private function renderStreamType(array $e): string
    {
        $variants = [];

        /** @var array<string, list<string>> $streams */
        $streams = $e['streams'];

        foreach ($streams as $eventName => $dtoRefs) {
            $dataType = implode(' | ', $dtoRefs);
            $variants[] = "    | { event: '{$eventName}'; data: {$dataType} }";
        }

        return "export type {$e['eventTypeName']} =\n".implode("\n", $variants).";\n";
    }

    /**
     * @param  array<string, mixed>  $e
     */
    private function renderStreamHook(array $e, string $client, string $routeFn): string
    {
        $hasParams = ! empty($e['params']);
        $sig = $hasParams ? 'params: Record<string, string | number>' : '';
        $routeCall = $hasParams ? "{$routeFn}('{$e['name']}', params)" : "{$routeFn}('{$e['name']}')";

        return "export function {$e['hookName']}({$sig}) {\n"
            ."    return useSseStream<{$e['eventTypeName']}>({$client}, {$routeCall}, { method: '{$e['writeVerb']}' });\n"
            ."}\n";
    }

    // ── hooks/<domain>.ts (verbatim rendering; emits to the file map) ──────────────────────────────

    /**
     * @param  array<string, string>  $files
     * @param  list<array<string, mixed>>  $typed
     * @return array<string, string>
     */
    private function emitHooks(array $files, array $typed): array
    {
        foreach ($this->byDomain($typed) as $domainKey => $entries) {
            $realm = $entries[0]['realm'];
            [$client, $routeFn] = $this->realmBindings($realm);
            $hasQuery = $this->hasQuery($entries);
            $hasMutation = $this->hasMutation($entries);

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

            $files['hooks/'.$domainKey.'.ts'] = rtrim($body)."\n";
        }

        return $files;
    }

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

    // ── stores/<domain>.ts (verbatim) ─────────────────────────────────────────────────────────────

    /**
     * @param  array<string, string>  $files
     * @param  list<array<string, mixed>>  $typed
     * @return array<string, string>
     */
    private function emitStores(array $files, array $typed): array
    {
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

            $files['stores/'.$domainKey.'.ts'] = rtrim($body)."\n";
        }

        return $files;
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

    // ── aliases.ts (verbatim; warnings returned instead of printed) ────────────────────────────────

    /**
     * @param  array<string, string>  $files
     * @param  list<array<string, mixed>>  $typed
     * @return array{0: array<string, string>, 1: string[]}
     */
    private function emitAliases(array $files, array $typed): array
    {
        $aliases = [];
        $warnings = [];

        foreach ($typed as $e) {
            $leaf = substr($e['returns'], strrpos($e['returns'], '.') + 1);

            if (isset($aliases[$leaf]) && $aliases[$leaf] !== $e['returns']) {
                $warnings[] = "aliases.ts: '{$leaf}' basename clash ({$aliases[$leaf]} vs {$e['returns']}); keeping the latter.";
            }

            $aliases[$leaf] = $e['returns'];
        }
        ksort($aliases);

        $body = $this->banner()
            ."// Friendly, non-namespaced re-exports of each return DTO (the `type X = App.Data.Y` bridges).\n\n";

        foreach ($aliases as $leaf => $type) {
            $body .= "export type {$leaf} = {$type};\n";
        }

        $files['aliases.ts'] = $body;

        return [$files, $warnings];
    }

    // ── shared helpers (verbatim) ─────────────────────────────────────────────────────────────────

    /** @return array{0: string, 1: string} */
    private function realmBindings(string $realm): array
    {
        return $realm === 'operator' ? ['operatorApi', 'operatorRoute'] : ['api', 'route'];
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
        return "// AUTO-GENERATED by `php artisan splicewire:beam:generate:client` — DO NOT EDIT BY HAND.\n"
            ."//\n"
            ."// Regenerate after any backend route change; the diff is the drift signal (client-sdk-codegen).\n"
            ."// Source of truth: the in-process route manifest (a RouteManifestSource).\n\n";
    }
}
