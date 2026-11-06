# ADR-0241 — A host generates TypeScript through Beam's extension, and a package declares the roots no declaration reaches

**Status:** accepted — decided in ts-transformer-convergence tickets 01 and 03 (simulated hands-free 2026-10-27,
owner-overrulable), recorded by that map's docs unit D1, 2026-10-28.
**Date:** 2026-10-28
**Repo:** `splicewire/laravel-beam`
**Wayfinding:** `.scratch/splicewire/splicewire-ecosystem/ts-transformer-convergence/` (tickets 01, 03 and 05), in
`splicewire-ecosystem`.
**Builds on:** `docs/agents/particle-doctrine.md` (three declaration sites, no fourth).
**Companions:** `tests/Codegen/BeamTypeScriptTransformerExtensionTest.php`, `tests/Codegen/PackageTypeRootsTest.php`,
`tests/Codegen/DeclaredRouteTypesTest.php`, `tests/Codegen/DeclaredParticleTransformedProviderTest.php`; the doctrine
is `docs/agents/typescript-generation.md`.

## Context

Eleven hosts each hand-assembled their own `TypeScriptTransformerServiceProvider`, so every host restated the same
transformers, type replacements and package directories in its own words. The flagship's hand-kept lists rotted
silently: its provider's own comments record `UxType`, `Cadence`, `Surface\Data` and `Ia` each missing from the tree,
with no error, until someone patched a list by hand. A package cannot
fix this from its own side, because Spatie v3 configures discovery in the app's `configure()` and a package cannot
append to it (api-surface-coherence 110 `:316`). Declared reach covers only what a particle declaration names. Page
props, shared props and the response envelope have no registry, so nothing a host could walk reaches them.

## Decision

1. **Beam ships a Spatie extension; the host keeps its provider and its writer slot.**
   `src/Codegen/BeamTypeScriptTransformerExtension.php` is called first in the host's own `configure()`. The host then
   states its writer (and its output directory when that is not Spatie's default) and any commented deviations. The
   only call allowed ahead of Beam's is a commented `LaravelDataTypeScriptTransformerExtension(…)` with arguments,
   because Spatie keeps the first instance of an extension class.
2. **The five fixed parts** live inside the extension, and a host never repeats them: the declared-particle provider,
   laravel-data's extension (`DataClassTransformer`, the Laravel providers, `CarbonInterface` → `string`),
   `EnumTransformer`, `app_path()` plus every package-declared type root, and `DateTimeInterface` → `string`.
3. **The seed is declared reach.** `DeclaredParticleTransformedProvider` walks from every particle declaration
   (`DeclaredParticleTypes`) and every route-declared type (`DeclaredRouteTypes`: `->beam()->returns()`/`->streams()`,
   `#[ResponseFromData]`/`#[StreamsFromData]`), following nested Data and enum references. Every type the client
   generator references is therefore in the tree by construction.
4. **Roots no declaration reaches are declared by the owning package** in its `composer.json`
   `extra.typescript-transformer.directories`, each directory with its reason (`src/Codegen/PackageTypeRoots.php`). The
   declaration is read live from the installed package's `composer.json`, must sit inside the package's `autoload.psr-4`
   roots, and fails loudly when absent, escaping or unexplained. A package declares a root only when every installing
   host serves that population to its frontend; a population only one host's frontend consumes is that host's
   commented root (host type selection).
5. **Beam takes `spatie/laravel-typescript-transformer` in `require-dev` and `suggest`, not `require`.** Every host that
   calls the extension already installs it, because Spatie's base provider ships there; a hard requirement would put a
   `typescript:transform` command on hosts that have no transformer.

## Consequences

- A running `typescript:transform --watch` does not see a root that was just declared or removed; it needs a restart,
  or a save of the provider file.
- The key is Spatie-shaped but Beam's. If Spatie ever claims `extra.typescript-transformer`, Beam renames its key.
- A conditional root (beam-market) stays a host deviation; a package root emits at every installing host.
- Two Beam commands remain ambient-only: `verify:declared-types` and `generate:contributed-types` parse one
  `GlobalNamespaceWriter` file, so a `ModuleWriter` host leaves them out of `beam.client.assets.generators`.
- Invokable routes wait on ts-transformer-convergence ticket 07: until `RouteReturnType` reads `Class` as
  `Class@__invoke`, a route served by an invokable controller seeds nothing, and its response type reaches the tree
  only through a scanned directory.
- Unannotated `BaseData` now emits at the satellites, because `DataClassTransformer` emits every `BaseData` it scans;
  each satellite unit accounts for those additions (A3 there).

## Considered and not taken

- **A Beam base provider.** It fails the deletion test: it would add only a template method and a second inheritance
  layer for hosts to learn, while every behaviour it carried is available through the extension.
- **The copied starter file.** Today's state, and it rots: eleven hand-assembled copies.
- **`DeclaredParticleTypes` learning page props.** A page registry would be a fourth declaration site, which the
  particle doctrine forbids.
- **Host-listed `vendor/` roots.** One package root repeated on N hosts (beam `src/Data` on six satellites, accounts
  `Pages` on five starters), each copy free to drift.
- **A Beam registry for roots.** `rushing/laravel-data-nav` must not require `splicewire/*`; a composer `extra` key
  needs no dependency.
