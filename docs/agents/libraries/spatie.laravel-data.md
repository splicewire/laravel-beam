---
library: spatie/laravel-data
tier: primary
version-read: 4.23.0
arrives: declared
docs: vendor/spatie/laravel-data/docs
config: none — beam is a package; the host owns config/data.php
related: [particle-doctrine]
date: 2026-08-25
---

# spatie/laravel-data

## What it is here

The substrate the particle doctrine is built on. Beam does not merely *use* laravel-data — it
**extends** it, through one deliberate intermediary: `Splicewire\Beam\Data\Data` →
`Schemastud\DataSchemas\StudData` → `Spatie\LaravelData\Data`. The middle link is not incidental —
parenting on `StudData` is what makes all 85 DTOs below it answer `::jsonSchema()` through the
host's *configured* generator instead of a bare `new JsonSchemaGenerator`. Beam owns the
`#[ParticleResource]` / `#[ParticleOp]` attributes, and the registries, backing layer, Scribe
strategies and TypeScript codegen all read Data classes as their input. When beam adds a mechanism,
the first question is whether laravel-data already has it. **Three times it did, and beam shipped a
hand-rolled version anyway** — see Traps, which is the reason this file exists.

Declared at `^4.0|^5.0`, resolving **4.23.0**. That constraint spans two majors, which is a
package-tier obligation the hosts do not carry.

**Read the shipped docs.** 57 markdown files under `vendor/spatie/laravel-data/docs/`. Everything
below routes into them.

## Concept index

Paths below are a directory in the heading, a filename in the cell.

### Extension points — `vendor/spatie/laravel-data/docs/advanced-usage/`

Start with **`in-packages.md`** — beam is a package, and the rules differ from a host's.

| concept | what it's for | read |
| --- | --- | --- |
| in packages | **shipping DTOs from a package** | `in-packages.md` |
| pipeline | the stages `from()` runs through; the supported hook for odd input | `pipeline.md` |
| normalizers | teaching `from()` an input shape it doesn't know | `normalizers.md` |
| custom cast | input-side type conversion | `creating-a-cast.md` |
| custom transformer | output-side conversion | `creating-a-transformer.md` |
| rule inferrers | deriving validation from a property type | `creating-a-rule-inferrer.md` |
| traits & interfaces | the seams a package may implement | `traits-and-interfaces.md` |
| internal structures | what the resolver actually builds | `internal-structures.md` |
| TypeScript | how a DTO becomes a generated type | `typescript.md` |
| performance | structure caching, and what it costs a package | `performance.md` |

### Construction — `vendor/spatie/laravel-data/docs/as-a-data-transfer-object/`

The half beam kept reimplementing.

| concept | what it's for | read |
| --- | --- | --- |
| **magical creation** | `from()` dispatching to any `public static from*()` by argument type | `creating-a-data-object.md` |
| from a model | `fromModel`, and what it maps without help | `model-to-data-object.md` |
| factories | `Data::factory()` for construction-time options | `factories.md` |
| injecting values | `InjectsPropertyValue` — a **public** interface, no host wiring | `injecting-property-values.md` |
| casts / defaults / computed | the declarative alternatives to a closure | `casts.md`, `defaults.md`, `computed.md` |
| `Optional` | absent, which is not null | `optional-properties.md` |

### Output and validation

| concept | what it's for | read |
| --- | --- | --- |
| appending | `AppendableData`, adding keys outside the constructor | `vendor/spatie/laravel-data/docs/as-a-resource/appending-properties.md` |
| lazy properties | computed only when the caller includes it | `vendor/spatie/laravel-data/docs/as-a-resource/lazy-properties.md` |
| validation | rules inferred from types, and when to override | `vendor/spatie/laravel-data/docs/validation/` |

## House overlay

**The doctrine is beam's own, and it is stricter than the library.** Three legal declaration sites,
no fourth: `#[ParticleResource]` and `#[ParticleOp]` (`src/Particle/Attributes/`), plus the
`#[ResponseFromData]` / `#[RequestFromData]` / `#[StreamsFromData]` / `#[QueryFromData]` set that
lives in `vendor/rushing/laravel-data-schemas-scribe/src/Attributes/` for non-particle surfaces.
Full statement: `docs/agents/particle-doctrine.md`.

**Extend `Splicewire\Beam\Data\BeamData`, not spatie's.** `src/Data/BeamData.php` adds the response
helpers and sits in beam-base so downstream packages reach it by a legal DOWN edge — a sibling
package reaching UP for a shared DTO parent is the thing it exists to prevent. It was called `Data`
until the 15-root sweep renamed it: the short name collided with `Spatie\LaravelData\Data`, so
anything wanting both had to alias, and the estate had already invented `BeamData` as that alias in
two packages before the rename made it real.

**Don't hand-write the attributes.** `MakeParticleResourceCommand` / `MakeParticleOpCommand`
(`src/Console/`) emit every slot plus the route mount line.

**A Data class is an input to four generators**, not just a response shape: the registries
(`src/Particle/`), the backing resolver (`src/Particle/Backing/`), the Scribe strategies
(`src/Scribe/Strategies/`), and codegen (`src/Codegen/` — TS client and Saloon connectors). A DTO
change ripples through all four.

**Parameter prose goes on the DTO property** — `docs/agents/api-parameter-documentation.convention.md`.

## Traps

**1. Check whether laravel-data already does it. Beam has got this wrong three times.**
`particle-contribution-seam` ticket 12 (closed 2026-08-22) found `project:` to be a hand-written
reimplementation of **magical creation** — `ParticleController::projectRecord()` already falls back
to `$resource->data::from($model)`, and laravel-data magic-dispatches any `public static from*()`
accepting the payload. **13 of the estate's 20 `project:` closures deleted to a no-op.** The map
names the pattern outright — *"a hand-rolled mechanism on top of a working one nobody knew was
there"* — after two earlier instances (`afterResolving`, the reverse index). Mechanical test from
the same ticket: **a `data:` declared and equal to the closure's target means the closure is
deletable — 13 for 13.**

That ticket also listed four affordances found available-and-unused. Two have since been adopted in
beam's `src/` and two have not, as of this reading:

| affordance | state in beam `src/` |
| --- | --- |
| `AppendableData` | **adopted** — `src/Particle/Contribution/ContributionProjector.php`, via `additional()` |
| `fromModel` | **adopted** — 3 files |
| `InjectsPropertyValue` | still unused — a public interface, rides the default pipeline, needs no host wiring |
| `Data::factory()` | still unused |

**2. Both extension points are still wholly unadopted estate-wide** — zero `pipeline()` overrides,
zero custom transformers, zero `#[WithTransformer]`. Verified still true in beam's `src/` at this
reading. That is not permission to hand-roll around them; it is the same blind spot as trap 1.

**3. A package's discoverable assets are invisible to host-rooted scans.**
`auto_discover_types` defaults to `app_path()` — the *host's* app dir — so beam's `#[TypeScript]`
classes emit nothing into a host's generated types unless the host is configured to look. The same
map found splicewire-app's `generated.d.ts` carrying **zero** frame classes for exactly this reason.
A DTO that "doesn't generate" is usually this, not the DTO.

**4. Two majors, one codebase.** `^4.0|^5.0` means beam's code must hold on both. A 5-only
affordance seen in upstream docs is not automatically available here — the lock resolves 4.23.0, and
the hosts pin what beam permits.
