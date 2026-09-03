# Stub publishing — convention

**Status:** canon for every host that customizes what its generated particle surfaces are born as.
**Mechanism:** the `beam-stubs` publish tag (`src/BeamServiceProvider.php`) plus
`Splicewire\Beam\Console\ParticleGeneratorCommand::resolveStubPath()`. This document states the rule;
those two document the machinery.
**Covered by:** `tests/Console/StubPublishTest.php`.

## The rule

> **A host customizes a scaffolder stub by publishing `beam-stubs` once and editing the copy in place.
> There is no flag on the generate, and not publishing is the default rather than a missing file.**

```
php artisan vendor:publish --tag=beam-stubs
```

`resolveStubPath()` prefers `base_path(<same relative path>)` and falls back to the package copy —
Laravel's own `stub:publish` convention. So the round trip is: publish, edit
`stubs/particle-resource.stub`, run `splicewire:beam:make:particle-resource Article`, and the emitted
file carries your edit.

## What the tag writes

Six generator stubs, one per thing a `make:particle-*` command can emit:

| stub | read by |
| --- | --- |
| `particle-resource.stub` | `splicewire:beam:make:particle-resource` — the `#[ParticleResource]` read Data class |
| `particle-resource-input.stub` | the same command's write-DTO companion |
| `particle-op.stub` | `splicewire:beam:make:particle-op` — Read and Write kinds |
| `particle-op-task.stub` | the same command, `--kind=task` |
| `particle-op-stream.stub` | the same command, `--kind=stream` |
| `particle-data.stub` | the same command's input/output Data companions |

⚠️ **The tag maps a DIRECTORY, not a file list**, so it also deposits `stubs/client-runtime/{api,routes}.ts`
and `stubs/scribe/scribe.php`. Those two have their own tags — `beam-client-runtime` and `beam-scribe` —
which publish to the destinations that are actually *read* (`resource_path('js/lib/')` and
`config_path('scribe.php')`). **The copies `beam-stubs` leaves under `stubs/` are inert.** Publishing
`beam-stubs` is not a way to get either of them wired; publishing them and then also publishing
`beam-stubs` leaves a second, unread copy on disk that will drift.

## Why it is not an install step

`splicewire:beam:install` deliberately does not publish this tag (the provider's comment is the
authority): *an unpublished stub is not a missing file, it is the default.* A host that never
customizes should carry no copy — a published stub is a snapshot that stops tracking the package, and
the estate has enough of those. `beam-scribe` is an install step for the opposite reason: a fresh host
cannot generate a spec worth reading from Scribe's stock config (ADR-0211 §7).

For the same reason there is **no doctor finding for an unpublished stub**. Absence is correct here,
which is the inverse of `ClientRuntimeContractAudit`, whose tag names a module the generated client
genuinely imports.

## Republishing — read the estate rule, do not re-derive it

A published stub is a **snapshot**, and everything the estate knows about stale snapshots applies
unchanged: byte-compare the published copy against the package file before republishing, and never
assume a diff means staleness — a changed shape can be a deliberate host variant that a
`vendor:publish --force` will overwrite. That reasoning, the tower `personal_access_tokens` worked
example, and the `glob()` first-match hazard are stated once, in
`~/Workspaces/splicewire-ecosystem/AGENTS.md` under *"A migration error at a host is usually a stale
snapshot"*. Read it there rather than a restatement here.

One narrowing that is specific to this tag: `vendor:publish` **never overwrites an existing file**
without `--force`, so re-running `--tag=beam-stubs` on a host that has edited its stubs is a no-op and
is safe. `--force` is what discards the host's edits, and there is no first-match ambiguity to worry
about — unlike migrations, a stub's destination path is fixed rather than timestamp-globbed.
