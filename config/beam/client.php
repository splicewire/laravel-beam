<?php

use Splicewire\Beam\Source\ParticleRouteManifestSource;

return [

    /*
    |--------------------------------------------------------------------------
    | beam client SDK (client-sdk-codegen, promoted from the platform app)
    |--------------------------------------------------------------------------
    | `splicewire:beam:generate:client` reads the enriched route manifest and
    | emits the committed client access layer (route map + typed react-query
    | hooks + friendly aliases). This file config-drives the app-specific bits
    | so one generator serves a Tower platform AND a satellite alike.
    */

    /*
    | Where the generated SDK lands. Default: the satellite layout `resources/js/generated`.
    | The platform overrides this to its npm-workspace path (`ui/src/generated`).
    */
    'out_dir' => env('BEAM_CLIENT_OUT_DIR', resource_path('js/generated')),

    /*
    | Emit the opt-in per-resource zustand stores alongside the hooks. OFF by default —
    | zustand is opt-in (and a satellite may not depend on it). Hooks are always emitted.
    */
    'emit_stores' => (bool) env('BEAM_CLIENT_EMIT_STORES', false),

    /*
    | The module specifiers the generated code imports. `client_import` supplies the http
    | clients (`api`/`operatorApi`); `routes_import` supplies the resolvers + `RouteMap` type
    | (`route`/`operatorRoute`). A host builds these small runtimes; defaults match the `@/lib/*`
    | alias both the platform and the satellite use.
    */
    'client_import' => env('BEAM_CLIENT_CLIENT_IMPORT', '@/lib/api'),
    'routes_import' => env('BEAM_CLIENT_ROUTES_IMPORT', '@/lib/routes'),

    /*
    | The RouteManifestSource bound per realm. `defaults` is the primary (tenant) tier;
    | `operator` is OPTIONAL — a satellite has one tier, so leave it null and the generator
    | emits an empty `operatorDefaults` map + no operator hooks. Each value is a container-
    | resolvable class-string implementing Splicewire\Beam\Source\RouteManifestSource.
    |
    | The default `defaults` binding is the particle-route source: it reads the app's mounted
    | `Route::particleResource(...)` routes off the LIVE route table and derives each read
    | route's `returns` from the resource's output Data class. A Tower platform overrides this
    | with its own Tenant/Operator manifest source.
    |
    | NOTE (ADR-0156, 2026-08-07 addendum): BEAM_CLIENT_ADMIN_SOURCE renamed to
    | BEAM_CLIENT_OPERATOR_SOURCE — any deployed environment setting the old var must be updated.
    */
    'sources' => [
        'defaults' => env('BEAM_CLIENT_TENANT_SOURCE', ParticleRouteManifestSource::class),
        'operator' => env('BEAM_CLIENT_OPERATOR_SOURCE'),
    ],

    /*
    | The umbrella `splicewire:beam:generate:assets` pipeline, in dependency order (shapes →
    | schemas → the client that references both). A generator not registered in this host is
    | skipped with a note, never a hard failure — so a satellite without schemastud still runs.
    */
    'assets' => [
        'generators' => [
            'typescript:transform',
            'schemas:generate',
            'splicewire:beam:generate:client',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | SDK-hook migration surgeon audit (client-sdk-codegen #05 rollout)
    |--------------------------------------------------------------------------
    | `SdkHookMigrationAudit` finds hand-written react-query wrappers in a domain's `<ui>/features/*`
    | `api.ts` that a generated hook (under `out_dir`) already covers, and nominates retiring them. Lossless
    | TS/TSX parsing needs the JS toolchain (recast + babel), so the scan runs in Node via the
    | `@splicewire/beam-ux` package's `beam-ux-sdk-hook-migration` CLI (Symfony Process).
    |
    | `script` — absolute path to `bin/sdk-hook-migration.mjs` in the resolved `@splicewire/beam-ux`
    |            package. Null/unset ⇒ the audit is UNAVAILABLE (degrade-not-fabricate): it reports
    |            zero findings rather than fabricate drift.
    | `node`   — the node interpreter (resolved on PATH; default `node`).
    | `timeout`— the per-invocation process timeout, seconds.
    | `exclude_dirs` — directory names skipped entirely (both as a wrapper source and as an import
    |            site) — e.g. a domain mid-refactor in a concurrent diff.
    |
    | `sdk_package`/`sdk_namespace` — identify the generated Saloon SDK
    | ({@see \Splicewire\Beam\Codegen\SplicewireClientGenerator}'s output) that
    | `SdkEndpointDriftAudit`/`SdkNameConventionAudit` scan: the composer package name (resolved via its
    | `vendor/` install path — works for both a local path-repo checkout and a normal CI git clone) and the
    | PHP namespace its `Requests/` classes live under. Defaults match this repo's own `splicewire/laravel-connector`;
    | a different beam host generating its own Saloon SDK overrides both to its own package.
    */
    'surgeon' => [
        'sdk_hook_migration' => [
            'bridge' => [
                'script' => env('BEAM_CLIENT_SDK_HOOK_MIGRATION_SCRIPT', base_path('node_modules/@splicewire/beam-ux/bin/sdk-hook-migration.mjs')),
                'node' => env('BEAM_CLIENT_SDK_HOOK_MIGRATION_NODE', 'node'),
                'timeout' => (int) env('BEAM_CLIENT_SDK_HOOK_MIGRATION_TIMEOUT', 60),
            ],
            'exclude_dirs' => ['credits'],
        ],
        'sdk_package' => env('BEAM_CLIENT_SDK_PACKAGE', 'splicewire/laravel-connector'),
        'sdk_namespace' => env('BEAM_CLIENT_SDK_NAMESPACE', 'Splicewire\\Client\\Requests'),
    ],

];
