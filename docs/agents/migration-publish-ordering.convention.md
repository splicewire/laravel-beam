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

**Hand-editing a published migration's timestamp.** The next `vendor:publish` undoes it, and it
papers over an ordering the install is supposed to guarantee. A greenfield app must come up correct
straight from install, with no hand-patched files in `database/migrations/`.

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
