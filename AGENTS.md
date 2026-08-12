> You are in **splicewire/laravel-beam** — the schema-driven-CMS core: a schema-typed, snapshot-versioned, migrate-on-read runtime substrate for beam satellites.

Composes the open schema foundation (`schemastud/laravel-data-schemas` + `schemastud/laravel-frame`)
and the open versioning foundation (`rushing/laravel-versioning`) into `BeamParticle`. Also the
runtime home of the host-hook registries (webhook / sitemap / doctor), the realm registry, and the
particle resource declarations. Headless-capable: no editor SURFACE is required to run.

## Particle doctrine

Before adding or changing any I/O surface (HTTP route, MCP tool, Inertia page, command), read
`~/Workspaces/splicewire-beam-runbook/references/particle-doctrine.md` — the
declare-every-boundary-crossing-shape invariant, its three declaration sites, the four exceptions,
and `splicewire:beam:manifests --json` for locating the registry behind a surface.

## Vendored family-package conventions

Any repo that vendors another family repo's code (composer `vendor/<vendor>/<pkg>/`, npm
`node_modules/<vendor>/<pkg>/`) checks that vendored repo's own `AGENTS.md` for conventions it
ships with itself before editing through into it.
