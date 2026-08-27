---
library: nette/php-generator
tier: primary
version-read: v4.2.2
arrives: declared
docs: https://doc.nette.org/en/php-generator
related: [spatie.laravel-data]
date: 2026-08-27
---

# nette/php-generator

## What it is here

**Beam generates PHP source**, and this is the thing that writes it. Two codegen drivers compose
`PhpFile` + `PsrPrinter` + `Literal` into a builder: `src/Codegen/SaloonConnectorGenerator.php` emits
the flat self-client (`App\Generated\Saloon` — a connector plus one Request class per operation), and
`src/Codegen/SplicewireClientGenerator.php` emits the domain-namespaced, per-field-typed
`Splicewire\Client` SDK (Requests, Resources, and thin `Data/*` adapters). Both are
`rushing/codegen` generators driven off the *same* canonical model the TypeScript client consumes —
the syntactic builder deliberately lives **inside the driver**, never in the shared semantic model,
so a second driver in another language would bring its own emitter. Beam drives roughly twenty of the
library's types across `ClassType`, `Method`, `PromotedParameter`, `Property`, `PhpNamespace` and the
printer; that is what makes it primary here rather than a helper.

**It is primary in beam and essentially nowhere else.** Family-wide it is **1.7% direct share** —
beam is its only real declarer. That is the whole point of the convention's admission rule: share is
a *host-tier* instrument, and at package tier the declaration plus "do you construct, configure,
extend or implement its types" is what decides. Contrast `symfony/yaml` at ~90% resolved share, which
is three static calls on one class and primary nowhere.

Not everything beam emits comes from here: the particle scaffolders (`src/Console/ParticleGeneratorCommand.php`,
`MakeParticleResourceCommand.php`, `MakeParticleOpCommand.php`) are Laravel `GeneratorCommand` + stubs,
and `src/Console/GenerateClientSdkCommand.php` is the TypeScript leg. Reach for php-generator when the
output is *derived from a model*, not templated. Declared `^3.4|^4.0`, resolving **v4.2.2**.

## Concept index

Paths below are a directory in the heading, a filename in the cell.

### Prose — `vendor/nette/php-generator/`

One file, ~1000 lines, sectioned — the only prose shipped on disk, same text as the site. Read the
named section of `readme.md`: *Classes* / *Interfaces or Traits* / *Enums* (the declaration model),
*Method and Function Signatures* (parameters, promotion, types), *Method and Function Bodies*
(`setBody()` and the `?`/`...` placeholder interpolation beam does **not** use), *Printer and PSR
Compliance*, *Literals*, *Namespace* + *Class Names Resolving* (`addUse()` and FQN shortening),
*PHP Files* (`strict_types`), *Loading from PHP Files* (unused here).

### The type model — `vendor/nette/php-generator/src/PhpGenerator/`

| concept | what it's for | read |
| --- | --- | --- |
| file | the outermost node; `printFile()` takes this | `PhpFile.php` |
| namespace | `addUse()`, `addClass()`; owns import resolution | `PhpNamespace.php` |
| class | `setExtends`, `addImplement`, `addTrait`, `addComment` | `ClassType.php` |
| shared class surface | what `ClassType`/`InterfaceType`/`TraitType`/`EnumType` have in common | `ClassLike.php` |
| method | `setBody`, `setReturnType`, `setStatic`, `addParameter` | `Method.php` |
| promoted ctor param | `addPromotedParameter()` — the shape both beam drivers emit | `PromotedParameter.php` |
| parameter | type, default, nullability | `Parameter.php` |
| property | `setType`, `setValue`, visibility | `Property.php` |
| **literal** | a value printed as-is (`new Literal("Method::POST")`) instead of quoted | `Literal.php` |
| printer | rendering rules; the base class to subclass for house style | `Printer.php` |
| PSR printer | the PSR-12 preset beam instantiates | `PsrPrinter.php` |
| type helpers | `Type::union()`, nullable/intersection spelling | `Type.php` |
| attributes | emitting `#[...]` on any node | `Attribute.php` |
| value dumping | how non-`Literal` PHP values become source | `Dumper.php` |
| ingestion | build a node from reflection, or parse a real file back into the model | `Factory.php`, `Extractor.php` |
| structural edits | add/remove members on a built class | `ClassManipulator.php` |
| 8.4 property hooks | available, unused in beam | `PropertyHook.php` |

### Where beam drives it — `src/Codegen/`

| concept | what it's for | read |
| --- | --- | --- |
| flat self-client | the smallest complete example — ~200 lines, whole builder visible | `SaloonConnectorGenerator.php` |
| published SDK | domains, typed body fields, `Data/*` adapters, reserved-name guard | `SplicewireClientGenerator.php` |
| naming | path+verb → class/method name; convention-only, no overrides | `SdkNaming.php` |
| the TS sibling | the other driver off the same model — **no php-generator** | `TsClientGenerator.php` |

Generator output is asserted as printed source in `tests/Codegen/SaloonConnectorGeneratorTest.php`
and `tests/Codegen/SplicewireClientGeneratorTest.php`.

## House overlay

**`PsrPrinter`, always; no custom `Printer` subclass.** Both drivers do `new PsrPrinter` and print
whole files. House style on *generated* PHP is not enforced by `surgeon:house-style` — the emitter is.

**Emit the marker.** Generated files carry an `addComment('AUTO-GENERATED … DO NOT EDIT BY HAND.')`
and, where useful, the source route name.

**`Literal` for enum cases and expressions, `var_export()` for scalars.** `setValue(new Literal("Method::{$verb}"))`
is the load-bearing use — a plain string would be quoted and the generated class would not compile.

**Names are convention, not configuration.** Class and method names come from `SdkNaming.php`; the
per-route hints in the host's codegen options carry only structural escape hatches. Do not add a
name-override key — that was removed deliberately.

**The generated SDK's shape is documented host-side** in `config/beam/client.php`; the model it reads
is built from the route manifest (`src/Codegen/RouteManifestModelSource.php`).

## Traps

**1. php-generator checks syntax, never semantics — the fatal shows up at class-load, in the host.**
`SaloonConnectorGenerator` emitted a promoted `public array $body` on every write Request. It printed
cleanly, and every one of the **341 regenerated files** fataled on load: Saloon's `HasJsonBody` trait
already declares `$body`, so PHP rejected the redeclaration as "defined incompatibly". Fixed by
emitting `$payload` plus a `defaultBody()` that returns it (beam `51e6c2b`; diagnosed in
`~/Workspaces/splicewire-ecosystem/.scratch/splicewire/splicewire-app/splicewire-recohere/SESSION-8-LEDGER.md`).
The ledger's note is the lesson: *"regenerating alone would NOT have helped — the generator itself
was wrong."* If you add a promoted parameter or property, check the emitted class's **base class and
traits** for that name.

**2. The same trap generalizes to any name derived from wire data.** A body field camelCasing onto a
Saloon base-`Request` property (`config`, `headers`, `query`, `method`, `connector`, `middleware`,
`response`, `body`) fatally redeclares it. The guard is `SALOON_RESERVED` at
`src/Codegen/SplicewireClientGenerator.php:50`, which suffixes the PHP name (`config` → `configField`)
while leaving the wire key intact (`f4a95e9`, client-sdk-regen #08). A new emission target needs its
own reserved list; there is no generic one.

**3. The Saloon classes beam imports are emitted, not used — do not "clean up" the imports.** Both
drivers top-level-import six Saloon types beam does not depend on, purely to write `Method::class` /
`Request::class` into `addUse()` and `setExtends()`. Inert: a `use` is compile-time aliasing and PHP
never autoloads on it. The audit in
`~/Workspaces/splicewire-ecosystem/.scratch/splicewire/splicewire-app/api-surface-coherence/issues/87-a-package-cannot-load-its-own-model.md`
measured exactly this case and ruled it safe — *"`SaloonConnectorGenerator` and
`SplicewireClientGenerator` only ever emit them as strings"* — while an absent class in an `extends`,
`implements`, or in-body trait `use` is a real, uncatchable fatal.
