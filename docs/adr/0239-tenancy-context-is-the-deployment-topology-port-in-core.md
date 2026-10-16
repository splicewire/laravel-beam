# 0239 — TenancyContext is the deployment-topology port, homed in core

Status: accepted (2026-10-16, owner; host-binding-seam ticket 01, grilled from
architecture-pass-20261016 theme T2 / finding B6-1)

## Context

"Is tenancy active, who is the tenant, which connection is central" had no owner below the packages
that needed the answer. Measured 2026-10-16: 20 files across six packages carry a hand-copied
`function_exists('tenant')` probe (tower 11, beam-market 4, beam-taxonomy 2, satellite-knowledge 1,
beam-tenancy 1, beam-media 1; src only, tests excluded), and two central-connection helpers are
deliberate mirrors — `SatelliteTenancy::centralConnection()` and
`TenancyConnections::central()`, the latter documenting the duplication as forced: "satellite sits
ABOVE beam and beam-tenancy cannot depend upward." A forgotten copy is a recorded fatal
(satellite-knowledge `Fragment.php` carries the incident comment).

## Decision

- `Splicewire\Beam\Realm\Contracts\TenancyContext` lives in beam CORE, beside `TenantResolver`
  (deployment-shape siblings: that port answers per-realm resolvability; this one the process-wide
  moment). Surface: `isMultiTenant()`, `isActive()`, `currentTenant()`, `currentTenantId()`,
  `centralConnection(): ?string`.
- Core `bindIf`s `CentralTenancyContext` (container lane, defaults-only-if-absent): hard false/null
  for the live-tenant half — core never probes for a `tenant()` helper — and config-derived answers
  for `isMultiTenant`/`centralConnection`. beam-tenancy's stancl adapter supersedes with plain
  `bind()` (installing the tenancy package IS the posture declaration; host-binding-seam ticket 02).
- `centralConnection()` adopts TenancyConnections' null semantics: **null, never the default's
  name** — the default can be swapped mid-request by tenancy, so null ("the connection you would
  have used anyway") is the only honest fallback, and it keeps probes honest in harnesses whose only
  connection is `testing`.
- `isMultiTenant()` means "a split is declared and resolvable" (stancl's
  `tenancy.database.central_connection` names a configured connection) — deliberately NOT
  SatelliteTenancy's "central differs from the default" inequality, which answers false on
  tower-shaped hosts where central IS the boot default.
- Division against the `central` connection ALIAS (BeamServiceProvider, beam-facade ticket 96): the
  alias makes the NAME resolve for models that pin `central`; this port is the resolver kind, for
  readers with no model to pin. A resolver call is not a pin justification
  (`CentralPinJustificationAudit` reads declarations only).

## Consequences

- beam-tenancy: `TenancyConnections::central()` delegates to the port; the "cannot depend upward"
  clause retires (ticket 02, rule-11 sweep of the quoted sentence).
- laravel-satellite: `SatelliteTenancy` may delegate the shared half once it consumes the port
  (host-binding-seam stretch row) — its stricter `isMultiTenant()` inequality stays its own local
  call if it still wants it.
- New package code resolves the port instead of minting probe #21; the 20 existing probe sites
  convert opportunistically with their owning tickets (02–04), not in one sweep.
- Editor-parity ticket 08 (tenant Hosted sites) builds per-request tenant/connection branching on
  this port (cross-map edge, pending the owner's Q17 confirmation).
