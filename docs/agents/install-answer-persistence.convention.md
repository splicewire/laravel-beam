# What `beam:install` persists — convention

**Status:** canon for `splicewire:beam:install` and for any package registering an install step that
wants an answer to survive the process.
**Decided:** 2026-08-27 (beam-facade ticket 158), which is also the first time this contract was
written down anywhere in the estate.
**Mechanism:** `Splicewire\Beam\Console\BeamInstallCommand::persistConfig()`. This page states the
rules; the method documents the machinery.

## The contract

The wizard's answers reach the running process through `config([...])` immediately, and reach **disk**
only through `persistConfig()`. Those are two different lifetimes and the difference is the whole of
this page.

| answer | option | config key | written to disk |
| --- | --- | --- | --- |
| table prefix | `--prefix=` | `beam.core.table_prefix` | yes |
| schema sources | `--schema-sources=` | `beam.core.schema.sources` | yes |
| tenancy mode | `--tenancy=` | `beam.core.tenancy` | yes, **since 2026-08-27** |

Three conditions, all required, or nothing is written:

1. **`config/beam/core.php` exists** — the host has published the config.
2. **It is writable.**
3. **`--force` was passed.** Safe-unless-force, matching `vendor:publish`: an already-published file
   may carry hand edits, so overwriting it is an explicit act. Without `--force` the answers still
   govern *this run*; they are simply not persisted, and the command says so.

A failure of any condition is a **warning, never a fatal**. Install is not the place to refuse.

## An answer that is accepted and not written is the failure mode this page exists for

⚠️ **`tenancy` was accepted and dropped for the whole life of the command.** It was a parameter of
`persistConfig()`, it participated in the early-return guard, and it appeared **nowhere else** — so an
operator answering the tenancy question on an already-installed host had the answer govern the run and
die with the process. `--force` did not help; there was no branch to reach.

Nothing reported it, for a reason worth generalising: **`beam.core.tenancy` is read in exactly one
place in the entire estate** — this command's own `verifySharedMigrations()`. A config key with one
consumer, and that consumer inside the command that sets it, is a key whose persistence nobody can
observe from outside. **When you add an answer here, name its consumers; an answer with no consumer
outside the wizard cannot be caught by anything except a test of the writer itself.**

## ⚠️ The fixture is half of the regression test

`replaceScalar()` is a `preg_replace` over `'<key>' => '<value>'`. On a key **absent** from the file it
matches nothing, returns the contents unchanged, and reports no error.

So a test whose fixture omits the key **passes against a writer that wrote nothing**. That is exactly
how the tenancy defect survived two existing `persistConfig` tests: both fixtures were
`['table_prefix' => …, 'schema' => ['sources' => …]]`, with no `'tenancy'` key for the assertion to be
wrong about.

**Any key added to this method needs that key present in the test fixture**, and the test must be
verified to fail before the fix. `tests/Install/BeamInstallTest.php::publishedConfig()` exists to make
that hard to get wrong.

A second, quieter version of the same trap: assert against a value the **shipped config's own default**
already carries and the assertion passes whether or not the writer ran. The regression test answers
`multi` precisely because the shipped default is `single`.

## Adding an answer

1. Add the option and the prompt.
2. `config([...])` it, so it governs the run.
3. Write it in `persistConfig()`, behind the same three conditions — do not invent a fourth posture for
   one key.
4. Add the key to the test fixture, and write the assertion so it fails without step 3.
5. Add the row to the table above.
