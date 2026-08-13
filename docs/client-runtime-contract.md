# The client runtime contract

> Owner: `splicewire/laravel-beam` (this package). Decided in particle-doctrine-followups #12 —
> before that the contract was unowned: the generator only *named* the two modules and no package or
> starter shipped them, which produced two divergent hand-written implementations and a generated
> file typed by a hand-written one.

`splicewire:beam:generate:client` emits code that imports **two host-owned runtime modules**, named by
config (`config/beam/client.php`):

| Config key | Default | What the generated code imports from it |
| --- | --- | --- |
| `beam.client.client_import` | `@/lib/api` | `api` — and `operatorApi` when an operator source is bound |
| `beam.client.routes_import` | `@/lib/routes` | `route` — and `operatorRoute` when an operator source is bound |

## Exact symbols and shapes

**`client_import`** must export:

- `api` — an object with `get(url)`, `post(url, body?)`, `put(url, body?)`, `patch(url, body?)`,
  `delete(url)` (delete is called **without** a body). Each returns a promise resolving to a response
  where **`res.data.data` is the payload** — the double-unwrap envelope every generated hook
  destructures (`return res.data.data as T`). Axios satisfies this natively for Laravel's `{ data }`
  envelope; a `fetch` implementation re-wraps the body once (see the published stub).
- `operatorApi` — same shape, **required only when `beam.client.sources.operator` is bound**.
- When any route declares `streams`, the client object is also passed by value to
  `useSseStream(api, url, { method })` from `@splicewire/beam-ux/streaming`, so it must be a valid
  first argument there.

**`routes_import`** must export:

- `route(name: string, params?: Record<string, string | number>): string` — resolves a generated
  route name to a request path, substituting `{param}` templates.
- `operatorRoute` — same shape, required only when the operator tier is bound.

**`RouteMap` is not part of the host contract.** The generated `routes.ts` declares and exports its
own `RouteMap` (`Record<string, string>` — the shape the generator actually emits). It used to be
imported from `routes_import`, which made the type flow backwards: a hand-written shape constrained
what the generator may emit, and the narrower of the two live implementations would have broken
silently had the generator emitted the wider form. A host runtime may still keep a **wider** entry
type for its own layers (the platform stores `{ path, methods }` entries for hydration); every wider
map accepts the generated narrow map as input, so the direction now scales.

## Ownership decision

Considered: a `@splicewire/beam-client-runtime` npm package; starter stubs stamped at install;
generator-owned type only. Decided: **the contract and its reference implementation live here**
(`stubs/client-runtime/*`, publishable via `vendor:publish --tag=beam-client-runtime`), the
**generator owns `RouteMap`**, and hosts own their runtime files. A shared npm runtime package was
rejected for now because the two live runtimes differ *legitimately* (operator tier, upsell
interceptor, host mirroring on the platform; deliberate minimalism on the satellite) — the contract
is the stable seam, the implementation is host character. The stubs are the satellite-shaped
reference; the beam starter ships them pre-published so a fresh host generates code that resolves.

## The guard

`Splicewire\Beam\Surgeon\ClientRuntimeContractAudit` (check `sdk.client-runtime-contract`, advisory,
registered into `BeamDoctorManifest` following the `laravel-beam-ux-prototype` precedent) statically
verifies both modules exist under the host's `@/` alias root (derived as the parent of
`beam.client.out_dir`) and export the required symbols for the host's bound tiers. It checks
presence and export names only; it deliberately does not report axios-vs-fetch or other legitimate
per-host divergence, and anything needing a bundler would ride the existing `SdkHookMigrationBridge`
Node-bin seam rather than being faked in PHP.
