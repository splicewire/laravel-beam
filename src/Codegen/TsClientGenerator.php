<?php

namespace Splicewire\Beam\Codegen;

use Illuminate\Support\Str;
use LogicException;
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

        // Both emitters share the same domain/file namespace; a stream may collide with a typed route too.
        $entries = $this->disambiguateRealmDomains($this->disambiguateTransportDomains([...$typed, ...$streamed]));
        $typedCount = count($typed);
        $typed = array_slice($entries, 0, $typedCount);
        $streamed = array_slice($entries, $typedCount);
        $this->assertDistinctClientNames($typed, $streamed);

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

            if (! empty($op['meta']['returnsBody'])) {
                $entry['returnsBody'] = true;
            }

            if (isset($op['meta']['streams'])) {
                $entry['streams'] = $op['meta']['streams'];
            }

            if (array_key_exists('input', $op['meta'] ?? [])) {
                $entry['input'] = $op['meta']['input'];
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

    private function typeReference(string $reference, bool $nested = true): string
    {
        $resolved = $this->options['type_references'][$reference] ?? null;
        if ($resolved === null) {
            return $reference;
        }
        if (! isset($resolved['module'])) {
            return $resolved['name'];
        }

        $module = $resolved['module'];
        if ($nested) {
            $module = '../'.(str_starts_with($module, './') ? substr($module, 2) : $module);
        }

        return 'import('.json_encode($module, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).').'.$resolved['name'];
    }

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
            .$this->renderRouteMapType()
            ."\n"
            .$this->renderMap('defaults', 'the tenant route-name map', $tenant)
            ."\n"
            .$this->renderMap('operatorDefaults', 'the operator route-name map (empty when the host has no operator tier)', $admin);
    }

    /**
     * The generator OWNS the `RouteMap` type it annotates its own output with (client runtime contract,
     * particle-doctrine-followups #12). It used to be imported from the host's hand-written
     * `routes_import` module — a backwards dependency where a hand-written shape constrained what this
     * generator may emit. The emitted maps are always bare name → path-template strings; a host runtime
     * may store WIDER entries in its own layers (the platform's `{ path, methods }` hydration form),
     * since every widened map accepts this narrow shape as input.
     */
    private function renderRouteMapType(): string
    {
        return "/** AUTO-GENERATED — the entry shape this generator emits: route name → path template. */\n"
            ."export type RouteMap = Record<string, string>;\n";
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
     * Realm-qualify a domain key ONLY where the two realms actually collide on it.
     *
     * `typedEntries()` strips the realm prefix — correct for the route NAME, which is realm-qualified
     * already. But the domain key is what `byDomain()` groups by and what every emitted identifier is
     * built from, so an `api.operator.<x>.index` and a tenant `<x>.index` reduce to ONE key, land in
     * ONE file, and emit each state type, store and hook TWICE. That is a hard `tsc` failure
     * (`Cannot redeclare block-scoped variable`), and it took down the whole SPA build.
     *
     * Measured on the live route table: 8 remainders collide — `hooks.{index,show,events,deliveries,
     * op.reset}`, `bills.index`, `tenants.index`, `routes.manifest`. `hooks` broke first only because
     * it is the first with a typed PARAMLESS read in both realms (only those become stores), so
     * `bills` and `tenants` sat one such route from the same break.
     *
     * The duplicate identifiers were the loud half. The quiet half is worse: `emitStores()`/`emitHooks()`
     * take their client binding from the FIRST entry's realm, so the operator entries in a merged file
     * were wired to the TENANT api/route — a wrong endpoint that compiles cleanly. De-duplicating by
     * name alone would have silenced the error and kept that.
     *
     * ⚠️ **Scoped to actual collisions on purpose.** Prefixing every non-tenant domain unconditionally
     * also renames operator-only domains that nothing collides with — which breaks hand-written imports
     * of the generated modules (measured: `@/generated/hooks/registry` at
     * `ui/src/features/operator/OperatorRegistryPage.tsx`). A generated module path is a consumed
     * contract; rename only what is ambiguous.
     *
     * ⚠️ **Corrected 2026-09-03 — `frame.collapse_operator_realm` DOES NOT EXIST.** An earlier version of
     * this docblock said this de-duplication was "deliberately NOT keyed off" that knob, describing it as a
     * shipped default-OFF config key. It was a spike that never landed: it was reverted uncommitted
     * (realm-and-floor-reconciliation ticket 00), `git log -S'configDrivenRealms'` is empty, and this
     * comment — committed at 09b8a9a, 2026-08-26 — was the only trace of it left in HEAD.
     *
     * The reasoning it stood on is unchanged and is worth keeping: a runtime collapse of one realm
     * through another would not make two distinct realms one namespace in a generated client, so this
     * de-duplication would not key off such a knob even if one existed. The only shipped sibling is
     * `frame.collapse_user_realm` (`Realm/RealmRegistry.php:320`), and it is not consulted here either,
     * for the same reason. If a collapsed host should ever emit one merged domain, that is a deliberate
     * change with its own test — against a knob that would first have to be built.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    private function disambiguateRealmDomains(array $entries): array
    {
        $realmsPerDomain = [];
        foreach ($entries as $entry) {
            $realmsPerDomain[$entry['domainKey']][$entry['realm']] = true;
        }

        foreach ($entries as $i => $entry) {
            $realm = (string) $entry['realm'];
            if ($realm !== 'tenant' && count($realmsPerDomain[$entry['domainKey']]) > 1) {
                $entries[$i] = $this->withDomain($entry, $realm.'-'.$entry['domainKey']);
            }
        }

        return $entries;
    }

    /**
     * Keep an existing short WEB name; qualify an API family only when generated names collide in its realm/domain.
     * An API-only host (including its committed imports) therefore emits exactly the previous names.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    private function disambiguateTransportDomains(array $entries): array
    {
        $names = [];
        $collisions = [];
        foreach ($entries as $entry) {
            $realm = $entry['realm'];
            $domain = $entry['domainKey'];
            foreach ($this->clientIdentifiers($entry) as $name) {
                $names[$realm][$domain][$name][$this->transportPrefix($entry)] = true;
                $families = $names[$realm][$domain][$name];
                if (isset($families['']) && count($families) > 1) {
                    $collisions[$realm][$domain] = true;
                }
            }
        }
        foreach ($entries as $i => $entry) {
            $prefix = $this->transportPrefix($entry);
            if ($prefix !== '' && isset($collisions[$entry['realm']][$entry['domainKey']])) {
                $entries[$i] = $this->withDomain($entry, $prefix.'-'.$entry['domainKey']);
            }
        }

        return $entries;
    }

    /** @param array<string, mixed> $entry */
    private function transportPrefix(array $entry): string
    {
        $prefix = $entry['realm'] === 'operator' ? 'api.operator.' : 'api.v1.';

        return str_starts_with($entry['name'], $prefix) ? str_replace('.', '-', rtrim($prefix, '.')) : '';
    }

    /** @param array<string, mixed> $entry */
    private function withDomain(array $entry, string $domain): array
    {
        $segments = explode('.', $entry['name']);
        $stem = Str::studly($domain).Str::studly(array_pop($segments));
        $entry['domainKey'] = $domain;
        $entry['hookName'] = 'use'.$stem.(isset($entry['streams']) ? 'Stream' : '');
        if (isset($entry['streams'])) {
            $entry['eventTypeName'] = $stem.'StreamEvent';
        }

        return $entry;
    }

    /**
     * Refuse secondary collisions rather than merge two client bindings or silently overwrite a file.
     * Reservations span all route-derived hooks, Vars, stores, state and stream types, not just one file.
     * DTO aliases retain their existing separately documented basename-warning policy.
     *
     * @param  list<array<string, mixed>>  $typed
     * @param  list<array<string, mixed>>  $streamed
     */
    private function assertDistinctClientNames(array $typed, array $streamed): void
    {
        $files = [];
        $identifiers = [];
        $reserve = function (string $name, array $entry) use (&$identifiers): void {
            if (isset($identifiers[$name])) {
                throw new LogicException("Client identifier [{$name}] collides between routes [{$identifiers[$name]}] and [{$entry['name']}].");
            }
            $identifiers[$name] = $entry['name'];
        };
        foreach ([...$typed, ...$streamed] as $entry) {
            $family = [$entry['realm'], $entry['originalDomainKey']];
            $file = 'hooks/'.$entry['domainKey'].'.ts';
            if (isset($files[$file]) && $files[$file]['family'] !== $family) {
                throw new LogicException("Client file [{$file}] collides between routes [{$files[$file]['route']}] and [{$entry['name']}].");
            }
            $files[$file] = ['family' => $family, 'route' => $entry['name']];
            foreach ($this->clientIdentifiers($entry) as $identifier) {
                $reserve($identifier, $entry);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return list<string>
     */
    private function clientIdentifiers(array $entry): array
    {
        $names = [$entry['hookName']];
        if (isset($entry['streams'])) {
            $names[] = $entry['eventTypeName'];
        } elseif ($entry['isWrite']) {
            if ($entry['params'] !== [] || ($entry['writeVerb'] !== 'delete' && $entry['input'] !== false)) {
                $names[] = substr($entry['hookName'], 3).'Vars';
            }
        } elseif ((bool) ($this->options['emit_stores'] ?? false) && $entry['params'] === []) {
            $names[] = $entry['hookName'].'Store';
            $names[] = Str::studly(substr($entry['hookName'], 3)).'State';
        }

        return $names;
    }

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
                'type' => $this->typeReference($entry['returns']).(($entry['returnsMany'] ?? false) ? '[]' : ''),
                'returns' => $entry['returns'],
                'responseExpression' => ($entry['returnsBody'] ?? false) ? 'res.data' : 'res.data.data',
                'domainKey' => $domainSlug,
                'originalDomainKey' => $domainSlug,
                'hookName' => 'use'.Str::studly($domainSlug).Str::studly($action),
                'params' => $paramMatches[1],
                // tutorial-dx 03: a TS type path, false (no body) or null (undeclared: the body stays unknown).
                'input' => $entry['input'] ?? null,
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
                'originalDomainKey' => $domainSlug,
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
            $dataType = implode(' | ', array_map(fn (string $ref) => $this->typeReference($ref), $dtoRefs));
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
        $sig = $hasParams ? 'params: '.$this->paramsType($e['params']) : '';
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

            // Types in their own top-level `import type` (a host's eslint `import/consistent-type-specifier-style:
            // prefer-top-level` refuses `type` specifiers inline in a value import; calcucrypt MAP-24).
            $values = array_filter([$hasMutation ? 'useMutation' : null, $hasQuery ? 'useQuery' : null]);
            $types = array_filter([$hasMutation ? 'UseMutationOptions' : null, $hasQuery ? 'UseQueryOptions' : null]);

            $body = $this->banner()
                .'import { '.implode(', ', $values)." } from '@tanstack/react-query';\n"
                .'import type { '.implode(', ', $types)." } from '@tanstack/react-query';\n"
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
            // tutorial-dx 03: each mutation names its own variables type (see renderHook), so the options carry it.
            $out .= "type MutationOpts<T, V> = Omit<Partial<UseMutationOptions<T, Error, V>>, 'mutationFn'>;\n";
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
            $params = $hasParams ? 'params: '.$this->paramsType($e['params']).', ' : '';
            $sig = "{$params}options?: QueryOpts<{$type}>";
            $key = $hasParams ? "['{$e['name']}', params]" : "['{$e['name']}']";
            $routeCall = $hasParams ? "{$routeFn}('{$e['name']}', params)" : "{$routeFn}('{$e['name']}')";

            return "export function {$e['hookName']}({$sig}) {\n"
                ."    return useQuery({\n"
                ."        queryKey: {$key},\n"
                ."        queryFn: async () => {\n"
                ."            const res = await {$client}.get({$routeCall});\n"
                ."\n"
                ."            return {$e['responseExpression']} as {$type};\n"
                ."        },\n"
                ."        ...options,\n"
                ."    });\n"
                ."}\n";
        }

        // tutorial-dx 03: the mutation's OWN variables — the path's parameters by name (required when the path has
        // any), and a body typed from the route's declared input: a Data class types it, `false` sends none, an
        // undeclared input stays `unknown`. A DELETE sends no body. Nothing to send at all: the variables are `void`.
        $fields = [];
        if ($hasParams) {
            $fields[] = 'params: '.$this->paramsType($e['params']);
        }
        $input = $e['input'] ?? null;
        $sendsBody = $e['writeVerb'] !== 'delete' && $input !== false;
        if ($sendsBody) {
            $fields[] = is_string($input) ? 'body: '.$this->typeReference($input) : 'body?: unknown';
        }
        $varsType = 'void';
        $alias = '';
        if ($fields !== []) {
            $varsType = substr((string) $e['hookName'], 3).'Vars';
            $alias = "export type {$varsType} = { ".implode('; ', $fields)." };\n";
        }

        $routeCall = $hasParams ? "{$routeFn}('{$e['name']}', vars.params)" : "{$routeFn}('{$e['name']}')";
        $call = $sendsBody
            ? "{$client}.{$e['writeVerb']}({$routeCall}, vars.body)"
            : "{$client}.{$e['writeVerb']}({$routeCall})";
        $varsParam = $fields !== [] ? "vars: {$varsType}" : '';

        return $alias
            ."export function {$e['hookName']}(options?: MutationOpts<{$type}, {$varsType}>) {\n"
            ."    return useMutation({\n"
            ."        mutationFn: async ({$varsParam}) => {\n"
            ."            const res = await {$call};\n"
            ."\n"
            ."            return {$e['responseExpression']} as {$type};\n"
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
            ."\n"
            ."        try {\n"
            ."            const res = await {$client}.get({$routeFn}('{$e['name']}'));\n"
            ."            set({ data: {$e['responseExpression']} as {$type}, loading: false });\n"
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
            $type = $this->typeReference($type, nested: false);
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

    /**
     * A path's parameters as a named record (tutorial-dx 03): `{post}/{comment}` → `{ post: string | number; comment:
     * string | number }`, so a misspelt or missing parameter fails the caller's compile, not the request.
     *
     * @param  list<string>  $params
     */
    private function paramsType(array $params): string
    {
        return '{ '.implode('; ', array_map(fn (string $p) => "{$p}: string | number", array_values(array_unique($params)))).' }';
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
