# Declare your wire name, and the PHP spelling is free

**The rule.** A Data class property whose published key a *global* mapper would rewrite must declare
that key with `#[MapName]` / `#[MapInputName]` / `#[MapOutputName]`. Once it does, the PHP spelling
is a style choice and nothing else.

The order is the whole point. **Declare the wire, then rename freely.** Reverse it and a rename
silently moves a published contract.

Enforced advisorily by `Splicewire\Beam\Surgeon\WireNameDeclarationAudit`
(`beam.particle.undeclared-wire-name`), which reports through `surgeon:audit`.

## Why this needed an instrument

`UndeclaredWriteMapAudit` asks *"did you declare how this DTO maps onto **columns**"*. Nothing asked
*"did you declare what a client must **send**"* — the more load-bearing of the two, because a column
map decides what one application stores while a wire name is a contract other people build against.

The defect runs in both directions and neither was visible:

- **Nothing declared.** Measured 2026-08-28 on `splicewire/laravel-beam-calendars`: fifteen Data
  classes declared neither axis, so under `input => CamelCaseMapper` / `output => null` the package
  **emitted `calendar_id` and demanded `calendarId` for the same field.** It shipped.
- **Declared by accident.** `api-surface-coherence` 100: sixty properties spelled snake in PHP purely
  to defeat a global camel mapper — the workaround became the convention.

Both are the same thing: **the global mapper deciding a package's published contract by default.**

## ⚠️ The audit reports TRANSFORMATION, not absence — and the first version got this wrong

Only a property that a **configured** global mapper would **rewrite** is a finding. An identity
mapping means the property name *is* the key, deterministically, and there is nothing to declare.

The first version skipped that test and flagged every undeclared multi-word property: **232 findings
at the flagship, 212 of them correct camelCase read properties under `output => null`** — and its
suggested `#[MapName('created_at')]` would have **renamed all 212 on the wire**. An audit that
recommends a breaking change to a correct declaration is worse than no audit. With the rule
corrected: **20 findings**, all real.

So the audit reads the host's own `data.name_mapping_strategy`. That makes its population a host
fact, which is why it is **advisory permanently** — a check whose answer depends on the host must
not throw. A class that cannot be reflected is a skipped row, not a fatal, for the same reason.

## ⚠️ Renaming: by SUBJECT TYPE, never by text

A DTO property is camelCase. An **Eloquent column is not.** Both are read with `->`, and a mis-cased
model read returns **`null` rather than erroring** — so a blanket regex over `src/` produces a silent
defect that surfaces far from the edit.

```php
calendarId: (string) $event->calendar_id,   // ✅ Eloquent model → COLUMN, snake
calendarId: $event->calendarId,             // ✅ value object   → PROPERTY, camel
```

Measured on `laravel-beam-calendars`: a regex renamed both, the model reads became `null`, and it
surfaced three tests later as *"expected '2026-01-06', got null"*. Invisible to `php -l` and to any
typecheck. For the same reason, a `toModelAttributes()` written as a loop over one list of names —
which works only while property and column happen to share a spelling — becomes an explicit
`property => column` map.

## Checking a sweep is wire-invisible

Because the sweep **retains** the attribute and renames only the property, the published key is the
attribute's argument. So the invariant is textual and exact: **the set of (file, attribute-argument)
pairs must be identical before and after.** That is stronger than regenerating schemas and diffing
them, because nothing else moving can confound it.

## Adding a property

Multi-word, and a configured mapper would rewrite it? Declare it. Single word? Nothing to do — every
mapper is the identity on `$id`. If a rename moves a key in that invariant, the attribute is missing.
