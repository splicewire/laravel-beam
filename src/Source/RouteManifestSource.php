<?php

namespace Splicewire\Beam\Source;

use Splicewire\Beam\Console\GenerateClientSdkCommand;

/**
 * The source-agnostic seam the client-SDK generator ({@see GenerateClientSdkCommand})
 * reads. A source yields the enriched route manifest — the SAME shape the platform's Tower
 * `Tenant`/`AdminRouteManifest` produced when the generator lived app-side — so the codegen never has to
 * know WHERE the routes came from (a Tower tier, or a satellite's mounted `#[ParticleResource]` routes).
 *
 * The manifest is a route-name → entry map:
 *
 *   [
 *     'library-lyrics.index'  => ['path' => 'resources/library-lyrics',      'methods' => ['GET'],  'visibility' => 'internal', 'returns' => 'App.Data.Library.LyricPieceProjectData', 'returnsMany' => true],
 *     'library-lyrics.store'  => ['path' => 'resources/library-lyrics',      'methods' => ['POST'], 'visibility' => 'internal', 'returns' => 'App.Data.Library.LyricPieceProjectData'],
 *     'library-lyrics.update' => ['path' => 'resources/library-lyrics/{id}', 'methods' => ['PATCH'], 'visibility' => 'internal'],
 *   ]
 *
 * Entry keys:
 *   - `path`        (required) the URI, `{param}`-templated, with NO leading slash — read off the live table.
 *   - `methods`     (required) the HTTP verbs (`['GET']`, `['POST']`, …).
 *   - `visibility`  (required) the route's exposure tier, `'public'` or `'internal'` (default when
 *                    undeclared). Orthogonal to `returns`/codegen — has no consumer in the generator itself;
 *                    exists for future public/private API-surface tooling to read.
 *   - `returns`     (optional) the response Data class as a TypeScript type path (`App.Data.X`); its
 *                    presence is what promotes an entry from a route-map-only line to a typed hook. Absent
 *                    ⇒ the entry gets a route-map entry only (its hook stays hand-written).
 *   - `returnsMany` (optional) true ⇒ the hook/store yields `App.Data.X[]` (a list/index endpoint).
 *
 * A host binds ONE source per realm into the generator (see `beam.client.sources`). A satellite has a
 * single tier, so the admin/second source is OPTIONAL — the generator emits an empty `adminDefaults` map
 * and no admin hooks when no admin source is bound.
 */
interface RouteManifestSource
{
    /**
     * @return array<string, array{path: string, methods: list<string>, visibility: string, returns?: string, returnsMany?: bool}>
     */
    public function toArray(): array;
}
