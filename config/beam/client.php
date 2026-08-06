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
    | clients (`api`/`adminApi`); `routes_import` supplies the resolvers + `RouteMap` type
    | (`route`/`adminRoute`). A host builds these small runtimes; defaults match the `@/lib/*`
    | alias both the platform and the satellite use.
    */
    'client_import' => env('BEAM_CLIENT_CLIENT_IMPORT', '@/lib/api'),
    'routes_import' => env('BEAM_CLIENT_ROUTES_IMPORT', '@/lib/routes'),

    /*
    | The RouteManifestSource bound per realm. `defaults` is the primary (tenant) tier;
    | `admin` is OPTIONAL — a satellite has one tier, so leave it null and the generator
    | emits an empty `adminDefaults` map + no admin hooks. Each value is a container-
    | resolvable class-string implementing Splicewire\Beam\Source\RouteManifestSource.
    |
    | The default `defaults` binding is the particle-route source: it reads the app's mounted
    | `Route::particleResource(...)` routes off the LIVE route table and derives each read
    | route's `returns` from the resource's output Data class. A Tower platform overrides this
    | with its own Tenant/Admin manifest source.
    */
    'sources' => [
        'defaults' => env('BEAM_CLIENT_TENANT_SOURCE', ParticleRouteManifestSource::class),
        'admin' => env('BEAM_CLIENT_ADMIN_SOURCE'),
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

];
