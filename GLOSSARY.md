# Glossary — splicewire/laravel-beam

Terms this package coins, where a name is the decision. ADRs in `docs/adr/` own the rulings; this
file is the index for readers. Wire-shape vocabulary (particle, SHAPE slots, affordance) lives in
`docs/agents/particle-doctrine.md` and is not restated here.

- **TenancyContext** — the deployment-topology port in `Realm/Contracts`: is a central/tenant split
  declared and resolvable, is a tenant initialized on this request, which configured connection is
  central (null = "the connection you would have used anyway"). Core's `CentralTenancyContext` is
  the no-tenancy default (`bindIf`); beam-tenancy's stancl adapter supersedes it. Not the `central`
  connection alias (that makes the NAME resolve for pinned models), and not `TenantResolver` (that
  answers per-realm resolvability). ADR-0239.
- **TenantResolver** — the per-realm tenant-resolvability port (deployment-shape switch keyed by
  realm), re-homed from the retired `RealmDefinition::$tenancy` flag. Realm-architecture ticket 08.
- **Host-binding lanes** — how a family package ships a default and how anything overrides it:
  container lane (`bindIf` default / plain `bind()` supersession behind a posture / `extend()`
  decorator — order-independent) and config lane (null-guarded write / `packageBooted` timing —
  order-sensitive). The doctrine, with exemplars by cite:
  [`docs/agents/host-bindings.md`](docs/agents/host-bindings.md).
- **Seed step** — one entry in `beam.seed.steps`: a seeder class a package or host registers from its own provider
  under its composer coordinate (a sub-coordinate, `vendor/name/step`, for a second one), with an order and an
  optional config gate. `splicewire:beam:seed` runs them. `docs/registries/beam.seed.steps.mdx`.
- **Seeding Scenario** — a named selection of seed steps that one `splicewire:beam:seed` run executes: `include`
  and `exclude` lists over step coordinates and memberships, on top of `baseline`. Chosen by a ladder: the run's
  `--scenario`, then `BEAM_SEED_SCENARIO`, then the scenario named for the environment, then `baseline`. A scenario
  selects steps; it never changes what a step writes. Named and ruled 2026-11-13; **not built yet** (ecosystem
  scratch, `splicewire/laravel-beam/seeding-scenarios` ticket 01). _Avoid_: bare "scenario" (beam-synthetic and
  beam-exec own other senses, and the flagship has **Synthetic Tenant Scenario**), "profile", "seed set", and
  "environment" (an environment only supplies the default scenario name).
- **Declared reach** — the types a host's generation reaches by walking references from its declarations: every
  particle's SHAPE slots and every route-declared type. A reached type needs no scanned directory.
  `docs/agents/typescript-generation.md`; ADR-0241.
- **Route-declared type** — a Data class a route names as its response body or stream payload
  (`->beam()->returns()`/`->streams()`, `#[ResponseFromData]`/`#[StreamsFromData]`); part of declared reach. Not a
  request body: `#[RequestFromData]` seeds nothing.
- **Package-declared type root** — a directory a package names in its `composer.json`
  `extra.typescript-transformer.directories`, with the reason no declaration reaches it; scanned at every installing
  host. _Avoid_: a host-listed `vendor/` root, "auto-discovery" (a v2 term).
- **Host type selection** — a root one host lists, with a comment, because only its own frontend consumes that
  population (the flagship's estate scan). The opposite of a package-declared type root.
