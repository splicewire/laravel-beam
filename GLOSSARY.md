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
