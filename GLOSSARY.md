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
