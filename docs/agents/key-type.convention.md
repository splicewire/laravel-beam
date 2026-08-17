# Key type — convention

**Status:** canon for every beam host and every package that publishes migrations into one.
**Decided:** 2026-08-17, after `~/Herd/splicewire` came up with a bigint `users` table under a uuid
migration, and seven other sites were found consistently bigint.
**Mechanism:** `Splicewire\Beam\Doctor\KeyTypeConformanceAudit`. This document states the rule; the
class documents the machinery.

## The rule

> **Identity is uuid, and a table's key type, its foreign keys, and its model must all say the same
> thing.**
> A third-party table vendored verbatim keeps its upstream shape.

Two halves, because either one alone lets the estate go wrong:

- **Agreement.** A migration and a model are two authored statements about one fact. When they
  disagree, exactly one is wrong — decidable without any opinion about which type is preferred.
- **Convention.** `users` is uuid-keyed. Agreement alone cannot say this: a site whose column and
  model both say bigint is perfectly self-consistent, and seven of them were.

## Why the convention half is not optional

`laravel-satellite-starter` shipped `$table->id()` while `laravel-beam-starter` shipped
`$table->uuid('id')->primary()`. **Two starters disagreed about the identity of `users`**, every site
born from the wrong one inherited a key type nothing ever checked, and the estate drifted in one
direction for as long as it took someone to notice by hand. A rule that only catches *inconsistency*
is silent on a fleet that is uniformly wrong.

## What a wrong key type actually does

None of it raises an error at migrate time:

1. **Eloquent generates no identifier on insert** when it believes the key auto-increments, so the
   insert fails on a NOT NULL `id` — or silently collides.
2. **`foreignIdFor(Model::class)` derives its column type from the model**, so one undeclared model
   emits a **bigint foreign key pointing at a uuid primary key**. SQLite stores that without
   complaint; MySQL and Postgres reject the constraint. The defect ships in dev and detonates on the
   first real database.
3. **`migrate` reports success throughout.**

The `~/Herd/splicewire` instance had all three at once, from a single missing `HasUuids`.

## Three things that are NOT the fix

**Making the model match a wrong column.** Agreement is the check, not the goal. A bigint `users`
that agrees with a bigint model is still off-convention.

**Mandating uuid everywhere.** `create_activity_log_table` ships `$table->id()` because it is
spatie's shape vendored verbatim, and that is correct. A blanket mandate flags every third-party
table on day one, which is how a check gets its floor raised and then deleted. The convention is a
named list (`beam.core.schema.uuid_tables`), not a universal.

**Fixing the column and stopping.** The column, every foreign key that points at it, and the model
are one change. `~/Herd/splicewire` needed all three; fixing two left a bigint FK against a uuid PK.

## The third-party seam

A table the estate publishes may be read by a model that ships in `vendor/` —
`laravel-beam-accounts` publishes `create_permission_tables` with a uuid `roles.id`, and a host that
does not bind `config('permission.models.role')` gets `Spatie\Permission\Models\Role`, which
auto-increments. Both halves of that defect are first-party decisions; only the model is in a
directory nobody scans.

**Bind a uuid-aware subclass in config.** The estate already ships several
(`Splicewire\Tower\Models\Role` among them). Code that resolves such a model must go through the
config key rather than naming the vendor class — `TeamProvisioner::syncSpatieRole()` is the idiom,
and `InteractsWithTenancy::rootUser()` is where getting it wrong took out an entire tenant suite.

## Symptoms

`null value in column "id" of relation "…"` on a clean insert; a foreign key that will not constrain
on MySQL/Postgres but is silently accepted on SQLite; `migrate:fresh` reporting success and only
seeding noticing. All three mean key type, not DDL.

## You don't have to remember any of this

`Splicewire\Beam\Doctor\KeyTypeConformanceAudit` enforces the rule mechanically, in three predicates —
`pk-model-disagreement`, `identity-key-convention`, and `third-party-key-binding`.

```
php artisan splicewire:beam:doctor
```

This document is the prose — the *why*, the two halves, and the three non-fixes. The audit is the
enforcement, and it stands on its own: it reads migrations, models and config, never this file. If
the two ever disagree, the audit is right and this page is stale.

It is advisory (warns, never fails the exit code) and static — no database connection, so it runs
before a host has ever migrated, which is when the fix is cheapest. It is deliberately conservative:
a model whose table it cannot resolve statically is **counted in the pass line**, not silently
skipped, because "no findings" and "could not look" are different facts.
