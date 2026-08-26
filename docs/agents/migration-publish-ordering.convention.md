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
`rushing/laravel-schema-convergence`'s `docs/agents/convergent-migration-guards.convention.md`: package-tools
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

## `--travel=` — where the block this install publishes lands

**Decided:** 2026-08-26, beam-facade ticket 117, the owner's idea.

The lever above claims ONE stem against ONE competitor. The wider question — *the block I am about to
publish has to sort before migrations this host already has* — had no answer but a hand re-date, which
this page rejects. `--travel=` is that answer, put in the installer where a fresh clone inherits it:

```
php artisan splicewire:beam:install --travel="-1 year"
php artisan splicewire:satellite:install --travel="-1 year"   # forwarded down to beam
php artisan splicewire:tower:install --travel="-1 year"       # forwarded down to satellite → beam
php artisan splicewire:beam:commerce:install --travel="-6 months"
```

`Splicewire\Beam\Install\MigrationTravel` is the mechanism. Five things about it are deliberate:

- **It moves the RUN, never a file.** There is no single-file affordance at all, because "Re-stamping one
  file to move it" above is a real incident and the documented repair is to regenerate the whole chain.
  The moved set is exactly the files that did not exist before this run.
- **The value is relative, and an absolute date is refused with its reason.** Pinning a run's files to a
  chosen stamp is the `0001_01_01_*` publish band ticket 22 rejected, it collapses the ordering the
  install manifest just produced, and it cannot cascade — three tiers anchored to one date stack on the
  same second.
- **The calendar expression resolves ONCE, against the run's earliest stamp.** PHP overflows month
  arithmetic (`2026-03-31 -1 month` is `2026-03-03`), so evaluating per file can invert two files a
  second apart that straddle a day boundary. One delta, applied to all, keeps spacing exact.
- **It reports what the block crossed.** The already-published migrations it now sorts past are named —
  the visibility the re-stamping incident lacked. A crossing only matters for a migration that has not
  run.
- **It cannot touch the ledger half, by construction.** A file that did not exist before this run cannot
  have run, so no move it makes can orphan a `migrations` row. Making an *already-run* migration pending
  again is a different lever with a different blast radius and is not here.

**It governs FRESH and reset databases**, because filename order sorts pending files only. And it does
not replace `$order`: `$order` decides the order *within* the block (per package, declared once, every
host); travel decides where the block *sits* (per invocation, operator-declared). A cross-package
ALTER-before-CREATE bug is always `$order`'s.

## A published copy is a snapshot, not the source of truth

**Decided:** 2026-08-24, beam-facade ticket 86, after a column rename shipped correctly and reached
nothing.

> **Editing a `create_*` stub changes what a FRESH database gets and touches nothing that already
> exists.** Every already-migrated database keeps the old shape, `migrate` says `Nothing to migrate.`,
> and the test suite stays green because `RefreshDatabase` migrates fresh.

Ticket 65 renamed `form_key` → `capture_key` in beam's `create_beam_submissions_table` stub and in its
~17 published copies. Correct, complete, and the estate's live databases still had `form_key` while the
code wrote `capture_key` — nine of them one request from a hard SQL error, flagged by nothing.

The rule this corrects is beam-facade ticket 46's, which is quoted everywhere on that map: *measure the
rows before pricing a rename*. It is right about **cost** and silent about **reach**. Zero rows means
the ALTER is trivial; it never meant the ALTER ran.

### The procedure

**Rebuild, don't shim.** The declared repair for a renamed or reshaped published table is to reset the
database, not to ship a guarded ALTER stub beside the create. The estate is greenfield: drop the
migrations and tables as needed and rebuild. This is a **dated posture, not a property of renames** —
it holds because every database in this family is disposable today, and it expires the moment one is
not:

> **Trigger.** The day any deployed database in this family holds data someone would miss, a rename of
> a published table owes a guarded shim migration and this section is wrong. `beam-pilot-gcp-cloud-run`
> is the only deployed root today and is declared resettable — its `docker/entrypoint.sh` runs
> `migrate --force` on boot, so a redeploy after a `migrate:fresh` is the whole repair.

Do not reach for a shim before that trigger fires, and do not reach for the convergent guard instead:
it cannot help here and 29's re-dating lever makes it worse. See "What convergence cannot do" in
`rushing/laravel-schema-convergence`'s `docs/agents/convergent-migration-guards.convention.md`.

### When you meet a migration error at a host

An agent hitting a migration failure from a package-published migration is very likely looking at a
**stale snapshot, not a bug**. The host's `database/migrations/**` copies were stamped at install time
and the package has moved since; the host set is presumed **inferior** to the package source, not
authoritative.

1. **Compare** the published copy against the stub it came from — `vendor/<vendor>/<pkg>/database/
   migrations/**/<stem>.php.stub`, matched by basename with the timestamp prefix stripped.
2. **Gap-analyse** the divergence: what the source adds, drops, or reshapes.
3. **Choose the repair per instance.** Reset and republish, re-publish over the stale copy, or inject a
   correctly-ordered copy. There is no default and no mechanism that picks for you — the choice depends
   on what diverged and what the host holds, and it is judgment.

**Detection is deterministic and the repair is not.** That seam is deliberate and it is surgeon's
existing house pattern — `Rushing\Surgeon\Conformance\StaleDownstreamDuplicateAudit` draws the identical
line for host-local DTOs that twin a downstream package's ("identical → fixable; drifted → advisory
only, reconciling a divergence is judgment"). `Rushing\Surgeon\Conformance\PublishedMigrationDriftAudit`
is the sibling that enumerates published-migration divergence the same way: it names the drift, nominates
nothing, and gates nothing.

### Don't wait for a migration error to find out

You do not have to already be looking at a failure. The audit runs host-side in every
`surgeon:audit` sweep and reports **every** published copy under `database/migrations/**` whose content
no longer matches the package file it came from — matched by basename with the timestamp prefix stripped,
across publish-destination moves, no database and no git involved.

```
php artisan surgeon:audit
```

Two things to expect from it, both measured at 20 hosts on 2026-08-26 (**314 drifted copies estate-wide**,
119 of them at `~/Herd/splicewire-app`):

- **A large population is the normal reading, not an alarm.** Ticket 28 recorded the estate's steady state
  as *a swept package beside an unswept host*, and this is the instrument that finally says so out loud.
- **It cannot tell a fossil from a deliberate host edit**, and it does not try. `laravel/passport`'s
  published `create_oauth_auth_codes_table` differs from its source by one line — `foreignId('user_id')`
  became `foreignUuid('user_id')` — which is a host that chose uuid user keys, i.e. exactly what publishing
  a migration is for. That is why every finding is advisory and none nominates a fix.

## The other half of the collision family

This page is about **ALTER-vs-CREATE across packages**. It does not address **two CREATEs of the same
table**, where sequencing is not the lever because both sides believe they own the table — see
`rushing/laravel-schema-convergence`'s `docs/agents/convergent-migration-guards.convention.md`.
