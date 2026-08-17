> You are in **splicewire/laravel-beam** — the schema-driven-CMS core: a schema-typed, snapshot-versioned, migrate-on-read runtime substrate for beam satellites.

Composes the open schema foundation (`schemastud/laravel-data-schemas` + `schemastud/laravel-frame`)
and the open versioning foundation (`rushing/laravel-versioning`) into `BeamParticle`. Also the
runtime home of the host-hook registries (webhook / sitemap / doctor), the realm registry, and the
particle resource declarations. Headless-capable: no editor SURFACE is required to run.

## Particle doctrine

Before adding or changing any I/O surface (HTTP route, MCP tool, Inertia page, command), read
`docs/agents/particle-doctrine.md` — the
declare-every-boundary-crossing-shape invariant, its three declaration sites, the four exceptions,
and `splicewire:beam:manifests --json` for locating the registry behind a surface.

## API parameter documentation

A documented API parameter is declared on a Data class — `#[RequestFromData]` / `#[QueryFromData]` plus
`#[Description]` on the properties — never in a `@bodyParam` / `@queryParam` docblock, and path
parameters are derived from the route's particle stamp rather than annotated. See
`docs/agents/api-parameter-documentation.convention.md`.

## Migration publish ordering

Publishing stamps a migration at publish time, so the order packages install in IS the order their
migrations run in on a greenfield install. A package shipping an ALTER against a table ANOTHER
package creates must register a higher `$order` on its `BeamInstallManifest` step than the package
that creates it — two packages on the default `100` are separated only by provider boot order.
Hand-editing a published timestamp is never the fix. See
`docs/agents/migration-publish-ordering.convention.md`.

## Vendored family-package conventions

Any repo that vendors another family repo's code (composer `vendor/<vendor>/<pkg>/`, npm
`node_modules/<vendor>/<pkg>/`) checks that vendored repo's own `AGENTS.md` for conventions it
ships with itself before editing through into it.
