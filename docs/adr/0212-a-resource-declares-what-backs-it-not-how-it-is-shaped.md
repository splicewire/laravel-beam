# ADR-0212 — A resource declares WHAT BACKS IT, and the backing is a type rather than three fields

**Status:** accepted
**Date:** 2026-08-23
**Repo:** `splicewire/laravel-beam`
**Wayfinding:** `.scratch/splicewire/laravel-beam/particle-contribution-seam/` tickets 06, 09, 10, 11, 01, 13
**Supersedes:** the `model` XOR `source` contract (ADR-0156 §57-58).
**Extends:** ADR-0116 (pre-packaged UI authoring), ADR-0152 (the projection inlined onto the declaration).

## Context

A particle resource used to describe its backing with **three fields**: `sourceKind`
(`'model'|'service'`), `model` (an Eloquent class-string) and `source` (a `UnionSource` class-string),
governed by a `model` XOR `source` contract. Ticket 06 opened on the observation that the XOR had no
slot for "both" — `tenants` needed a model AND a custom read.

The investigation did not find a missing third state. It found the wrong number of **axes**, and then
that the axes were not fields at all:

- **`sourceKind` had ZERO branching readers estate-wide.** It was written at declaration sites,
  asserted in tests, and interpolated into one error message. Every real branch was on
  `source !== null` — a type test wearing a string.
- **`model` was four live readers, not one**: write target, query root, a reverse identity index, and
  record resolution. A fifth reader (`dataClassFor()`) was measured at **zero reachable call sites**
  across four hosts (ticket 09), which is what let `tenants`' 10-line in-code defence of the field cite
  two beneficiaries that were both dead.
- **The estate already ships the pattern for this.** `ParticleOperation` carries `$model` *and*
  `$abilityModel` ("null ⇒ the resolved instance"); `ParticleResource::$routeKey` is a second split.
  Beam's house answer to "one field, many jobs" was already *split it, with a default*.

Two more facts constrained the shape. Frame's PHP read **none** of the three fields (ticket 10), so
they were beam's contract living in a schemastud class. And `ParticleResource::$model` was a required,
non-nullable `string` while frame's was nullable with two live resources shipping `model: null` — a
merge blocker nobody had named until ticket 11 §A10.

## Decision

**A resource declares one polymorphic `backing:` slot carrying a `ResourceBacking`. `model`, `source`
and `sourceKind` are deleted, not re-homed.**

Polymorphism is the discriminator the string field was standing in for.

### The slot is polymorphic on what you hand it

| given | meaning |
|---|---|
| a `ResourceBacking` instance | used as-is |
| a `ResourceBacking` class-string | container-resolved at REQUEST time, so it can take constructor injection |
| any other class-string | read as a model class and wrapped in `EloquentBacking` |

That last row is load-bearing. ~30 of this estate's resources are one plain Eloquent model, and making
them each name a backing class to state the least interesting thing about themselves would have been a
tax with no reader behind it. The ordinary declaration reads `backing: Silo::class` — one word changed
from what it replaced — while the declaration genuinely carries **no model field**, which is what let
the two declaration types merge.

A class-string is **not validated** at declaration time. The field it replaced was a plain
`public string $model` that nothing ever checked, and a declaration may legitimately name a class this
package cannot see (a host's model) or a fictional one (doc generation exercising the manifest without a
database). A bogus class still fails at the first query, exactly where `$model::query()` failed before.

### Capabilities, and why there are five

| capability | contract |
|---|---|
| `StreamsRecords` | `records(filters, cursor, perPage): CursorPaginator` — the GENERAL one |
| `QueriesRecords` | `query(filters): Builder` — Eloquent-ONLY |
| `ResolvesRecord` | `resolve(id, filters): ?ResolvedRecord` |
| `WritesRecords` | `resolveForWrite()` + `newRecord()` |
| `BacksModel` | `modelClass()` — **conditional** |

Ticket 11 named four. The fifth exists because the two shipped query shapes are genuinely different
contracts and it never reconciled them: a model-backed resource yields a **composable `Builder`** the
caller goes on refining (data-filters operators, saved filters, owner scoping, the declared default
sort), while a union yields an **already-paged, already-projected `CursorPaginator`**. Collapsing them
into one capability needs a return-type discriminator — precisely the thing polymorphism replaced.

`BacksEloquent` expresses `records()` in terms of `query()`, so an Eloquent backing gets the general
capability free and the split costs nothing at the implementation site.

⚠️ The general capability's method is **`records()`, not `stream()`**. All four backings in this estate
already have an internal `stream()` — the projected collection they page over — so the obvious name
would have collided with the implementation it wraps, on every one of them.

### `$filters` is the same argument everywhere

Every capability takes the request's opaque `filter[...]` bag, unchanged in shape whatever backs the
resource. That is what makes the capabilities substitutable. Beam never interprets it; the backing owns
its own query semantics.

This absorbed `UnionSource::find()`'s separate `string $source` union-arm discriminator into the bag.
It was a filter by any other name, and its own positional parameter forced every backing to accept an
argument only unions use.

### Capability is the CEILING; affordances narrow it

`instanceof WritesRecords` is what the backing **can** do; `creatable`/`editable`/`deletable` are what
the resource **may** do. An affordance opened against a backing that cannot honour it **throws at
registration** — a declaration error, not a 405 discovered on the first write. The check reads the
backing statically, so registration never resolves it.

This is what lets `tenants` read honestly for the first time. It used to say the same thing twice in two
vocabularies — `sourceKind: 'service'` AND `creatable: false`. Now the affordances say it once and beam
enforces that they cannot lie.

### `BacksModel` is conditional, and must stay that way

> A backing whose rows are all instances of one Eloquent model MUST implement `BacksModel`.

Conditionally required. Three production backings legitimately cannot — tower's `MembershipSource` and
`ReviewQueueUnionSource`, beam-accounts' `MembershipSource` — because a pivot row is identified by no
single model and a genuine union spans two record types. ⚠️ After the reclassification the
non-`BacksModel` population is 3 against ~30. **That near-universality is a fact about today's estate,
not licence to promote `modelClass()` onto the port** — those three have no legal answer for it.

The rule is about declaring what you BACK, not how you QUERY it. `TenantAdminSource` satisfies it while
running a hand-rolled batch-keyed query, which is legal residue under ticket 12 §A4.

### One declaration type

`registerDefinition()` is deleted and the raw `ResourceDefinition` escape hatch with it. This is the
retirement of what ticket 06 named the god-projection's root cause — *"a model-backed resource
registered through the presentation-tier type"* — because there is no second type to register through.

`get()`'s raw-definition `throw` is deleted **in the same commit**: a migration tripwire, not a policy,
whose branch is unreachable under one type. `all()`'s `instanceof` filter goes the same way. ⚠️ That
filter never did what its comment claimed — raw definitions were excluded for "carrying no Data class to
audit", but `ResourceDefinition::$data` is required and non-nullable, so the drift audit was blind to
exactly the resources this map suspected.

## Consequences

- **`tenants` enters the `ModelResourceIndex` for the first time.** `TenantAdminSource` implements
  `BacksModel` — correcting ticket 11 §A7, which used it as its example of a backing *without* a model.
  Measured: every row is a `Tenant` in both methods.
- **Two hand-rolled reverse indexes retire.** Both Surgeon audits reflected into the registry's private
  `$resources` on the stated grounds that *"the registry exposes `has($key)` but not an enumeration"* —
  `all()` existed and applied the identical filter. `ModelResourceIndex` is the one home. ⚠️ It is
  one-to-MANY and must NOT inherit `SchemaBindingIndex`'s throw-on-duplicate: two resources legitimately
  share a model, and inheriting it fails boot on a legal declaration.
- **A `BeamParticle`-backed resource becomes expressible**, and so does any external backing — neither
  was before.
- **BC break**, absorbed: ~78 declaration sites across 23 repos changed one word.
- **Not done here:** `ParticleOperation::$model` (a separable axis — `Sharing.php` and `beam-rank` pass a
  per-host model into ops for resources that need not be registered particle resources) and narrowing
  `ParticleHydrator`, which stays the out-of-scope read-repair effort. ⚠️ That effort no longer gets to
  choose its port's shape: it is per-resource, which dissolves its own federated-read precondition.
