# ADR-0222 — A realm dashboard is ONE read-only resource per realm, whose rows are cards

**Status:** accepted
**Date:** 2026-09-14
**Repo:** `splicewire/laravel-beam`
**Wayfinding:** `.scratch/splicewire/splicewire-ecosystem/realm-dashboards/` PRD ("Implementation Decisions"),
tickets 02, 04; executes the owner ruling of 2026-08-27 in
`.scratch/splicewire/splicewire-ecosystem/otb-ui-frontier-sidebar/DESIGN-01-shell-and-dock-exchange.md`
("Amendment — Dashboard as a read-only resource").
**Extends:** ADR-0212 (a resource declares what backs it), ADR-0221 (the index serves a streams-only backing).
**Relates to:** `schemastud/laravel-frame docs/adr/0003-summary-and-overview-are-collection-render-contexts-and-a-summary-provider-is-a-hidden-resource-slot.md`
(the contexts and the provider slot this resource consumes).

## Context

DESIGN-01 ruled on 2026-08-27 that *"dashboard can now be a read-only resource with just a read backing"*:
a `StreamsRecords` backing with no `WritesRecords`, no Eloquent model, entering the nav's one sort by
`navOrder`. It also named the trap: a model-less resource declaring no `policy:` is shown to every actor
its realm admits, so the read posture must be *a decision recorded in the declaration*. That ruling was
written for one host's operator dashboard. This map generalises it.

Measured 2026-09-14 (ticket 04 premise, confirmed): **only the manifest is mounted per realm**; the frame
resource socket is mounted once, realm-blind, and `ParticleFrameResourceHandler` reads a null realm there
(`:390`). A single `dashboard` key cannot therefore learn its realm from the route. The estate's exemplar
for a model-less, streams-only, actor-filtered, in-memory backing was `ResourceRegistryBacking`.

## Decision

1. **One read-only resource per registered realm, `{realm}-dashboard`**, a member of that realm only, backed
   by a `DashboardBacking` **instance** carrying the realm (`BackingResolver` takes an instance as-is). Realm
   identity is derived from membership because the socket carries none. Grammar (`keyFor`, `routeNameFor`,
   `PATH = 'dashboard'`) lives once in `Splicewire\Beam\Dashboard\RealmDashboard`; the leaf mounts as a list
   at `/{realmBase}/dashboard`, `showable: false`, `editable: false`, section-less at nav order zero.
2. **The backing is `Unpaged`** — a marker extending `StreamsRecords`, enveloped by the handler in frame's
   pre-paginated shape (`{data, total, page, perPage}`) so frame's controller returns it untouched. A read
   is paged twice (handler `perPage`, then frame's `per_page` re-slice); a bounded population declares
   itself whole rather than guessing a page size. Never for a record stream.
3. **Participation is owned once**, in `Splicewire\Beam\Dashboard\DashboardParticipation::contextFor()`: *a
   realm resource participates by default iff a leaf of the realm's projected rail resolves to it (by list
   route name, else href), or it declares `summary`/`overview` explicitly; `#[Summary(false)]` opts out;
   `overview` when declared, else `summary`.* The backing (actor in hand, `RailLeaves::fromNavItems()`) and
   the doctor audit (actor-free, `RailLeaves::declaredFor()`) read the same rule. The rail is what a user
   sees, so the rail is the definition.
4. **Jump-to tiles ARE the rail**: `context: 'nav'` rows appended after every card, in the rail's own walk
   order, one sort for cards and tiles.
5. **The gate is written on the declaration.** `policy:` is `RealmGateAbility::for()` —
   `entitlement:{declared}` for a gated realm, `entitlement:os.operate` for a central realm with none — and,
   for a gate-less non-central realm, `realm-dashboard.view` (`RealmDashboard::OPEN_ABILITY`, defined at
   boot as "an authenticated principal is present", narrowable by a host). `ModelLessReadGateAudit` then
   reads a decision, not an omission. Per-row `ResourceVisibility::listable()` is the second gate.
6. **Registered imperatively, at the beam-ux boot link and again in an idempotent `Application::booted()`
   sweep**, because the framework's route loader runs in its own provider-level `booted()` — after the last
   provider boots, before any application `booted()` callback — and the starters derive route constraints
   from the projected hrefs at load time. Deferring everything left `/operator/dashboard` a 404.
7. **The default provider counts through `ScopedIndexQuery::forDefinition()`**, the same scoped path the
   index uses (owner scope, saved filters, the declared `scope` closure), never the unscoped builder, so a
   tenant actor's figure equals its index total. A backing that cannot count declines (404).
8. **No new registry kernel**, no new route-mount verb (`mounts: 'list'`), no host config edit. The doctor's
   `DashboardTierAudit` (`particle.dashboard-tier`) reports declared / derived / absent per realm resource.

## Consequences

- A fresh host gets one dashboard per realm by writing nothing; a package puts a card on it by declaring
  `summary`/`overview` on a resource that is already in the rail. A contributed card naming a resource the
  host does not mount drops rather than throws.
- The read posture is auditable: an undeclared model-less read on the dashboard is now a doctor finding,
  not a default.
- Two repos spell the rule's two halves: participation and grammar in beam, seating and hrefs in beam-ux.
  The audit and the backing cannot drift because they call one function.
- **Runbook promotion is deferred** until a second host proves a dashboard config-only; repo-local per
  `docs/conventions/adr-placement.md`.

## Alternatives rejected

- **A single `dashboard` key with the realm read from the route.** Impossible on the socket: only the
  manifest is mounted per realm; the resource socket reads a null realm.
- **`section`-based seating** ("participates iff it declares `section:`"). Zero cards at the reference host:
  `users` and `teams` declare no section and reach the rail as a seat's static children.
- **A named fallback-stack registry for cards.** The seam widget registry's predicate chain already is one;
  a host replaces a card renderer by registering a later predicate.
- **User-arranged layouts.** A saved layout is a publish (`PublishPayload`), not a rendering; out of scope.

## Records

- Commits: laravel-beam `d28504c`, `6c9135a` (ticket 02), `b058cb8`, `5fb0764` (ticket 04);
  laravel-beam-ux `c989626`, `946336b`; `~/Workspaces/js/packages/beam` `d86e0cb`, `7db0efd`.
- Map: `~/Workspaces/splicewire-ecosystem/.scratch/splicewire/splicewire-ecosystem/realm-dashboards/`.
