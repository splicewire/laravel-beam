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

## Check the library before building the mechanism

Before adding a mechanism to beam, check whether a primary dependency already ships it. This has
gone wrong three times — `afterResolving`, the reverse index, and `project:`, which turned out to be
a hand-written reimplementation of `spatie/laravel-data`'s magical creation and deleted **13 of the
estate's 20 `project:` closures to a no-op** (`particle-contribution-seam` ticket 12). Each time the
affordance was documented, shipped in `vendor/`, and invisible.

`docs/agents/libraries/` holds the orientation docs that fix that: what a library offers, and where
its real documentation already sits on disk. Read the one for the library you are about to build
alongside. Note that beam is a **package** — `advanced-usage/in-packages.md` applies, beam ships no
`config/data.php`, and `^4.0|^5.0` means its code must hold on two majors.

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

## Asking what the convergent guards would do, without publishing

`splicewire:beam:convergence-preflight` (beam-facade 146) is the **read-only** entry point to the
install's convergence phase. It rehearses two populations — the pending migrations the next `migrate`
would read, *and* the unpublished `.php.stub` files installed packages ship, which
`MigrationFiles::pathsFor()` cannot see by construction — and prints clean / would-change / conflicted
plus the `?` lines it could not rehearse. It writes nothing.

It is a wrapper and a renderer over `MigrationRehearsal`, `PackageStubs` and `RehearsalSafety`; a second
rehearsal implementation is the mistake ticket 109 already refused. ⚠️ It **reports, it does not gate** —
whether a host's live shape conflicts with a package's declaration is a fact about the host, so the exit
code is 0 even on conflicts and `--fail-on-conflict` is opt-in.

## What `beam:install` persists

The wizard's answers reach the running process through `config([...])` and reach **disk** only through
`persistConfig()`, behind three conditions: the config is published, it is writable, and `--force` was
passed. All three answers — prefix, schema sources, tenancy — are written; **`tenancy` was accepted and
silently dropped until 2026-08-27** (beam-facade 158), and nothing caught it because
`beam.core.tenancy` has exactly one consumer estate-wide and it is inside the command that sets it.

⚠️ Adding a key here means adding it to the **test fixture** too: `replaceScalar()` no-ops on an absent
key and returns the contents unchanged, so a fixture missing the key turns the assertion into a
tautology. See `docs/agents/install-answer-persistence.convention.md`.

## Gate or advisory

Beam reserves `gate: true` for *"an agent is building a thing wrong"* — two audits today, both in
`registerRegistryConformanceAudits()`, both registered **unconditionally** because the estate's one
gate used to sit behind `interface_exists()` on a `require-dev` interface and was therefore silently
absent from every production host. Everything else beam registers is advisory, on purpose.

**The rule and its home are not beam's** (same move as the convergent guard): the flag is
`Rushing\Doctor\DoctorRegistration::$gate`, so the convention lives in
`rushing/laravel-doctor/docs/agents/gate-or-advisory.convention.md`. Read it before reaching for
`gate: true`, before making a check throw at boot, and before letting anything that moves with
`APP_ENV` or `--no-dev` into a gate or a ratchet.

## Vendored family-package conventions

Any repo that vendors another family repo's code (composer `vendor/<vendor>/<pkg>/`, npm
`node_modules/<vendor>/<pkg>/`) checks that vendored repo's own `AGENTS.md` for conventions it
ships with itself before editing through into it.
