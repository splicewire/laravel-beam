> You are in **splicewire/laravel-beam** — the schema-driven-CMS core: a schema-typed, snapshot-versioned, migrate-on-read runtime substrate for beam satellites.

Composes the open schema foundation (`schemastud/laravel-data-schemas` + `schemastud/laravel-frame`)
and the open versioning foundation (`rushing/laravel-versioning`) into `BeamParticle`. Also the
runtime home of the host-hook registries (webhook / sitemap / doctor), the realm registry, and the
particle resource declarations. Headless-capable: no editor SURFACE is required to run.

## Particle doctrine

Before adding or changing any I/O surface (HTTP route, MCP tool, Inertia page, command), read
`docs/agents/particle-doctrine.md` — the
declare-every-boundary-crossing-shape invariant, its three declaration sites, the four exceptions,
and `popcorn:registries --json` for locating the registry behind a surface.

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
Hand-editing a published timestamp is never the fix — but re-dating *is*, once the install causes it:
against a third-party migration we cannot re-guard, `splicewire:beam:install` asks who owns each
colliding table (default beam, `--own-tables` to script it) and re-dates beam's published copy one tick
below the competitor. See `docs/agents/migration-publish-ordering.convention.md`.

## Convergent migration guards

A published `create_*` migration declares the shape it needs, not the table it creates. Guard it with
`Rushing\SchemaConvergence\ConvergentTable` — create when absent, add missing columns when a copy already
exists, throw on a column that exists with the wrong type — never a bare `Schema::hasTable($t) → return`,
which hands the table to whichever migration sorts first and reports success to the loser.

**The guard and its convention live in `rushing/laravel-schema-convergence`, not here** (beam-facade
tickets 34/35). It left beam because the rule is wider than beam: five `rushing/*` packages publish
`create_*` stubs into the same hosts while carrying no beam dependency, so a guard homed here was one
they could not import. Beam requires the package like anyone else. The rule, the tiers, both terminals,
and what convergence cannot do are all in
`rushing/laravel-schema-convergence/docs/agents/convergent-migration-guards.convention.md`.

What stays in beam is the OTHER half of the collision family — `docs/agents/migration-publish-ordering.convention.md`,
above — because the installer that spends its filename-order lever is beam's.

## The dedupe keyword

`x-beam-dedupe: { by: ['email'], mode: 'admit' }` is beam-core's first owned keyword — the one place
"how a capture behaves when its key matches an earlier one" is declared. All three modes are
LEDGER-side (`admit` marks the repeat, `ignore` drops it, `reject` refuses it); `update` and
`version` are deliberately not legal values. Two rules a reader must not have to re-derive:
**precedence is authorize → validate → dedupe → persist**, so a dedupe verdict never preempts a gate;
and **`ignore` must stay byte-identical to a fresh capture**, because otherwise a public door is an
email-existence oracle — read the written model off `ParticleWriter::write()`'s RETURN VALUE, never
off the instance you passed in. `reject` is an oracle by construction and is legitimate only behind
an authenticated door. The key recipe is write-once. See
`docs/agents/dedupe-keyword.convention.md`.

## Vendored family-package conventions

Any repo that vendors another family repo's code (composer `vendor/<vendor>/<pkg>/`, npm
`node_modules/<vendor>/<pkg>/`) checks that vendored repo's own `AGENTS.md` for conventions it
ships with itself before editing through into it.
