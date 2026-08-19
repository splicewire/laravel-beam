# Migration publish ordering — convention

**Status:** canon for every package that publishes migrations into a beam host.
**Decided:** 2026-08-17, after a greenfield install came up with no `silos.name_path`.
**Mechanism:** `Splicewire\Beam\Install\BeamInstallManifest` — `$order` on the step a package
registers. This document states the rule; the class documents the machinery.

## The rule

> **Publishing STAMPS a migration at publish time, so install order IS migration order.**
> A package shipping an ALTER against a table ANOTHER package creates must register at a higher
> `$order` than the package that creates it.

`spatie/laravel-package-tools`' `generateMigrationName` timestamps each published file at the moment
it is published, a second apart in `->hasMigrations([...])` declared order. Two packages left on the
default `order: 100` are therefore separated only by **provider boot order** — which is composer's
resolution order, not anything anyone declared. The ALTER can be stamped ahead of the CREATE and run
against a table that does not exist yet.

A package whose ALTERs target only its OWN tables needs nothing. `->hasMigrations([...])` already
sequences its own entries, so its creates precede its own alters.

## Current tiers

| order | packages | why |
| --- | --- | --- |
| 0 | `laravel-beam` | the substrate everything composes |
| 5 | `laravel-beam-accounts`, `laravel-beam-tenancy` | identity + tenancy land before consumers |
| 20 | `laravel-beam-ux` | |
| 100 | every other `beam-*` / `satellite-*` package | default |
| 200 | `tower` | the estate's only cross-package ALTER shipper |

`tower` is at 200 because it ships `add_federation_scope_to_silos` and
`add_directory_acl_grants_and_visibility` against **beam-taxonomy's** `silos`. At the default 100 it
tied with beam-taxonomy and the winner was boot order.

## Two things that are NOT the fix

**Hand-editing a published migration's timestamp.** It papers over an ordering the install is supposed
to guarantee: a greenfield app must come up correct straight from install, with no hand-patched files
in `database/migrations/`. (This used to add "the next `vendor:publish` undoes it" — that reason is
**false** and is corrected in
[`convergent-migration-guards.convention.md`](convergent-migration-guards.convention.md): package-tools
re-finds a published file by basename, so a re-date survives. What does not survive is the next
greenfield clone, which is the real objection.)

The objection is to the **hand** edit, not to re-dating as such — see "Re-dating IS the fix once the
install causes it" below. The same file at the same timestamp is correct when the install put it there
and wrong when a person did, because only one of those survives a fresh clone.

**Re-stamping one file to move it.** Re-stamping is relative to *every* already-published file, not
just the one you were thinking about. Moving beam-taxonomy's creates forward once pushed them past
tower's other ALTERs still sitting at their original stamps, and a fresh migrate died on a duplicate
column. If a dependency chain has to be regenerated, regenerate **all** of it: delete every published
file in the chain and re-publish, so the whole set re-stamps together in install order.

## Symptoms

A fresh database missing a column another package's ALTER was supposed to add; `column "x" of
relation "y" already exists` on a clean migrate; an ALTER failing because its target table does not
exist. All three mean order, not DDL.

## You don't have to remember any of this

`Splicewire\Beam\Doctor\MigrationOrderingAudit` enforces the rule mechanically — it joins the install
manifest (package → order) against every registered package's migration stubs (table → created /
altered) and warns on any cross-package ALTER whose package does not install strictly after the
package that creates the table. Equal orders warn too: a tie is resolved by provider boot order,
which nothing declares.

```
php artisan splicewire:beam:doctor
```

This document is the prose — the *why*, the tiers, and the two non-fixes. The audit is the
enforcement, and it stands on its own: it reads the manifest and the stubs, never this file. If the
two ever disagree, the audit is right and this page is stale.

It is advisory (warns, never fails the exit code) and deliberately conservative — a dynamically-named
table (`Beam::table('media')`, `$this->target()`) is unresolvable without booting the declaring
package, so it is skipped rather than guessed at.

## Re-dating IS the fix once the install causes it

Everything above is about packages *inside* the family, where `$order` is the lever. Against a
**third party** it is not: `lunarphp/core` ships `activity_log`, `media`, `tags` and the permission
tables at fixed `2026_01_01_*` stamps behind bare `Schema::hasTable() → return` guards. We do not own
that guard, so the loser reports success over the winner's schema and filename order is the only lever
left.

`splicewire:beam:install` spends it, between publish and migrate:

```
php artisan splicewire:beam:install                     # asks; every collision defaults to beam
php artisan splicewire:beam:install --own-tables=       # decline all — the other package wins
php artisan splicewire:beam:install --own-tables=media  # claim one
php artisan splicewire:beam:install --no-interaction    # silently takes the default (beam owns all)
```

It finds every published `database/migrations/**` file whose name matches a migration a package
registers from its own source, asks who owns each, and re-dates beam's copy **one tick below** the
earliest competitor. `Splicewire\Beam\Install\TableOwnershipResolver` is the mechanism.

Four things about it are deliberate, and each answers an objection on this page:

- **The unit is the filename stem, not the table.** A table name is routinely dynamic
  (`Beam::table('particles')`, `config('activitylog.table_name')`) — the same blindness
  `MigrationOrderingAudit` refuses to guess around. A stem is a literal on disk.
- **One tick, never a `0001_01_01_*` band.** "Re-stamping one file to move it" above is a real
  incident, and a band maximises the blast radius that incident is about. One tick minimises it, and
  it is derived from the competitor's own stamp rather than chosen.
- **It warns about the one direction that can break.** Moving a CREATE *earlier* is safe for every
  ALTER against that table; it is unsafe only where the create itself references a table created
  later. The resolver reports those (literal FK targets only) and claims anyway — the operator
  declared the ownership, and a failed migrate is loud.
- **Two migrations a HOST wrote itself are invisible to it.** Both are "ours", neither is a package's
  own source, and beam has no standing to arbitrate between two of an app's migrations.

Declining an entry is a real answer, not a mistake: the competitor's migration wins and beam's
convergent guard then throws on the type conflict instead of reporting success. Loud, chosen, and
recoverable — which is the whole trade this collision family is decided on.

## The other half of the collision family

This page is about **ALTER-vs-CREATE across packages**. It does not address **two CREATEs of the same
table**, where sequencing is not the lever because both sides believe they own the table — see
[`convergent-migration-guards.convention.md`](convergent-migration-guards.convention.md).
