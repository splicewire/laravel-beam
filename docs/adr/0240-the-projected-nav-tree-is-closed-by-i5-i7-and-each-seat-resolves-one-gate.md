# ADR-0240 — The projected nav tree is closed by I5–I7, and each seat resolves exactly one gate through SeatGate

**Status:** accepted — the record of the app-walkthrough IA epic (APP-01, APP-03, APP-06, APP-07, APP-16), written as its
records ticket APP-23, 2026-10-21. There was no live exchange; the invariants and the SeatGate resolution landed with the
tickets this ADR cites, and this ADR records them as one decision.
**Date:** 2026-10-21
**Repo:** `splicewire/laravel-beam`
**Wayfinding:** `.scratch/splicewire/tower/app-walkthrough/SPEC.md` (§2.B APP-06, §2.C APP-16, §5.1 T7/T8) and ticket
APP-23, in `splicewire-ecosystem`.
**Builds on:** the IA invariants I1–I4 (`splicewire/laravel-beam-ux` `src/Ia/Invariants/`), ADR-0222 (a realm dashboard
is one read-only resource per realm), ADR-0223 (a particle-op opts into a Frame action), ADR-0230 (a guest reads a
resource only through its Public Read).
**Companions:** `splicewire/laravel-beam-ux` `IaInvariantsTest` (I5–I7) and `AssertsHostIaSeam` (T7a/T8); the
app-walkthrough `g1-host-ia` and `g1-host-primary-nav` browser probes; `SeatGateTest`.

## Context

A realm's rail is projected from declared nav, not authored by hand (ux-walkthrough IA-5…IA-10). The IA invariants I1–I4
already hold the rail in its realm (I1), keep developer seats in the Developer zone (I2), sit each row under one task
section (I3), and join every href to a mounted route (I4) — coded in `splicewire/laravel-beam-ux` `src/Ia/Invariants/`,
throwing where a host is built and pruning package-contributed violations in production.

Three further properties were decided across the app-walkthrough IA epic but were not yet recorded as one ADR: that the
projected tree is **closed** (every nav seat names a leaf and every seatable leaf has a home or is declared unseated),
that each seat resolves **exactly one** authorization decision, and that each tenant object has **exactly one** unscoped
list. Closure is stated first, because "one gate per seat" and "one home per object" are only checkable on a closed tree
(SPEC X1).

A seat, further, carries no permission declaration of its own: its visibility is the answer its route gives, and a
client-drawn (SPA) seat has no HTTP route of its own at all. The epic introduced `SeatGate` to make that answer explicit
and single.

## Decision

1. **I5 — Closure** (app-walkthrough APP-03, "M5′ I5"). Per realm and per principal: (a) every nav `routeName` names a
   leaf and its href is the minted href; (b) every non-parameterised `list|detail|widget` leaf has a nav home — its own
   node, or the `.index` of its `.create`/`.edit` — or declares `unseated: '<reason>'`; (c) a resource with an empty,
   unfolded nav label emits no leaf (`saved-filters`), and a folded projection emits none (`review-units` → Review).
   Closure is numbered first; I6 and I7 are only checkable on a closed tree.

2. **I6 — One gate** (coded: `splicewire/laravel-beam-ux` `src/Ia/Invariants/SeatResolvesOneGate.php`; app-walkthrough
   APP-06/APP-07). Every local nav seat resolves exactly one authorization decision from its route. A seat over a gateless
   route is a violation unless the route says so on purpose — `ability: false`, or a declared open-to-members marker. A
   missing decision stays missing, so I6 rejects host-authored navigation and prunes package-contributed navigation
   rather than treat an omission as public.

3. **I7 — One home** (app-walkthrough APP-16, "M5′ I7"). Each glossary object's tenant list has exactly one seat, its
   home. Other surfaces may link to it (a door) or embed a view scoped to a parent record (a record tab), never a second
   unscoped list. A key may appear at two seats only if one is a record tab.

4. **SeatGate is the route-derived answer to "may this principal see this seat?"** (`src/Authorization/SeatGate.php`). A
   seat carries no permission of its own; its route resolves, in order, to the resource-list gate, the declared
   particle-operation gate, an explicit open decision, or a named middleware gate. The resolution is kept server-side as a
   `SeatGateResolution` (its `kind`, and the `Route` / `ResourceDefinition` / `ParticleOperation` it came from) while a
   nav node is projected.

5. **The resolution vocabulary is one enum, `SeatGateKind`**, with four cases and no fifth: `Resource` (a Frame list's
   declared read boundary — realm reach plus `ResourceReadGuard`, or a model-less read), `Operation` (a declared
   `#[ParticleOp]`'s `ability:`), `Route` (a named middleware gate on the route), and `Open` (an explicit, reviewed
   decision that authenticated membership alone suffices).

6. **A client-drawn seat points at the one data route that governs it.** `SeatGate::backedBy($seatRouteName,
   $backingRouteName)` declares that backing, and `resolve()` follows the backing chain; it refuses a backing equal to the
   seat, a seat already declaring an open or conjunctive gate, a re-point to a different backing, and a backing cycle.
   `SeatGate::openToMembers($seatRouteName)` declares, case by case, that the authenticated tenant boundary is the seat's
   whole gate (`SeatGateKind::Open`; `allows()` then passes iff the actor is non-null); it refuses an empty name or a seat
   already backed or conjunctive. A seat needing both a route gate and a Frame list read is declared conjunctive with
   `backedByRouteAndResource()`.

## Consequences

- The three invariants have one record. `IaInvariantsTest` (I5–I7) and `AssertsHostIaSeam` (T7a/T8) are the package
  proof; `g1-host-ia` and `g1-host-primary-nav` are the browser proof, per principal (app-walkthrough §5).
- **I6 is coded today** (`SeatResolvesOneGate`). **I5 and I7 are recorded here as the epic's rules but are not yet coded**
  in `src/Ia/Invariants/`; they run today through the app-walkthrough probes (APP-03's structure widening, APP-16's
  closure test) and through `assertHostIaClosureRatchet`. A later change lifts them into `src/Ia/Invariants/` beside
  I1–I4; this ADR is their standing definition either way.
- A seat with no resolvable gate is never silently public: host-authored navigation throws at build, package navigation
  is pruned in production. Adding a seat therefore means either its route already declares a gate, or the seat is
  explicitly backed, opened to members, or declared conjunctive.

## Considered and not taken

- **Letting a seat carry its own `ability`.** Rejected: a seat is a view of a route, and two places to declare one
  permission drift. The route is the single source, and `SeatGate` reads it.
- **Treating a gateless route as public.** Rejected by I6: a missing decision stays missing. Public reach is declared (a
  Public Read, ADR-0230, or an open-to-members marker), never inferred from omission.
- **Coding I5 and I7 in this change.** Deferred: they are recorded here and enforced through the app-walkthrough probes
  and the closure ratchet; lifting them into `src/Ia/Invariants/` is a separate, mechanical move this ADR already
  licenses.
