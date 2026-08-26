<?php

/*
|--------------------------------------------------------------------------
| Scribe — published by splicewire/laravel-beam (`vendor:publish --tag=beam-scribe`)
|--------------------------------------------------------------------------
|
| Beam's out-of-the-box Scribe configuration (ADR-0211 §7). It is a STUB, not a merged package config:
| once published this file is yours, and beam will never overwrite it.
|
| A beam host documents ITSELF. The spec generated from this config describes the host's own routes —
| not a copy of some upstream spec — which is what makes a fresh `laravel-beam-starter` boot with a live,
| truthful API reference at `/docs/api`.
|
| Three things here are load-bearing and are the reason this file ships at all:
|
|  1. `type => 'laravel'` + `laravel.add_routes => false`. Scribe is an EMITTER here, never a UI
|     (ADR-0028). Beam serves the artifact itself at `beam/openapi.yaml` / `beam/openapi.json` and
|     renders it with Scalar through `<ApiReference>`. Flipping either value grows a second, unbranded
|     docs UI at a URL beam does not own — and collides with the entry renderer's catch-all (ADR-0209).
|     `beam:doctor` reports it if you do; it does not stop you.
|  2. `routes.match.prefixes`, DERIVED from where this host's sockets actually are. Because the artifact
|     route is PUBLIC by default, these match rules ARE the exposure boundary for this site. Widen
|     deliberately, and narrow deliberately too.
|
|     This used to read `['api/*']` and nothing else, which was true of the host it was written from and
|     false of a fresh install: a bare beam host mounts NO route under `api/*`. Frame's CRUD socket sits
|     at `frame/*`, the entry-body transport at `beam/ux/*`, and a starter has no `routes/api.php` at all
|     — so a "self-documenting" host generated a spec with zero paths and served it proudly (ADR-0211 §7,
|     amended). The list below therefore derives from `frame.route_prefix` and `beam.ux.api_root`, the
|     same keys that position those routes, so it cannot describe a layout this host does not have.
|
|     What stays out is a judgement, not an oversight: the intake door (`beam/intake/*`), webhook
|     receivers, and beam's own `beam/openapi.*`. What is now IN includes auth-gated operator sockets —
|     documenting a gated endpoint is not exposing it, but if this host would rather publish nothing but
|     its public read surface, cut the derived entries and say so here.
|  3. The `Splicewire\Beam\Scribe\*` strategies and generators. Without them a generated spec is bare
|     paths — no groups, no titles, no request/response schemas — which is not worth pointing a
|     reference at.
|
| What is deliberately NOT here, because it is host-specific and a package cannot know it:
|
|  - `openapi.overrides.servers`. Set it if your API lives somewhere other than `app.url` (a tenant
|    subdomain, say). Use a CONCRETE host: a `{tenant}` template is a trap — Scalar cannot resolve it and
|    falls back to the page origin, which tenancy middleware then rejects.
|  - The group taxonomy. It comes from `GroupRegistry`, which beam ships EMPTY: your groups derive from
|    your own declared particle resources. Seeding one estate's ontology into every host is the exact
|    mistake the retired `config/api-groups.php` warned about in its own header.
|
| One wrinkle worth knowing about `type => 'laravel'`: Scribe still WRITES a Blade view to
| resources/views/scribe/ and assets to public/vendor/scribe/ on every generate, even with `add_routes`
| false. Nothing routes to them, so nothing serves them — they are inert output, not a second docs UI.
| Add both to .gitignore if the churn bothers you.
|
| Only the most common settings are shown. Full reference: https://scribe.knuckles.wtf/laravel/reference/config
*/

use Knuckles\Scribe\Config\AuthIn;
use Knuckles\Scribe\Config\Defaults;
use Knuckles\Scribe\Extracting\Strategies;
use Knuckles\Scribe\Extracting\Strategies\Responses\ResponseCalls;
use Rushing\LaravelDataSchemasScribe\OpenApi\DataSchemaGenerator;
use Rushing\LaravelDataSchemasScribe\Strategies\UseDataRequest;
use Rushing\LaravelDataSchemasScribe\Strategies\UseDataResponse;
use Rushing\LaravelDataSchemasScribe\Strategies\UseDataStream;
use Splicewire\Beam\Scribe\OpenApi\TagHierarchyGenerator;
use Splicewire\Beam\Scribe\Strategies\GroupStrategy;
use Splicewire\Beam\Scribe\Strategies\ParticleRequestStrategy;
use Splicewire\Beam\Scribe\Strategies\ParticleResponseStrategy;
use Splicewire\Beam\Scribe\Strategies\ParticleTitleStrategy;
use Splicewire\Beam\Scribe\Strategies\ReturnsResponseStrategy;
use Splicewire\Beam\Scribe\Strategies\RouteTitleStrategy;
use Splicewire\Beam\Scribe\Strategies\UrlParametersWithoutRowReads;

use function Knuckles\Scribe\Config\removeStrategies;

return [
    // The HTML <title> for the generated documentation.
    'title' => config('app.name').' API',

    // A short description of your API. Included in the OpenAPI spec.
    'description' => '',

    // Text placed in the "Introduction" section. Markdown and HTML are supported.
    'intro_text' => '',

    // The base URL displayed in the docs.
    'base_url' => config('app.url'),

    /*
     * WHICH ROUTES REACH THE SPEC — see note 2 in the header. This is the exposure boundary, not a
     * convenience filter.
     */
    'routes' => [
        [
            'match' => [
                // DERIVED, not a literal list — see note 2. `api/*` is the conventional host API prefix;
                // the other two are where beam's own JSON sockets actually are on THIS host, read from the
                // same config keys that position them. A host that re-prefixes its sockets (say, to
                // `api/frame`) is covered by `api/*` and the duplicate falls out in the unique below; a
                // host that leaves the defaults is covered by the derived entries. Either way the boundary
                // tracks the routes instead of describing a host somebody else wrote.
                //
                // Still unpublished, deliberately: `beam/intake/*` (the intake door), webhook receivers,
                // and beam's own `beam/openapi.*` — an OpenAPI document is not an API resource and cannot
                // appear in the document it serves, which is why both routes are in
                // `UndeclaredSurfaceAudit::DEFAULT_EXEMPT_URIS`.
                // Note the `?:` rather than a `config()` default. A key that is PRESENT AND NULL — a host
                // that published the config and blanked the value — skips the default argument entirely,
                // and `trim(null).'/*'` is `/*`, a prefix matching EVERY route on the site. An exposure
                // boundary whose degenerate case is "publish everything" is worse than no boundary, so
                // the fallback is applied to the trimmed value, not to the lookup.
                'prefixes' => array_values(array_unique([
                    'api/*',
                    (trim((string) config('frame.route_prefix'), '/') ?: 'frame').'/*',
                    (trim((string) config('beam.ux.api_root'), '/') ?: 'beam/ux').'/*',
                ])),

                'domains' => ['*'],
            ],

            // Include these routes even if they did not match the rules above.
            'include' => [
                // 'users.index', 'POST /new', '/auth/*'
            ],

            // Exclude these routes even if they matched the rules above.
            'exclude' => [
                // 'GET /health', 'admin.*'
            ],
        ],
    ],

    /*
     * `laravel` writes the artifact to storage/app/scribe/openapi.yaml — the path
     * `beam.core.openapi.artifact` defaults to. See note 1: this pair is the no-HTML guarantee.
     */
    'type' => 'laravel',

    'theme' => 'default',

    'static' => [
        'output_path' => 'public/docs',
    ],

    'laravel' => [
        // FALSE deliberately. Beam owns the docs URLs; Scribe owns the artifact and nothing else.
        'add_routes' => false,

        'docs_url' => '/docs',

        'assets_directory' => null,

        'middleware' => [],
    ],

    'external' => [
        'html_attributes' => [],
    ],

    'try_it_out' => [
        'enabled' => true,

        // The base URL the API tester calls. Null ⇒ same as the displayed URL.
        'base_url' => null,

        // [Laravel Sanctum] Fetch a CSRF token before each request, as X-XSRF-TOKEN.
        'use_csrf' => false,

        'csrf_url' => '/sanctum/csrf-cookie',
    ],

    // How is your API authenticated? Used in the displayed docs and generated examples.
    'auth' => [
        'enabled' => false,

        'default' => false,

        'in' => AuthIn::BEARER->value,

        'name' => 'key',

        // Never included in the generated documentation.
        'use_value' => env('SCRIBE_AUTH_KEY'),

        'placeholder' => '{YOUR_AUTH_KEY}',

        'extra_info' => '',
    ],

    'example_languages' => [
        'bash',
        'javascript',
    ],

    /*
     * OFF. The Postman collection is out of scope for beam's docs surface: nothing in the stack consumes
     * it, and an OTB default emitting an artifact nobody reads widens the contract for free. Turn it on
     * if you have a consumer.
     */
    'postman' => [
        'enabled' => false,

        'overrides' => [
            // 'info.version' => '2.0.0',
        ],
    ],

    /*
     * ON — this is the artifact beam serves. Written to storage/app/scribe/openapi.yaml.
     */
    'openapi' => [
        'enabled' => true,

        /*
         * 3.1, deliberately, and NOT merely a taste preference: DataSchemaGenerator below stamps
         * `openapi: 3.1.0` on the assembled document regardless, because hoisting Data-class $defs into
         * components/schemas needs 3.1's JSON Schema compatibility. Leaving Scribe's 3.0.3 here would
         * make it emit 3.0-shaped fragments into a document declaring 3.1 — the two must agree.
         */
        'version' => '3.1.0',

        'overrides' => [
            // 'servers' => [['url' => 'https://api.example.com', 'description' => 'Production']],
        ],

        /*
         * Document-assembly hooks. The schema generator hoists Data-class $defs into
         * components/schemas and points each operation's request/response at the real $ref (ADR-0028);
         * the tag-group generator projects the declared group tree into `x-tagGroups`, which is what
         * gives the reference its second sidebar level.
         */
        'generators' => [
            DataSchemaGenerator::class,
            TagHierarchyGenerator::class,
        ],
    ],

    'groups' => [
        // Endpoints with no resolved group land here.
        'default' => 'Endpoints',

        // Ordering. The tree itself comes from GroupRegistry, not from this key.
        'order' => [],
    ],

    'logo' => false,

    'last_updated' => 'Last updated: {date:F j, Y}',

    'examples' => [
        // Any number here makes example values reproducible across runs.
        'faker_seed' => 1234,

        'models_source' => ['factoryCreate', 'factoryMake', 'databaseFirst'],
    ],

    /*
     * The extraction strategies. Order is load-bearing in two slots — see the comments below.
     */
    'strategies' => [
        'metadata' => [
            ...Defaults::METADATA_STRATEGIES,

            // Title + description for DISSOLVED particle routes, read off the declaration itself
            // (resource key/label, op name/kind). A generated handler has no docblock for Scribe to
            // summarize, so without this the sidebar shows a bare path. Defers to an explicit docblock.
            ParticleTitleStrategy::class,

            // LAST-RESORT titles for hand-written thin controllers with no docblock summary: derived
            // from what the route already declares — route name first
            // (`api.saved-filters.apply` → "Apply Saved Filter"), URI + method otherwise. Never
            // overwrites a title that is already present.
            RouteTitleStrategy::class,

            // The host's declared taxonomy. Registered LAST and AUTHORITATIVE over a docblock `@group`:
            // grouping is a host presentation concern, and a `@group` written into a package controller
            // encodes one host's taxonomy into code that ships to every host. It also supplies each
            // group's description, which Scribe otherwise scavenges from whichever member endpoint
            // happens to carry prose. Only its last-resort guess defers to a real `@group`.
            GroupStrategy::class,
        ],

        // Spelled out rather than spread, because ONE member of the default list is replaced.
        // `Defaults::URL_PARAMETERS_STRATEGIES` leads with Scribe's `GetFromLaravelAPI`, which ends
        // its Eloquent inference with `$modelInstance::first()->{$routeKey}` — a live row read during
        // generation, whose result is published as that parameter's example. The artifact is served
        // unauthenticated at `GET beam/openapi.yaml`, so on any host that generates against a
        // populated database that is a real row's key on the public internet. The replacement is the
        // same strategy with that one line removed; type inference is unchanged.
        // (api-surface-coherence ticket 62.)
        'urlParameters' => [
            UrlParametersWithoutRowReads::class,
            Strategies\UrlParameters\GetFromUrlParamAttribute::class,
            Strategies\UrlParameters\GetFromUrlParamTag::class,
        ],

        'queryParameters' => [
            ...Defaults::QUERY_PARAMETERS_STRATEGIES,
        ],

        'headers' => [
            ...Defaults::HEADERS_STRATEGIES,
            Strategies\StaticData::withSettings(data: [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ]),
        ],

        'bodyParameters' => [
            ...Defaults::BODY_PARAMETERS_STRATEGIES,

            // #[RequestFromData] → request schema.
            UseDataRequest::class,

            // Dissolved generic-controller routes carry no attribute; the schema signal is the route's
            // `_particle` default naming a ParticleResource. Stashes on the same custom key.
            ParticleRequestStrategy::class,
        ],

        'responses' => [
            /*
             * ResponseCalls is REMOVED. Response schemas come from the Data-class strategies below, and
             * live calls against authenticated (or tenant-subdomain) routes only ever produce error-page
             * examples — and drive Scribe to rewrite model-named URL params like {post} → {post_id}.
             */
            ...removeStrategies(Defaults::RESPONSES_STRATEGIES, [ResponseCalls::class]),

            UseDataResponse::class,

            // Streams reach the spec: reads #[StreamsFromData] and emits the text/event-stream entry
            // with its x-sse-events payload union.
            UseDataStream::class,

            // The `->returns()` → OpenAPI-response bridge. Registered AHEAD of the particle strategy so
            // an explicit `->returns()` wins, mirroring how RouteReturnType resolves.
            ReturnsResponseStrategy::class,

            // Same route-driven signal as ParticleRequestStrategy: index → paginated list envelope,
            // show/store/update → single-item envelope, off the resource's declared read Data class.
            ParticleResponseStrategy::class,
        ],

        'responseFields' => [
            ...Defaults::RESPONSE_FIELDS_STRATEGIES,
        ],
    ],

    'database_connections_to_transact' => [config('database.default')],

    'fractal' => [
        'serializer' => null,
    ],
];
