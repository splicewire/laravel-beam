# ADR-0245 — Config Sections describe a package's config, and one Override Ladder resolves it

**Status:** proposed — config-sections ticket 02, 2026-11-09. Gated by `cross-review` before ticket 05 builds on it.
**Date:** 2026-11-09
**Repo:** `splicewire/laravel-beam`
**Wayfinding:** `.scratch/splicewire/laravel-beam/config-sections/` (MAP, PRD, tickets 01 and 02), in `splicewire-ecosystem`.
**Builds on:** `docs/agents/particle-doctrine.md` (three declaration sites, no fourth), ADR-0212 (the `backing:` slot),
ADR-0235 (a registered schema types a member and is served beside it).
**Companions:** `splicewire/laravel-beam-ux` ADR-0225, ADR-0226, ADR-0227 (the Release, which a config override joins in
ticket 08); `tests/Config/*`.
**Placement:** repo-local. Promoted to the runbook's fleet tier once two family packages declare sections, per the
ecosystem's ADR placement rule.

## Context

An operator can't change most of how beam, tower and the flagship behave without a deploy. Settings live in
`config/*.php` arrays and env values, and the operator pages that show them are read-only by construction, each
hand-built with its own "set at deployment" badge. The overrides that do exist are scattered and have no schema, history
or rollback: five keys copied from `tenants.data` by stancl's `TenantConfig`, an extend-only model-catalog merge, a
`site_settings` table for brand, and a cache value that wins over config for synthetic reporting. Nothing tells an
operator which tier set the value they're looking at, or which env var to edit when a key really is deploy-time.

A package author has no typed description of their own config, so there is nothing to render a form from, validate an
override against, or enforce.

Ticket 01's prototype, built from config captured from the flagship (49 roots, 750 leaf keys, 348 `env()` bindings, only
15 of them set), measured four facts this decision rests on:

- **Keys contain dots.** `app.meters` is keyed `google.cse.query`, so a dot path cannot address a key.
- **`mergeConfigFrom()` is shallow.** A host's top-level copy of a key hides the package's whole value for it. In
  `beam.ux`, the host's `site` hides `/site/artifact_root`.
- **Providers write config.** `beam.ux /site/reserved_prefixes` reaches `config()` with a value neither file holds.
- **"Deploy-only" mostly means "the env var is unset".** The single most useful fact on a deploy-only key is its env
  var's name and whether it is set.

## Decision

### 1. A Config Section is a Data class per config root, and a registry holds them as vocabulary

`Splicewire\Beam\Config\ConfigSection` is an abstract Data class. `#[ConfigRoot(root:, title:, description:, entries:)]`
names the root it describes. Its properties are the root's keys, named exactly as the config file spells them, so the
schema, the pointers and the file use one spelling and no name mapper sits between them.

A root keyed by names a host chooses (`app.meters`, `app.entitlements`) is **map-shaped**. It names an `entries:` Data
class, every value is one instance of it, and the section declares no properties.

Per key, `#[ConfigKey]` declares the ladder behaviour (decisions 3 to 6). Title, description and control are the
data-schemas attributes every Data class already uses (`#[Title]`, `#[Description]`, `x-widget`), so they reach the
schema through the same generator.

`ConfigSectionRegistry` (`#[IsRegistry]`, root `beam.config.sections`) holds one entry per root: the section class, its
owner (a composer name, or `host`) and its package file. It holds **vocabulary only**. Values are read from `config()`
and the files when asked, and a per-tenant value never becomes an entry (the registry-kernel rule). A later registration
for a root supersedes, so a host can replace a package's section without forking it.

### 2. The Override Ladder is one fold, and every value reports its provenance

```
package default → host config/env → [provider] → operator override → tenant override
```

`ConfigLadder::fold()` is a pure function in the style of tower's `SourceWeights`: tiers in, effective values and
per-key provenance out. Keys are RFC 6901 **JSON pointers** relative to the root (`/google.cse.query/unit_cost_usd`).

- **Package** is the registered package file, required in isolation.
- **Host** is the host file. Its top-level keys are read from its **syntax tree**, never by executing it. Their values
  are taken from `config()`, because re-evaluating `env()` under `config:cache` no longer reads `.env`.
- **"Host shadows package"** is reported per key (`hostShadowsPackage`), and every package leaf a host's top-level key
  hides is listed in `shadowedPackageKeys`. This is the shallow merge, made visible.
- **Provider** is not a tier anyone writes. It names a value in `config()` that differs from what the two files fold to,
  so a key a service provider wrote at boot doesn't claim a file as its source. Two limits are stated, not hidden. A
  provider write over a key the host file also declares reads as the host. A package key fed by `env()` whose
  re-required value differs from `config()` is attributed to the env, not to a provider.
- **The effective value is always `config()`'s.** The fold is checked against it and never replaces it, so an
  operator is never shown a reconstruction.
- **Env bindings** come from the same syntax-tree walk: each `env('NAME', …)` call is mapped to the pointer of the
  array key whose value contains it. A key reports the binding of the tier that set it, plus whether the variable is set.
  A binding the walk cannot see can be named on the key (`#[ConfigKey(env: …)]`).

Ticket 02 folds package, host and provider. Operator and tenant join as sparse pointer layers when the override store
exists (ticket 05), with three ops: set, unset and explicit null. They reuse DataOverlay's `override` and `unset` ops
from `schemastud/laravel-data-schemas`, so ADR-0089's op set does not change.

### 3. Tiers and the ceiling

`ConfigTier` orders the ladder. A key's `ceiling` is the highest tier that may override it, declared by the package. An
operator may only **narrow** it (a lock, operator data) and never widen it, so what a tenant can change is governed by
code review. The default ceiling is `host`: not overridable at runtime.

### 4. Effect classes

`ConfigEffect` says when a change applies:
- `live`: the next request, job or command;
- `reload`: after long-lived workers reload;
- `deploy`: only a host file or env change.

The default is `deploy`. A key is declared `live` only deliberately, because promising a live change to a value boot
already read is exactly what the boot-read audit (ticket 06) exists to catch.

### 5. Merge strategies

`ConfigMerge` says how an override combines with what it covers:
- `replace`, the default;
- `deep`: maps merge recursively, and the override wins per key;
- `extend`: an override may add map entries and never replaces the package's own, which is the model-catalog merge
  made declarable.

`extend` is a core-side wrapper over the DataOverlay ops, not a new op.

### 6. Secrets are declared and never leave

`#[ConfigKey(secret: true)]` is the authority. Ticket 01's name heuristics over-redacted 3 of 12 and would miss any
secret not named like one, so the generator may only *suggest* the flag. A secret's value, and every layer value, is
`null` on the wire. The row says only `set: true|false`, with a timestamp once overrides exist. Secret values are never
written to a version, a mirror file or git. Stored overrides hold them in an encrypted column (ticket 05).

### 7. One schema per section, generated through the configured generator and committed

`ConfigSectionSchema` generates a section's JSON Schema through the host's configured data-schemas `Generator`, the one
`schemas:generate` runs. It adds the two things that generator cannot know: a map root's
`{type: object, additionalProperties: <entry>}`, and the `x-beam-config` keyword per key
(`{ceiling, effect, secret, merge, env?}`).

`splicewire:beam:config:schemas` writes `resources/schemas/config-sections/<root>.schema.json`. `--check` and a test both
compare without writing. A package's defaults validate against its section in a test, through the same `AcceptanceGate`
a particle write uses.

### 8. One resource, `beam-config`, serves every section through one reader

`#[ParticleResource(key: 'beam-config')]` is declared on `ConfigSectionData` in beam's own declaration root and was
scaffolded by `make:particle-resource`. Each row is one section:
- its identity, files and counts;
- its schema, served beside the values (ADR-0235: a row's value members are typed by its own section's schema);
- its effective values, secrets redacted;
- each leaf key with tier, layers, env binding and declaration.

The wire shape takes ticket 01's findings (`layers[]`, `env.set`, `env.file:line`, `shadowedPackageKeys`, `counts`, and
pointers as addresses). Its backing, `ConfigSectionBacking`, lists by stream and resolves by root, and it declines
`QueriesRecords` and `WritesRecords`. The resource is `readOnly`, `input: false`.

Every transport reads through **one** `ConfigSectionReader`:
- REST and Frame reach it through the backing;
- MCP reaches it through a read tool in `splicewire/laravel-beam-mcp`.

The read gate is the operator's existing ability, `entitlement:os.operate`. A row names env vars and install paths, so
no Public Read is declared. Realm membership is the host's (`frame.realms`), and the operator realm reads the central
tier. The tenant realm, which must never receive platform env names or vendor paths, arrives with the tenant tier
(ticket 07).

### 9. Overrides will live in a shared table, published to central and to every tenant schema

This placement is decided now and built in ticket 05. Overrides are sparse rows in one **shared** migration, published
to central and to every tenant schema the beam-ux way, rather than one central table keyed by tenant. The rows are:
section, pointer, op, value, encrypted value and revision, plus a per-section scope row carrying the draft and published
pins, versioned through `rushing/laravel-versioning`.

**The reason:** a Release's members must share its connection (`laravel-beam-ux` `ReleaseService`), so only
same-connection storage keeps a tenant's mixed Release (page, theme and config together) atomic. **The cost:** "which
tenants override key X" needs a fan-out query. A central projection is deferred until someone asks for it. Operator
locks are central data.

The database is the authority and a git mirror is a record (the owner's ruling, 2026-11-09). The runtime never reads an
override file. A committed file becomes a draft only through an explicit import, and the tenant tier keeps database
history only.

## Consequences

- Declaring a section changes no behaviour: every undeclared key is deploy-only and not overridable. On the first day,
  the Config area shows provenance read-only and names the env var to change, so the old dead ends become directions.
- A package author gets a schema, validation of their defaults, and a Config area row from one Data class.
- Sections for the flagship's `app.meters` and `app.entitlements` are the first two, declared by the host (ticket 02).
  Every beam package's sections follow (ticket 13), with the generator and two audits (ticket 06).
- `config:cache` keeps working. Provenance reads `config()` and syntax trees, never a re-executed host file.
- What would miss: a key whose value is assembled by a function call, or whose env var a provider reads directly, has no
  binding the walk can see, so the key declares it. A root whose host file doesn't return a literal array has no host
  keys, and the ladder reads it as package plus provider.
