# API parameter documentation — convention

**Status:** canon for every documented API surface in the Splicewire estate.
**Decided:** api-surface-coherence ticket 08 (2026-08-17).
**Mechanism:** `rushing/laravel-data-schemas-scribe` — see its `docs/parameter-attributes.md`. That
package describes what the attributes *do*; this document states which you *must* use.

## The rule

> **A documented API parameter is declared on a Data class. The axis picks the attribute.**

| axis | attribute on the method | where the prose lives |
| --- | --- | --- |
| request body | `#[RequestFromData(SomeInputData::class)]` | `#[Description]` on the DTO property |
| query string | `#[QueryFromData(SomeQueryData::class)]` | `#[Description]` on the DTO property |
| path | *nothing* — derived from the route's particle stamp | derived from the resource |

`@bodyParam` and `@queryParam` docblock tags are **migration artifacts**. They still extract (Scribe
reads them, and they sit ahead of the Data strategies in the slot order so nothing breaks mid-sweep),
but they are not a home for new prose. A route that needs a parameter description needs an input DTO.

`#[Example]` is likewise declared on the property. Examples are **opt-in**: absence is correct and
deliberate, a wrong example is not.

## Why not a docblock

The tag duplicates a shape the DTO already declares, and duplication drifts silently — nothing fails
when the tag and the property disagree. The DTO declaration is read by the schema generator, the
generated client, and the runtime validation from one site. This is the same principle as the rest of
particle doctrine: **derive from a declaration; do not hand-annotate a route.**

## Path parameters are never annotated

They are derived from the resource the route is stamped with — model, route key, and label resolved off
`$route->defaults[ParticleController::RESOURCE]`. Do not add `#[UrlParam]` to a particle route, and do
not add a description override to `ParticleResource`: if a resource needs bespoke path-parameter prose,
that is a fact about the resource worth examining, not a knob worth adding.

## Writing a good property description

The failure mode is a description that restates the type. `typeKey` (string, required) with the
description "The type key" has documented nothing.

Say what the value *is*, where a caller *gets* one, and what constrains it:

```php
#[Description('The record type to govern — a schema identifier. Sent in the body rather than the path because a schema `$id` may contain slashes.')]
#[Example('https://schemas.splicewire.app/food-safety/restaurant/1')]
public string $typeKey,
```

Rules that the schema already carries (required, max length, format) do not belong in the prose — they
are extracted. Prose carries what the schema cannot: provenance, meaning, and the reason for a
surprising choice.

## Resolve the DTO after the gate, not by injection

Declaring the DTO is the convention. *Injecting* it as a typed controller parameter is a separate
choice, and on a gated write endpoint it is usually the wrong one:

```php
// Reorders the gate — validation runs during container resolution, before the method body.
public function bind(WorkflowBindingInputData $input) { $this->authorize(…); }

// Gate first, then validate.
public function bind(Request $request) { $this->authorize(…); $input = WorkflowBindingInputData::validateAndCreate($request); }
```

Measured on the exemplar: injection turned an unauthorized member's empty-body request from **403 into
422**, leaking the field vocabulary to a caller with no write access, while the sibling `unbind` on the
same controller still returned 403. Several controllers in the estate document the gate-first property
in their comments ("the gate is the first line … a minimal body still 403s") and a conversion that
injects silently breaks it.

`#[RequestFromData]` documents the shape either way — only the resolution site moves. Injection is fine
for ungated or read-only endpoints; when in doubt, resolve explicitly and keep the gate first.

**Converting an inline-validated route? Check the status code an unauthorized caller gets, before and
after.** It is the one behavioural change this otherwise-mechanical conversion can make.

## Exemplar

`PUT /beam/workflows/bindings` — `Splicewire\Beam\Workflows\Data\WorkflowBindingInputData`, consumed by
`Splicewire\Tower\Api\V1\WorkflowController::bind()`. It was chosen as the exemplar because it was the
worst case: an endpoint whose *endpoint-level* prose was already good, sitting above three fields with
no descriptions and faker examples (`ea`, `dicta`) — the gap this convention closes, in one screen.

## New routes

A new write route declares an input DTO from the start. There is no "add the DTO later" step — the
inline `$request->validate([...])` that a later sweep has to convert is the thing this convention
exists to stop being written.
