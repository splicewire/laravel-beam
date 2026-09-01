---
library: spatie/typescript-transformer
tier: primary
version-read: 3.3.0
arrives: declared
docs: https://spatie.be/docs/typescript-transformer — the package ships NO docs/ tree; `vendor/spatie/typescript-transformer/src/` is the on-disk authority
overlay: splicewire/laravel-beam — `src/Codegen/`, `src/Surgeon/`
config: none — beam is a package; the host owns the transformer's service-provider wiring
related: [spatie.laravel-data.md, particle-doctrine]
date: 2026-08-27
---

# spatie/typescript-transformer

## What it is here

The **declaration vocabulary and the emitted artifact** beam's codegen is built around — not a runtime
beam drives. Beam declares the framework-agnostic core (`^3.0`, resolving **3.3.0**), never
`spatie/laravel-typescript-transformer`: the host owns the provider, the discovery config and the
`typescript:transform` command. Beam only *annotates* — `#[TypeScript]` on the Data classes in each
domain's own `Data/` directory (`src/Surface/`, `src/Webhooks/`, `src/Intake/`, `src/Discovery/`,
plus `src/Data/` itself). Domain-nested is the rule rather than a list to maintain: directories come
and go with the domains, and there is no `src/Rendering/Data/` any more — this doc named one until
its DTOs left beam. And
then *reads the result back* — `src/Codegen/AmbientTypeIndex.php` parses what the global-namespace
writer emitted, `src/Codegen/ContributedTypesGenerator.php` intersects it, and two `src/Surgeon/`
audits encode its failure modes. So the surface that matters is the **attributes**, the **writer's
output shape**, and the **discovery seam**; the machinery between them is the host's problem — until
it degrades silently (see Traps).

**There is no vendored docs tree.** The README is a two-page pitch and `UPGRADE.md` is the closest
thing to a v2→v3 map. Read `src/` — the class names below *are* the concept index.

## Concept index

A directory in the heading, a filename in the cell.

### Attributes — `vendor/spatie/typescript-transformer/src/Attributes/`

The only part of this library beam writes against directly.

| concept | what it's for | read |
| --- | --- | --- |
| `#[TypeScript]` | mark a class/enum for transform; optional `name:` / `location:` override | `TypeScript.php` |
| literal type | hand-written TS for one property, escaping inference | `LiteralTypeScriptType.php` |
| declared type | substitute a PHP type for the inferred one | `TypeScriptType.php` |
| hide / optional | drop a property, or emit it as `?:` | `Hidden.php`, `Optional.php` |
| extra import | pull a symbol the emitted file needs | `AdditionalImport.php` |
| your own attribute | **v3 seam** — the contract a custom type attribute implements | `TypeScriptTypeAttributeContract.php` |

### Transformers — `vendor/spatie/typescript-transformer/src/Transformers/`

v3 folded the old *collector* concept in here: a transformer decides *whether* it handles a type.

| concept | what it's for | read |
| --- | --- | --- |
| the contract | returns `Transformed` or `Untransformable` | `Transformer.php` |
| attribute-driven | the default — transform what carries `#[TypeScript]` | `AttributedClassTransformer.php` |
| class / interface / enum | the three built-in shapes | `ClassTransformer.php`, `InterfaceTransformer.php`, `EnumTransformer.php` |

### The result and its links — `vendor/spatie/typescript-transformer/src/`

| concept | what it's for | read |
| --- | --- | --- |
| a transformed type | name, location, node, and the references it needs | `Transformed/Transformed.php` |
| the negative | how a transformer declines | `Transformed/Untransformable.php` |
| references | how one emitted type points at another before writing | `References/Reference.php`, `References/PhpClassReference.php` |
| discovery + providers | where types come from, incl. watch mode | `TransformedProviders/TransformedProvider.php`, `TransformedProviders/ConfigAwareTransformedProvider.php` |

### The emitted tree — `vendor/spatie/typescript-transformer/src/TypeScriptNodes/`

| concept | what it's for | read |
| --- | --- | --- |
| the node base | everything emitted is one of these | `TypeScriptNode.php` |
| alias / object / property | the `export type X = { … }` shape beam's index parses | `TypeScriptAlias.php`, `TypeScriptObject.php`, `TypeScriptProperty.php` |
| raw / reference | escape hatch, and a link to another node | `TypeScriptRaw.php`, `TypeScriptReference.php` |

### Writing, and driving a run — `vendor/spatie/typescript-transformer/src/`

Host-tier; read when a host's generation misbehaves, not to wire beam.

| concept | what it's for | read |
| --- | --- | --- |
| namespaced `.d.ts` | **the shape beam assumes** — `declare namespace A.B.C { … }` | `Writers/GlobalNamespaceWriter.php` |
| module writers | the per-file alternatives | `Writers/ModuleWriter.php`, `Writers/FlatModuleWriter.php` |
| formatting | prettier/eslint post-pass over the written files | `Formatters/PrettierFormatter.php` |
| entry point + config | v3 configures in a service provider, **not a config file** | `TypeScriptTransformer.php`, `TypeScriptTransformerConfigFactory.php` |
| the pipeline | discover → transform → connect → write | `Actions/DiscoverTypesAction.php`, `Actions/TransformTypesAction.php`, `Actions/ConnectReferencesAction.php`, `Actions/WriteFilesAction.php` |
| type inference | PHPStan-based; **where a docblock silently degrades** | `Actions/TranspilePhpStanTypeToTypeScriptNodeAction.php` |
| post-processing | array-like fixups, and node-tree mutation | `ClassPropertyProcessors/FixArrayLikeStructuresClassPropertyProcessor.php`, `Visitor/Visitor.php` |
| watch mode | v3 addition — transformers may run repeatedly in one process | `FileSystemWatcher.php` |
| v2 → v3 | complete rewrite; the migration notes | `vendor/spatie/typescript-transformer/UPGRADE.md` |

## House overlay

**The generated type is downstream of the particle declaration, not a parallel one.** A Data class's
`#[TypeScript]` and its `#[ParticleResource]` / `#[ParticleOp]` describe the same shape; the doctrine
governs which is authoritative — `docs/agents/particle-doctrine.md`, and the substrate side in
`docs/agents/libraries/spatie.laravel-data.md`.

**Beam reads the emitted tree back.** `src/Codegen/AmbientTypeIndex.php` indexes what a `.d.ts`
actually declares so a derived type cannot dangle; `src/Codegen/ContributedTypesGenerator.php` emits
resource-keyed intersections for contribution slices, because a slice rides laravel-data's
`additional()` and **the transformer cannot see those keys**
(`src/Particle/Contribution/ContributionProjector.php`). `src/Codegen/TsClientGenerator.php` and
`src/Codegen/SplicewireClientGenerator.php` generate from the OpenAPI spec, not from the transformer —
siblings, not a chain. Two `surgeon:audit` checks encode its failure modes:
`src/Surgeon/TypeScriptUnknownResolutionAudit.php`, `src/Surgeon/TypeScriptShortNameCollisionAudit.php`.

## Traps

**1. Host-rooted discovery skips a package — this estate's most-repeated defect.**
`auto_discover_types` defaults to the **host's** `app_path()`, so a package's `#[TypeScript]` classes
emit nothing. Measured, not theorized: splicewire-app's regenerated `generated.d.ts` (2044 lines)
contained **zero** frame classes — no `ResourceDefinition`, no `NavMetadata`, no `RealmDefinition`
(`particle-contribution-seam` ticket 10 §A5). `AmbientTypeIndex`'s own docblock counts it as the
**third** mechanism with the identical shape, after `config('frame.discover_paths')` (ticket 07).
Fix without publishing config: a package provider appends its own `src/` to
`config('typescript-transformer.auto_discover_types')` at `packageBooted()` (ticket 12).

**2. An unresolvable docblock element type degrades to `unknown` instead of erroring.**
A bare `@var SomeClass[]` naming a class outside the file's own namespace/imports — routine when a
lower-tier DTO legitimately cannot `use`-import a higher-tier class — yields `unknown[]` in
`generated.d.ts`, silently. `surgeon-audit-viability` ticket 37 turned that residue into a check
(`src/Surgeon/TypeScriptUnknownResolutionAudit.php`); the fix is a fully-qualified docblock reference,
which crosses no dependency boundary because it is comment text.

**3. `name:` / `location:` overrides are now the *only* way to collide.** After
`surgeon-audit-viability` ticket 34 every class emits at its real native namespace — collision-proof
by construction, since PHP FQNs are unique. Before that, five same-short-name pairs across packages
(`ThreadMessageData`, `CreateInvitationData`, `MembershipResourceData`, `TokenResourceData`,
`PlanData`) double-emitted into a downstream babel parse crash. Reaching for an override reopens the
one door that was closed — `src/Surgeon/TypeScriptShortNameCollisionAudit.php` is what watches it.

**4. "We omit `#[TypeScript]` to avoid forcing the dependency" is a dead rationale.**
`beam-docs-satellite` ticket 24 found beam-ux carrying exactly that docblock while hard-requiring beam
core, which requires it — sole holdout (core 7, accounts 12, workflows 13, commerce 24, tower 155),
and the only package missing from a host tree already carrying eight vendor namespaces. Separately,
`beam-facade` ticket 129 found `splicewire/laravel-beam-versioning` requiring the package but with it
absent from its own `vendor/` — declared is not installed.
