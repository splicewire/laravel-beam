# Convergent migration guards — convention

**Status:** canon for every package that publishes a `create_*` migration into a beam host.
**Decided:** 2026-08-18, after the same collision was sighted three times in one sweep.
**Mechanism:** `Splicewire\Beam\Schema\ConvergentTable` — the fluent guard this document governs the
use of. This page states the rule; the class documents the machinery.

This is one half of a two-part collision family. The other half —
[`migration-publish-ordering.convention.md`](migration-publish-ordering.convention.md) — covers
**cross-package ALTER-vs-CREATE**, where a package's ALTER is stamped ahead of the CREATE it depends
on. Neither page is complete alone: that one is about *sequencing packages*, this one is about *two
CREATEs of the same table*, which no amount of sequencing fixes because both sides believe they own it.

## The rule

> **A published `create_*` migration declares the shape it needs, not the table it creates.**
> Guard it with `ConvergentTable`, never a bare `Schema::hasTable($t) → return`.

```php
use Splicewire\Beam\Schema\ConvergentTable;

ConvergentTable::named(Beam::table('ownership_edges'))
    ->define(function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('owner_id');
        $table->timestamps();
    })
    ->assert();
```

The definition closure is an ordinary Blueprint closure — the same one a `Schema::create()` would take.
Converting an existing migration is an indent plus a terminal.

## Why a bare existence guard is the defect

`spatie/laravel-package-tools` stamps a published migration with `now()`; vendor packages ship
fixed-date copies. So two migrations creating the same table race on filename — and because both sides
guard with `hasTable`, **whichever sorts first wins and the loser's guard reports SUCCESS.**
`migrate:fresh` exits green having produced a schema the app cannot use.

That is not a hypothetical. It was sighted three times during the beam-facade sweep:

1. **`lunarphp/core`'s `activity_log`**, whose `nullableMorphs` are bigint where beam needs string
   morph ids, so a tenant slug written into it throws `invalid input syntax for type bigint`.
2. **Two `create_media_table`s** in one host, which two separate tickets each had to prove
   pre-existing before they could read their own gate.
3. **A stale `create_beam_submissions_table` fork** sorting ahead of the canonical stub in three
   starters, handing hosts the old schema while `migrate` reported success.

In every case the *rule* was known and the *guard* was the thing nobody wrote.

## The three tiers

| state | verdict |
| --- | --- |
| table absent | create it |
| table present, declared columns absent | **add them** — converge |
| table present, column present with the wrong type | **throw** — cannot converge |

Tier three is the load-bearing one, and it is why "top up whatever is missing" is not enough on its own.
Lunar's `activity_log` does not *lack* the morph columns — it has them as the wrong type, so
`hasColumns()` returns true and a top-up guard tops up nothing and yields the broken table.

**A conflicted run writes nothing at all.** The table is left exactly as it was, so the two terminals
below differ only in how loud they are, never in what they did.

## Two terminals: pick the loud one unless you can say why

- **`->assert()`** throws `SchemaConvergenceConflict`. **This is the default.** A conflict is an
  install-time stop by design: the failure it replaces is silent, and an install that fails loudly is
  strictly better than an app that runs on a schema it cannot write to.
- **`->matches()`** returns a bool and stays quiet. For the call sites that genuinely do not care which
  shape won — a harness that migrates the central and the tenant pass into one schema, or a table whose
  competitor is harmless in a host that never reads it.

One terminal would force every call site into the other's shape, which is why both ship. But reaching
for the quiet one is a claim about the table, and it belongs in a comment beside the call.

## What convergence cannot do

Convergence handles **absence**, never **conflict**. Three shapes it refuses rather than papers over,
each naming a different repair (`Splicewire\Beam\Schema\SchemaConflict`):

- **`type`** — the column exists with an incompatible database type.
- **`required-addition`** — a declared column is NOT NULL with no default and the table already holds
  rows, so the ALTER itself would fail.
- **`required-residue`** — a column the declaration does not know about is NOT NULL with no default, so
  the table converges cleanly and then rejects every insert this package makes. This is the shape the
  submissions fork turned out to have: not a missing column and not a type conflict, but **a different
  table wearing the same name**, disjoint in both directions. `->ignoringColumns()` exempts a column a
  host is deliberately filling itself.

**Type comparison is deliberately three-valued.** A declared type with no mapping for the current driver
is reported *unverified*, never escalated to a conflict — the same conservative posture
`MigrationOrderingAudit` takes with a dynamically-named table. A false conflict stops an install; a
missed one leaves the status quo. `ColumnTypeEquivalence` is meant to grow when a real column type goes
unverified.

**Foreign keys and primary keys are not converged** onto an existing table. The create path applies
whatever the definition declares; the converge path adds columns and plain / unique / full-text indexes
only. A primary key cannot be added after the fact on sqlite, and a foreign key added to a populated
table fails on the rows already there — both are deliberate migrations, not convergence.

**Per-column type conversion (`->change()`) is not built.** Ruled out with a trigger: build it when a
real host must upgrade *through* a type conflict. Every sighting so far needed detection plus someone
told, not conversion. The extension point is already there — `ConvergentTable` is `Macroable`, and the
report names the conflicting column — so a host that must convert can macro one on without touching the
class.

## Two things that are NOT the fix

**A publish-time filename band.** Publishing beam's creates into a `0001_01_01_*` band so they always
sort first was considered and rejected on merit, not cost (`generateMigrationName` is `protected`; the
band was ~10 lines). It is invisible magic, and it would also outrank a host's own deliberate migration
— an install nobody chose is worse than an install that fails. What a band was reaching for, the
**installer** owns explicitly instead: table ownership is a declared, defaulted answer at install time,
in the shape `BeamInstallCommand`'s numbered traps already use.

**Hand-editing a published migration's timestamp.**
[`migration-publish-ordering.convention.md`](migration-publish-ordering.convention.md) lists this as a
non-fix and gives a reason that is **false**, corrected here: *"the next `vendor:publish` undoes it"* —
it does not. package-tools' `generateMigrationName` globs `database_path('migrations/<dir>/*.php')` and
matches an existing file by **basename**, ignoring the timestamp prefix, so a hand re-dated file is
re-found and overwritten **in place, keeping its date**. What actually regresses is **greenfield**: a
fresh host has nothing to match and gets a `now()` stamp. So the defect in a hand re-date is that *the
fix is not in source* — one host is patched and the next clone is not — which is a better reason to
refuse it than the one that was written down.

## Scope

Every `create_*` a family package publishes, not only tables with a known competitor. You do not know a
competitor exists until it collides, and a maintained list of hostile vendors fails silently the first
time someone forgets an entry. A convergent guard on a table nobody else touches costs one `hasTable`
against a table that does not exist.

Out of scope: **two migrations a HOST wrote itself**. Beam has no standing to arbitrate between two of
an app's own migrations, and the two `create_media_table`s above stay the host's bug.

## Enforcement

A sixth doctor audit — one predicate over package stubs, checking for the **absence** of a guard —
is specified and **not yet built** (beam-facade ticket 30), because a conformance check flags its whole
population until that population is swept, and the estate-wide sweep of ~141 stubs is ticket 28. Until
it lands, this page is the only statement of the rule, and beam's own six shared stubs are the worked
example:

```
laravel-beam/database/migrations/shared/*.php.stub
```

`Splicewire\Beam\Tests\Schema\SharedMigrationStubsConvergeTest` runs all six twice and asserts the
second pass moves nothing — the acceptance any converted stub should be able to meet.
