# ADR-0223 — A `#[ParticleOp]` opts into a frame ACTION with `affordance:`, and beam projects it

**Status:** accepted — decided on owner delegation 2026-09-25; revisable
**Date:** 2026-09-25
**Repo:** `splicewire/laravel-beam`
**Wayfinding:** `.scratch/splicewire/laravel-beam/particle-operation-surface/` ticket 21 ("Frame renders a
declared operation"), spawned by `.scratch/splicewire/splicewire-ecosystem/extensions-live/` ticket 03.
**Extends:** ADR-0212 (a resource declares what backs it, not how it is shaped).
**Relates to:** `schemastud/laravel-frame docs/adr/0005-a-resource-declares-actions-a-button-a-form-and-a-result.md`
(the generic action concept this projects onto).

## Context

A `#[ParticleOp]` declares its input, output, subject and ability, and `Particle::ops()` mounts it. Frame
renders a framed resource's list and forms from the manifest, and until frame ADR-0005 it had no concept for
anything but CRUD. So `splicewire/laravel-beam-commerce`, which seats its Billing screen through frame, recast
its `site-credits.reload` operation as a resource create: a second resource (`billing-credits`) over the same
rows, a handler whose `store()` was a reload, and a form titled from the op's input DTO. Every package that
seats itself (extensions-live D2) would pay that for every non-CRUD write.

Frame ADR-0005 gives frame a generic `actions` slot. This record decides how an operation reaches it.

## Decision

1. **Opt-in, with a three-state slot.** `#[ParticleOp]` and `ParticleOperation` gain `affordance:`:

   | value | meaning |
   |---|---|
   | `'action'` or `new ActionAffordance(label:, result:, destructive:)` | frame draws the op as an action |
   | `false` | declared API-only; nothing is drawn |
   | `null` (omitted) | undeclared; nothing is drawn, and the omission is counted |

   The name follows the resource attribute's vocabulary: its `creatable`/`editable`/`deletable` are its
   affordance flags, and `createAffordance` already says where a create button lives. `'action'` is the only
   string the slot accepts; any other fails at registration. `ActionAffordance`'s `result` is `toast` (the
   default: the response's message, then refresh) or `navigate`; its `label` defaults to the op's name,
   headlined. Rendering every `Write` op was rejected: an omission and a decision must not be spelled the
   same, and some writes (webhooks, signed links) have no business on a screen.

2. **Placement comes from the subject's coordinates.** No coordinates (`NoSubject`, `ActorSubject`) is a
   `resource` action beside the list's "New". Exactly `{id}` (`RecordSubject`, `ColumnSubject`) is a
   `record` action on each row and on the detail page. **A `ParentSubject` op is out of scope**: it addresses
   a record through an edge, and frame has no edge concept to place it in. It projects nothing, and the audit
   reports a declared affordance it cannot place.

3. **Transport.** `ParticleResource::toResourceDefinition()` passes `actions:` from
   `Frame\ParticleResourceActions`, which reads the resource's ops from the operation registry and each op's
   URL from the router (the resource/name defaults `ParticleMounter::op()` stamps). The URL is host-relative
   and a record URL keeps `{id}` for the client to fill. An op that is registered but not mounted in this host
   has no URL and no button; a mount whose parameters the button cannot fill is skipped the same way. `input`
   is the op's `input:` class (null for `input: false`, which makes a confirm-only button); frame puts its
   dot-form name on the wire and serves its schema from `…/actions/{action}/schema`.

4. **The form** renders from the op's `input:` Data schema in request mode, as the create form renders from
   `editData`, so it uses the keys the op's URL accepts. The op's own controller validates it.

5. **Authorization.** Beam binds frame's `ResourceActionAuthorizer` port to `Frame\ParticleActionAuthorizer`,
   which asks the op's `ability:` through the same `AbilityResolver`, on the same plane, as
   `ParticleOperationController::invoke()`: `abilityModel: false` is the entitlement plane, a class-string
   `abilityModel` is that class, and otherwise the subject (nothing for `NoSubject`, the actor for
   `ActorSubject`, the record for a record op, or a fresh instance of the subject model at class level). The
   answer rides the per-actor `can.actions` map, so the button never shows for a user the mount refuses; the
   mount stays the enforcement. Frame binds a deny-everything default; like the access gate, beam re-binds it
   after boot when discovery order let frame's default win.

6. **The residue is counted, not failed.** `Doctor\UndeclaredAffordanceAudit` warns on `Write` ops with no
   `affordance:` (`particle.operation-affordance`) and on declared affordances frame cannot place
   (`particle.operation-affordance-placement`). `splicewire:beam:make:particle-op` emits the slot explicitly,
   `false` unless `--affordance=action`.

## Consequences

- The existing population is untouched: no op declared the slot, so nothing is drawn until one opts in. The
  audit makes the undecided `Write` ops visible.
- `laravel-beam-commerce`'s seat frames `site-credits` itself and declares `affordance:` on
  `site-credits.reload`. The second resource and the `store()` that reloaded credit are gone.
- A `record` action's visibility is class-level (the manifest has no row), so a row-dependent ability
  answers its most generous approximation and the mount refuses per row. That is the safe direction.
- Adding a slot to `ParticleOperation` is still the three-place change the attribute's docblock warns
  about: the attribute, the runtime object and `AttributedParticleDiscovery::registerOpClass()`.
