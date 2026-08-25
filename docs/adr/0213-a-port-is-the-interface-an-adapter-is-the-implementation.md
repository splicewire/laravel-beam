# ADR-0213 — A port is the INTERFACE, an adapter is the IMPLEMENTATION, and neither suffix is inherited

**Status:** accepted
**Date:** 2026-08-25
**Repo:** `splicewire/laravel-beam`
**Wayfinding:** an `/improve-codebase-architecture` pass over the conduit `Port` concept, grilled to
close. Unblocks `.scratch/splicewire/laravel-beam/beam-facade/` ticket 123, which deferred a rename
pending "the `ActorPort` question"; adjacent to `.scratch/splicewire/splicewire-app/api-surface-coherence/`
ticket 34 (closed 2026-08-25), which ruled the registry-kernel effort out of this axis.

## Context

The estate ships exactly **four** `*Port.php` classes and **six** `*Adapter*.php` classes. Read
together they describe a rule nobody has written down, obeyed in most places and inverted in two.

| Class | Kind | Implements | Reading |
| --- | --- | --- | --- |
| `Beam\Authorization\ActorPort` | interface | — | ✅ port = interface |
| `Beam\Authorization\GuardActorPort` | class | `ActorPort` | ❌ implementation wears the port suffix |
| `Beam\Frame\ParticleResourceRegistryPort` | class | `ResourceRegistry` | ❌ inverted — the port is `ResourceRegistry` |
| `Circuits\Ports\Port` | value object | — | not hexagonal at all (see below) |
| `Surgeon\Lint\PintAdapter`, `EslintAdapter` | classes | `LintStack` | ✅ textbook — domain-noun port, `Adapter` implementations |
| `CompositionEngine\Kernel\Contracts\VendorAdapter` | interface | — | ❌ interface wears the adapter suffix |
| `Tower\Composition\Music\FalMusicAdapter`, `FakeSunoAdapter`, `FakeElevenLabsAdapter` | classes | `VendorAdapter` | ❌ implementation repeats its interface's suffix |

The defect is **not** "we used the word Port where we meant Adapter". It is narrower and it appears
twice, in opposite directions: **an implementation repeating its interface's suffix**
(`GuardActorPort implements ActorPort`, `FalMusicAdapter implements VendorAdapter`). Once the
implementation inherits the name, the pair stops saying which one you may swap.

Two facts made the ruling cheap rather than contentious:

- **`ParticleResourceRegistryPort` already documents itself correctly and is named backwards.** Its own
  docblock calls `ResourceRegistry` *"Frame's agnostic **port**"* and calls the class *"this **adapter**"*
  — in the same paragraph as the class name that says the reverse. The prose was right the whole time.
- **The blast radius is beam-local.** `GuardActorPort` is 6 references across 4 files;
  `ParticleResourceRegistryPort` is 8 across 3. **Nothing outside `splicewire/laravel-beam` references
  either** — verified against `~/Workspaces/php/packages` and `~/Herd/splicewire-app`.

**`Splicewire\Circuits\Ports\Port` is a different word.** It is a value object — an envelope `type`
plus the JSON Schema its payload must satisfy — and it is half of a real invariant with `Envelope` and
`PortValidator`. It is *deep* on the circuit-node path, where `StructuralPortValidator` checks an
envelope's type and walks its payload, and *degenerate* at the conduit seam, where capabilities are
invoked `array → array` through `Invocable`, no `Envelope` exists, and every consumption site reaches
straight through to `?->schema`. Two meanings of one word, live in three maps at once. This is the
twelfth naming collision the estate has found, and it faces the same way as the largest one — the
`Resolver` ban, 128 non-vendor classes across three vendors.

**`Envelope` is the thirteenth, and it collides with the framework.**
`Splicewire\Circuits\Ports\Envelope` and `Illuminate\Mail\Mailables\Envelope` are both live in
`splicewire/tower` — the latter at `src/Mail/TenantInvitationMail.php:50`. A family-wide `Envelope`
contract would make a Laravel-core collision estate-wide, and it has no second implementor to justify
itself: all four construction sites are inside the circuit path.

## Decision

### The rule

1. **A port is an interface.** Prefer a domain noun (`ResourceRegistry`, `LintStack`); the `Port`
   suffix is legitimate when the domain noun is taken or genuinely ambiguous (`ActorPort` — "Actor"
   alone would say nothing about the seam).
2. **An adapter is an implementation of a port**, and takes the `Adapter` suffix when its job is to
   bridge one shape to another. An implementation that is simply *the* behaviour, not a bridge, takes a
   descriptive name and no suffix at all.
3. **An implementation never repeats its interface's suffix.** This is the operative clause — it is
   what both live defects violate, and it is mechanically checkable.

### Renamed here

- `Splicewire\Beam\Authorization\GuardActorPort` → **`GuardActorAdapter`**. It bridges the Laravel
  guard to `ActorPort`; the port keeps its name.
- `Splicewire\Beam\Frame\ParticleResourceRegistryPort` → **`ParticleResourceRegistryAdapter`**. It
  bridges beam's `ParticleResourceRegistry` to Frame's `ResourceRegistry` port, which is what its
  docblock already said.

### `Circuits\Ports\Port` is exempt by kind, not by privilege

It is a value object, not a seam. It keeps its name, and it is **always written qualified**
(`Circuits\Ports\Port`) or spoken as a **node port**. Unqualified "Port" in prose, a ticket, or a class
name means the hexagonal interface. `Port` does not move packages: it is already in
`splicewire/laravel-circuit-spine`, which requires nothing family-side.

### Never define a family-wide `Envelope`

One implementor is a hypothetical seam, and the word is taken by the framework in a repo that uses
both. `Circuits\Ports\Envelope` stays where it is, qualified.

### `VendorAdapter` is recorded, not fixed

`splicewire/laravel-composition-engine`'s `VendorAdapter` is an interface wearing the adapter suffix,
and its three implementations repeat it. It violates clauses 1 and 3. It is **not** renamed here —
different repo, different map, and renaming an interface costs more than renaming two classes. It is
recorded so the composition-engine map inherits the decision instead of rediscovering it.

## Consequences

- `beam-facade` ticket 123 is unblocked and should land its rename after this, not before — its own
  note is that a second rename later costs more than getting the vocabulary right once.
- The rule's operative clause is mechanical, so a `surgeon:house-style` check is possible later: no
  class may end with the same suffix as an interface it implements. Not built here.
- Anything naming a new payload-contract concept must still pass a pre-flight grep. `*Slot*` is taken
  by composition-engine (`SlotSchema`, `CategorySlotSchema`, `ArraySlotSchema`) and `DeclaredShape` by
  `rushing/laravel-schema-convergence`, so beam's own prose term "shape slot" is **not** available.
- This ADR is repo-local per `docs/conventions/adr-placement.md`. It governs beam today; it promotes to
  fleet tier only if a second repo adopts the clause rather than citing it.
