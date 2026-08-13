// Beam client runtime — the `routes_import` half of the generated client's runtime contract
// (`beam.client.routes_import`, default '@/lib/routes'). Published by
// `php artisan vendor:publish --tag=beam-client-runtime`; after publishing this file is YOURS —
// the generator imports it but never writes it. Contract reference:
// splicewire/laravel-beam docs/client-runtime-contract.md.
//
// This is the one-tier (satellite) reference implementation: generated defaults only. A host with
// an operator tier additionally exports `operatorRoute` over `operatorDefaults`; a host that
// hydrates or overrides routes at runtime layers that on top (the platform host is the precedent).
// `RouteMap` is exported BY the generated file — the generator owns that type, not this module.

import { defaults } from '@/generated/routes';

export function route(name: string, params: Record<string, string | number> = {}): string {
    const template = defaults[name];

    if (template === undefined) {
        throw new Error(
            `Unknown route name '${name}' — regenerate the client SDK (php artisan splicewire:beam:generate:client).`,
        );
    }

    let path = template;

    for (const [key, value] of Object.entries(params)) {
        path = path.replace(`{${key}}`, String(value));
    }

    // A missing param stays templated on purpose — the failure is visible in the request line
    // rather than silently mangled.
    return path.startsWith('/') ? path : `/${path}`;
}
