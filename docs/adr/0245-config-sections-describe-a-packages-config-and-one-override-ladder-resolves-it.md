# ADR-0245 — Config Sections describe a package's config, and one Override Ladder resolves it

**Status:** accepted (cross-review met 2026-11-09; root under the owner's standing all-recs ruling, 2026-11-15)
**Date:** 2026-11-09
**Repo:** `splicewire/laravel-beam`
**Wayfinding:** `.scratch/splicewire/laravel-beam/config-sections/` (MAP, PRD, tickets 01 and 02), in `splicewire-ecosystem`.
**Builds on:** `docs/agents/particle-doctrine.md` (three declaration sites, no fourth), ADR-0212 (the `backing:` slot),
ADR-0235 (a registered schema types a member and is served beside it).
**Companions:** the Release, which a config override joins in ticket 08:
`splicewire/laravel-beam-ux docs/adr/0225-an-entry-has-one-history-a-save-is-a-draft-and-publish-moves-the-pin.md`,
`splicewire/laravel-beam-ux docs/adr/0226-a-release-publishes-entries-together-and-git-records-what-was-published.md`,
`splicewire/laravel-beam-ux docs/adr/0227-a-tenant-owned-entry-holds-blocks-not-code-and-starts-as-a-pack-copy.md`;
the overlay format the operator and tenant tiers apply through,
`splicewire/splicewire-app docs/adr/0089-dataoverlay-context-invoked-overlays-one-format-two-appliers.md`; and
`tests/Config/*`.
**Cross-review:** codex, 2026-11-09, REVISE at `185e44a`; amended once (decision 2's op contract, the env `set`
state under `config:cache`, cross-repo citations, and §Open questions for ticket 05).
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
- **"Set" has three states.** `true` means the running process sees the variable. `false` means config is not cached
  and the variable is absent, so the key runs on its file default. `null` means unknown: under `config:cache` Laravel
  no longer loads `.env` (`Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables` returns early), so a variable
  only `.env` held is invisible although the cached config was built with it. Absence then proves nothing, and the
  row never claims "file default".

Ticket 02 folds package, host and provider. Operator and tenant join as sparse pointer layers when the override store
exists (ticket 05), with three ops. **These are config ops, not DataOverlay ops**, and the difference matters for
`unset`:

- **`set`** writes a value at a pointer in that tier's layer. It wins over every tier below.
- **explicit `null`** is a `set` whose value is null: a **retained null override**. The tier still has an entry at that
  pointer, the effective value is null, and the provenance names the tier. It is never a deletion, so "no value" and
  "inherit" stay different things (PRD story 11).
- **`unset`** means **inherit**. It removes that tier's override row for the pointer, and the ladder refolds the
  tiers below, so the key reads the next lower tier's value again (ticket 05: "Unset returns to the host value"). It
  never deletes the inherited effective value. DataOverlay's own `unset` is a node-delete
  (`splicewire/splicewire-app docs/adr/0089-dataoverlay-context-invoked-overlays-one-format-two-appliers.md`;
  `schemastud/laravel-data-schemas` `src/Overlay/JsonPathMutator.php`), so a config `unset` is never sent to
  DataOverlay at all. DataOverlay's semantics are unchanged.
- **The document an override tier modifies** is that tier's own sparse set for the section: the operator's central
  rows, or one tenant's rows in its own schema. A tier's layer is built from its published rows. Each `set`/`null` is
  applied, in pointer order, to the effective document of the tiers below it through DataOverlay's `override`, which
  upserts a concrete path. An `unset` row is simply absent from the set.
- **Pointers stay public; JSONPath is internal.** The pointer is the address on the wire, in override rows and in the
  ladder. It becomes a DataOverlay JSONPath in exactly one place, `JsonPointer::toJsonPath()`, when a tier's layer is
  applied. Every segment is bracket-quoted (`/google.cse.query/unit_cost_usd` → `$['google.cse.query']['unit_cost_usd']`),
  so a dotted key stays one segment. DataOverlay's concrete-target parser has no escape for a quote or `]` inside a
  quoted segment, so a key holding either is refused by name rather than turned into a path naming another node.
  `tests/Config/JsonPointerOverlayBoundaryTest.php` runs this against DataOverlay's real mutator: a dotted-key set, a
  retained null, and a create-absent.
- **`extend`** (decision 5) is applied by core before the layer reaches DataOverlay, as a filter on which map entries the
  tier may add. It is not a new DataOverlay op, so DataOverlay's op set (`splicewire/splicewire-app docs/adr/0089-dataoverlay-context-invoked-overlays-one-format-two-appliers.md`) does not change.

### 3. Tiers and the ceiling

The ladder's vocabulary is three backed enums in `Splicewire\Beam\Enums` (`ConfigTier`, `ConfigEffect`, `ConfigMerge`),
beside beam's other enums, because a host's TypeScript tree reflects a package enum only from a scanned `src/Enums`
directory; declared in `src/Config` they emitted as `undefined` on every `beam-config` row.

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
- `extend`: an override may add map entries and never replaces one already present in a tier below it, which is the
  model-catalog merge made declarable. A colliding write is refused when its draft is saved (§Open questions, 2).

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

**The reason:** a Release's members must share its connection (`splicewire/laravel-beam-ux` `src/Release/ReleaseService.php`), so only
same-connection storage keeps a tenant's mixed Release (page, theme and config together) atomic. **The cost:** "which
tenants override key X" needs a fan-out query. A central projection is deferred until someone asks for it. Operator
locks are central data.

The database is the authority and a git mirror is a record (the owner's ruling, 2026-11-09). The runtime never reads an
override file. A committed file becomes a draft only through an explicit import, and the tenant tier keeps database
history only.

## Open questions for ticket 05

Raised by the cross-review. Two are decided here because ticket 05 builds on them, and one stays open for ticket 08.

1. **Which document does `unset` modify before the ladder refolds?** *Decided (decision 2).* That tier's own sparse
   set: the operator's central rows, or one tenant's rows in its schema. `unset` removes the row for the pointer.
   Nothing in a lower tier, and no effective document, is edited.
2. **Under `extend`, what happens when a tenant's entry collides with an entry the operator added?** *Decided.*
   `extend` protects every entry already present below the writing tier, whichever tier put it there (package, host
   or operator). A tenant may add new map keys and may not replace any existing one. A colliding tenant write is
   refused when the draft is saved, with the pointer and the tier that owns the entry, and is never silently
   dropped at fold time. An operator collides with package and host entries the same way.
3. **When a secret changes after it joined a Release, what preserves the Release's selected secret?** *Open, for
   ticket 08; proposed here.* Each secret write creates a new override revision that holds the encrypted value. A
   Release member and a version snapshot carry that revision's id, plus `set` and `setAt`, never the value, so a
   later write cannot change what the Release publishes or rolls back to. Whether the revision row is the store's
   own or `rushing/laravel-versioning`'s is ticket 05's to settle with this constraint.

   *Settled by ticket 05: the revision row is the store's own.* A `rushing/laravel-versioning` version's snapshot is
   plain JSON, and decision 6 rules that a secret value is never written to a version, so the encrypted value cannot
   live in one. `beam_config_overrides` is the store's append-only revision log: one row per write to one pointer
   (`set`, an explicit `null` or `unset`), with `encrypted_value` for a secret and `value` otherwise, never updated or
   deleted. The per-section scope row (`beam_config_scopes`) holds the draft as pointer → revision id, and it is the
   `Versionable` record: its HEAD pin is the last saved draft and `published_version` the version the runtime applies.
   A version snapshot carries each pointer's revision id with `set` and `setAt`, and a value only for a non-secret
   `set`. Ticket 08's Release member names a revision id the same way.

## Amendment, 2026-11-20 (config-sections 09): two extension points on the tenant tier

`ConfigTenantOverlay` gains two seams, both **opt-in and inert**: with no contributor registered and nothing listening,
`apply()` takes exactly its earlier path at its earlier cost (a test pins it).

- **`contribute(callable $contributor)`.** A package adds entries to the tenant layer. A contributor receives the tenant's
  restricted *published* layers (root => pointer => entry) and returns the layers to lay. It runs inside `apply()`, so
  also on `refresh()` after a tenant publish, and **only after the tenant store was read**: a read that fails leaves
  the hop on the central values, contributors included. A tenant with no store yet (its schema not migrated) is not a
  failure: it reads as nothing published, and contributors still run. It must stay cheap, since it runs on every hop. A contributor
  that throws is logged by class and skipped. A lay of contributed layers that throws is undone, and the published layer
  alone is laid. So the tier's "never throws" holds whatever a contributor returns. beam-tenancy's cutover source,
  which lays a tenant's legacy `tenants.data` values until they are imported, is the first contributor.
- **`Splicewire\Beam\Config\Events\TenantConfigLayerLaid`.** Dispatched, only when something listens, whenever a
  non-empty tenant layer is laid: at the hop, and again when `ConfigOverlay`'s operator re-sync lifts the layer, writes
  the operator tier and lays it back mid-hop. It carries root => pointers, **never a value**. A feature that rewrites a
  laid value re-applies itself on it; otherwise the re-lay hands back the raw value. beam-tenancy's role alias is the
  first listener.

Why seams rather than a subclass: rebinding the overlay singleton from another package would hand every resolver that
package's subclass, and the result would depend on provider registration order.

## Amendment, 2026-11-22 (config-sections 20): a declaration below a nested Data shape

Decision 6 holds only for a secret the declaration walk FINDS. `ConfigSection::keyDeclaration()` descended into nested
Data only through a single named type. A union, a map or list of records, an intersection and `self`/`parent` all ended
the walk, so a key declared secret below them read as plain: the store kept the operator's value in plain in the
override row, the scope draft and the version snapshot, and the reader served it unredacted.

`ConfigDataTypes` is now the one reading of a property's type that `keyDeclaration()` and `ConfigSectionCoverage` share.
The walk:

- **descends fully** into the Data class(es) a property holds: a named type (`Nested`), a nullable type (`?Nested`), the
  Data members of a union (`Nested|Optional`, `Nested|Optional|null`, `A|B`), the Data member of an intersection
  (`Nested&Iface`), and `self`/`parent`, resolved against the declaring class. `Optional`, null and scalars hold no keys;
- **descends for secrecy only** into the entry class of a map of records (`#[MapValues(Entry::class)]`) or a list of
  records (`#[DataCollectionOf(Entry::class)]`). One pointer segment names the entry (a key or an index). A secret declared
  below it raises `secret` on the map's own declaration, and the map's ceiling, merge and effect stand. Each generated
  entry property carries a bare `#[ConfigKey]` (ceiling host), and `ConfigTierBoundary` resolves a map's entries to the
  map's declaration, so a full descent would make every generated map entry non-overridable;
- **folds Data members that disagree.** `secret` holds if any member is secret. The ceiling is the strictest (the lowest
  rank). The effect is the heaviest (deploy over reload over live). Merge is `extend` if any member says extend, else
  `replace` when the members differ. `env` is kept only when every member names the same one (no env and the empty name
  `''` are different). A member that does not declare the segment contributes the declaration above it;
- **does not walk** interfaces, untyped properties, an array with no item attribute, properties declared only on a
  concrete subclass of an abstract Data type, entry classes that are a `BaseData` but not a `Data`, and a
  `SomeData|array` property's `#[MapValues]` entry (the Data member wins). A secret declared there is NOT found and reads
  as its nearest declared ancestor.

**Authorization changes too, below a union, an intersection and `self`/`parent`.** The walk descends fully into them, so
a key below one now takes the inner class's own ceiling, merge and effect, exactly as a key below a plain `Nested` always
did. Before, it took the outer property's. An operator-raised outer ceiling over an inner bare `#[ConfigKey]` (host) is
now refused, and an inner ceiling above the outer one (say tenant under operator) is now admitted. Map and list ENTRIES
are unchanged: they keep the map's or list's ceiling, merge and effect. No real section is affected. The only
union-typed section property in any package or flagship main, on either fleet host, is the flagship's
`MeterEntry::$unit_cost_usd` (`float|Optional`, a scalar), and generated sections declare a bare `#[ConfigKey]` at both
levels.

**The store splits a secret inside a LIST of records below the pointer it writes.** The store asks every leaf of the
value written, and also each record of a list that sits below a non-secret key (a list in a nested Data class, a map
entry or a union member), by index. A list declared secret itself is still split whole. A list index is not a stable
identity, so a parent op now drops EVERY split child below its pointer (by pointer prefix, not only those it split
itself), and a pointer through a list index never goes into a parent's `inherit` (an unset of such a child would
otherwise fill the slot from the tier below's list at that index: another record's secret, and at the tenant tier the
operator's). A SPLIT secret of a list item therefore never outlives a later set of the list or of an ancestor, and
never re-applies to another record. A null secret in a list item is split too, as an explicit-null encrypted entry.

**Not closed here (ticket 21: a list index is not a stable identity):** a DIRECT entry written inside a list item
(`/relays/<i>/...`, `via` null) is not dropped by a later set of the list. So, as before this ticket:
- after `set /relays/0 {host: A, password: pa}` and then `set /relays [{host: B, password: pb}]`, record 0 reads
  `{host: A, password: pb}`, one record's host paired with another's secret;
- a secret written at its own pointer `/relays/1/password` applies to whatever record later sits at index 1, or
  re-creates a removed item. The consequence, unchanged in kind from before: a list of records
holding secrets loses those secrets on a re-save of the list or an ancestor that does not resend them. Use a map keyed by
a stable name for records that hold secrets.

**That prefix drop is a behaviour change for maps and objects too.** Before it, a set of `/a` dropped only the secrets
it had split out itself. A secret a deeper set of `/a/b` had split out survived, by accident of pointer order, and
missing from `/a`'s `inherit`. Now that set of `/a` drops it, while the deeper `/a/b` entry itself stays in force,
now WITHOUT its secret. Config-sections 19 (the next amendment) gives such a child
back, kept and re-pointed, when the re-save of `/a` leaves it out. Tickets 20 and 19 should therefore land together.

The two walks differ on purpose, and tests state how. Coverage resolves only a single Data member (`ConfigDataTypes::single()`:
a two-Data union, a Data with a scalar and an intersection stay unresolved, as before), and it walks maps but not lists.
The declaration walk takes every Data member. Through the shared reading, Coverage now also resolves a `?self`/`?parent`
property that names one Data class (it left them unresolved before).

An audit at the fix scanned every package and flagship main and every worktree on both fleet hosts. It found no Config
Section with a secret below any of these shapes, and no real section declares a secret at all, so no stored plaintext is
known. If a host finds one:

- An override row stored in plain before the fix keeps its stored `secret: false`. It is read as plain, with no decrypt
  attempt and no crash, and the reader now redacts it on the wire.
- Until that key itself is re-saved (it then splits into its encrypted entry), every save of ANOTHER key in the section
  copies its plaintext into the new version snapshot.
- Snapshots written before, and backups, keep the plaintext. Rotate a value that may have been exposed.

## Consequences

- Declaring a section changes no behaviour: every undeclared key is deploy-only and not overridable. On the first day,
  the Config area shows provenance read-only and names the env var to change, so the old dead ends become directions.
- A package author gets a schema, validation of their defaults, and a Config area row from one Data class.
- Sections for the flagship's `app.meters` and `app.entitlements` are the first two, declared by the host (ticket 02).
  Every beam package's sections follow (ticket 13), with the generator and two audits (ticket 06).
- `config:cache` keeps working. Provenance reads `config()` and syntax trees, never a re-executed host file. The one
  thing caching hides, whether an env var only `.env` held was set, reads as unknown rather than "file default".
- What would miss: a key whose value is assembled by a function call, or whose env var a provider reads directly, has no
  binding the walk can see, so the key declares it. A root whose host file doesn't return a literal array has no host
  keys, and the ladder reads it as package plus provider.
