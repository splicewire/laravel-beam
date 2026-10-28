# TypeScript generation — every type a frontend reads comes from one run

A host's TypeScript is **generated from declarations, never written**. Each host has one
`app/Providers/TypeScriptTransformerServiceProvider.php` on Spatie's base. It calls
`BeamTypeScriptTransformerExtension` first and then names its writer, and every Data class or enum a frontend reads comes
out of that run. ADR-0241 records why.

## The shape

```php
final class TypeScriptTransformerServiceProvider extends TypeScriptTransformerApplicationServiceProvider
{
    protected function configure(TypeScriptTransformerConfigFactory $config): void
    {
        $config
            ->extension(new BeamTypeScriptTransformerExtension)
            ->writer(new ModuleWriter(path: null));
    }
}
```

**Fixed** — inside the extension (`src/Codegen/BeamTypeScriptTransformerExtension.php:37-45`); a host never repeats them:
1. `DeclaredParticleTransformedProvider`, seeded by `DeclaredParticleTypes` and `DeclaredRouteTypes` — the host's
   **declared reach**;
2. `LaravelDataTypeScriptTransformerExtension` (`DataClassTransformer`, the laravel-data providers,
   `CarbonInterface` → `string`);
3. `EnumTransformer`;
4. `app_path()` plus every package-declared type root;
5. `\DateTimeInterface` → `string`.

**Slot** — every host states its writer, plus its output directory when that is not Spatie's `resources/js/generated`.
`ModuleWriter(path: null)` at the default directory needs no comment. Any other writer or directory carries a one-line
`//` comment saying why.

**Deviation** — any other call, directly under a one-line `//` comment. Calls come in this order:
1. optionally, `LaravelDataTypeScriptTransformerExtension(customLazyTypes: …, customDataCollections: …)`. It is the only
   call allowed before Beam's, because Spatie keeps the *first* instance of an extension class;
2. `BeamTypeScriptTransformerExtension`;
3. the writer slot;
4. the deviations.

A host file **reads as** the shape when it holds those calls in that order and repeats no fixed part. That includes no
`AttributedClassTransformer`, and no `base_path('vendor/<p>/<dir>')` that `<p>` declares. PHP syntax
(`declare(strict_types=1)`, `final`) follows the host's written style policy, or else its current file. The flagship
forbids both.

`configure()` never calls `configPath()`. The factory *replaces* that list, and the base provider has already set it to
this file, which is the file `--watch` restarts on.

## Choosing the writer

| writer | hosts | the frontend reads | Beam commands |
| --- | --- | --- | --- |
| `ModuleWriter(path: null)` | starters | `import type { X } from '@/generated/Vendor/Pkg/Data'` | all except `verify:declared-types` and `generate:contributed-types`, which parse one ambient file; leave them out of `beam.client.assets.generators` |
| `GlobalNamespaceWriter('generated.d.ts')` | satellites, the flagship | ambient `Vendor.Pkg.Data.X` | all |

At an ambient host, `beam.client.types.dir` (or `BEAM_CLIENT_TYPES_DIR`) names the provider's output directory. Beam does
not derive it, and the default is `resources/js/types`. A writer change rewrites every import or ambient name the
frontend holds, so it carries its own declaration-name census.

## How a type reaches the tree

Reach for these in order:

1. **Declared reach.** Every particle SHAPE slot and every route-declared type seeds a walk through the Data classes and
   enums it references. Route-declared types come from `->beam()->returns()`/`->streams()` and from
   `#[ResponseFromData]`/`#[StreamsFromData]`. A type reached this way needs no directory: declare the shape, and its
   types follow.
2. **`app_path()`.** Every `BaseData` and enum under it emits. No attribute selects.
3. **A package-declared type root**, for a population that no declaration names: Inertia page props, shared props, the
   envelope. The owning package writes it in its own `composer.json`:

   ```json
   "extra": { "typescript-transformer": { "directories": {
       "src/Data/Pages": "Inertia page props that host controllers render; no particle declaration names them"
   } } }
   ```

   `PackageTypeRoots` reads each installed package's live `composer.json`, so an overlay edit is seen without
   `composer update`. It orders by package name. It fails loudly on a directory that is absent, outside the package's
   psr-4 roots, or escaping the package, and on an empty reason. Declare a root only when **every** installing host
   serves that population to its frontend, because a declared root emits at every host.
4. **Host type selection.** A population only this host's frontend consumes is listed by the host itself, as a
   deviation: one commented `transformDirectories` call per root or group, with the reason in the comment, and never a
   package declaration. The flagship's are the worked set (ts-transformer-convergence ticket 03 Q11):
   - its `splicewire/*` estate scan;
   - beam-ux `Style`, `Bindings` and `Components`;
   - prism-plus `Data` and music-spine `src`;
   - rushing/laravel-commerce `Enums`.

   One flagship root here is **temporary**: beam `src/Ia`. It stands in for the declared reach that invokable routes
   cannot give yet, and it retires when ticket 07 closes.

A running `typescript:transform --watch` does not see a root that was just declared or removed. Restart it, or save the
provider file. Edits *inside* a declared directory are seen live.

The client generator names only types the tree emits. Every type it references is a route-declared type, which declared
reach seeds, so its references resolve by construction.

One gap remains. A route served by an invokable controller (`Route::get('/x', XController::class)`) is invisible to every
route reader until `RouteReturnType` reads `Class` as `Class@__invoke` (ts-transformer-convergence ticket 07). Its
response type reaches the tree only through a scanned directory.

## What never becomes a type

Each line names the target first.

- **A content file is content, not a type source.** Content, docs, legal and ToS pages are MDX, and a page's file is
  never a type source: no PHP mirror of a page, no `#[TypeScript]` for one, no root over content files. The root half is
  mechanical: `.mdx` is never autoloaded PHP, so no package can declare it.
  - A build-time MDX import is typed by the `*.mdx` module declaration (the runbook's `references/content-pages.md` §3).
  - Shapes declared *for* pages are ordinary Data and reach the tree by the routes above: beam-ux Page Fields (a Data
    class the parent page names; beam-ux ADR-0233 decision 4), block contracts, and the editor's entry DTOs.
- **Import the generated type.** A hand-written TS interface that restates a Data class drifts from it. A reader over
  the generated type, such as a host's `ApiResponse.ts` over `Splicewire.Beam.Data.ResponseBody`, is the sanctioned
  shape.
- **Fix the source, not the output.** The tree, its manifest and the client files are written by generators. Fix the
  Data class, its declaration or the provider.
- **Fix a missing type in its own lane.** A type that will not resolve goes in the lane it belongs to:
  - when every installing host serves the population, the owning package's `extra` (route 3);
  - when a declaration should name it, a declared shape (route 1);
  - when only this host's frontend consumes it, a commented host root (route 4).

  A host-listed `base_path('vendor/…')` for a population of the first two kinds is the drift this shape retires: it
  repeats a package's own root on every host, or it scans what declared reach already walks.
- **Leave names native.** A `name:` or `location:` override on `#[TypeScript]` is the one way left to collide two types
  (`src/Surgeon/TypeScriptShortNameCollisionAudit.php`).
- **Ignore, don't format.** Generated files are not formatted:
  - an ambient host's `.prettierignore` lists `<dir>/generated.d.ts` and `<dir>/typescript-transformer-manifest.json`;
  - a starter gitignores its module tree, one line per top-level namespace.

## Traps

1. **An unreached reference becomes `undefined`, not an error.** The run logs "… not found in the transformed types"
   (`vendor/spatie/typescript-transformer/src/Actions/ConnectReferencesAction.php:50`) and writes `undefined`
   (`TypeScriptNodes/TypeScriptReference.php:35-36`), so the member compiles as always empty. Read the run's output every
   time. The starters' `TypeScriptGenerationTest` asserts that the line is absent.
2. **laravel-data's `#[Hidden]` alone keeps the member typed.** The member drops only when both laravel-data's and the
   transformer's `Hidden` are present
   (`vendor/spatie/laravel-typescript-transformer/src/LaravelData/ClassPropertyProcessors/DataClassPropertyProcessor.php:50`).
3. **Types use output names.** A member is named by its output mapper: the property's attribute, then the class's, then
   `data.name_mapping_strategy.output` (same file, `:91-103`). Where a host's input mapper differs, a request reads
   other names, and a generated type does not describe what a request sends.
4. **Every `DateTimeInterface` is `string`.** The extension's one `replaceType` covers DateTime, Carbon and their
   immutables (by `is_a`), matching laravel-data's default `DateTimeInterfaceTransformer`. That `replaceType` is a fixed
   part, so a host never repeats or overrides it with its own date `replaceType`. A host that serializes dates as
   anything but a string has no lane in this shape yet; raise it as its own decision before changing the provider.
5. **The manifest hides stale output.** `typescript:transform` skips a file whose hash its manifest holds, and it deletes
   only the files the manifest lists. A green run with an unchanged tree is not evidence of a regeneration.

## Proving a change

Prove a provider change, a declared root or a Beam codegen change in a worktree, never in a live checkout:
1. Move the manifest aside and regenerate every generator the host commits. Before any compile, move every previously
   owned path that the run did not emit out of the tree.
2. Diff the **generation snapshot** (the generator-owned files after that manifest-less run) before and after. Class
   every hunk as format-only, order-only, additive, a dialect change with its consumer edits, a writer or path change, or
   a stop. An **accounted change** is a hunk in any class but a stop.
3. Compile the tree strict with lib checking on, then the frontend's own `tsc`. Compare diagnostics by identity, before
   and after.
4. A red is a **baseline red** only when it reproduces at the base, in the same worktree and supply.
5. A base-regeneration hunk that traces to a supply package's commit at or below its pin is **supply drift**. Name it;
   it does not fail the change.
6. `verify:declared-types` exits 1 while any declared type it checks is missing from the tree, by design. That exit is
   a coverage reading, not a generator failure: the change holds when the missing set does not grow, and every other
   generator still exits 0.

The paste-ready recipe, its classes (A0–A4, A1b, S1–S7) and its host table are ts-transformer-convergence ticket 04 in
`splicewire-ecosystem`.
