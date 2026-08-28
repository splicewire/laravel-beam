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

## The audit asks two questions, and it needs both

**1. Would a configured mapper REWRITE this name?** If so the mapper is choosing the contract — see
the section below for why this is the rule and what the naive version cost.

**2. Does this class declare SOME of its wire names and not others?** A class with siblings pinned
and one field bare has made a decision and failed to apply it, and that is checkable at a single
moment with no baseline.

⚠️ **The second exists because the first cannot see the realistic slip.** After a casing sweep every
property is camelCase, so `CamelCaseMapper` is the *identity* on them — **dropping an attribute
during a rename silently moves that field's published key** (`calendar_id` → `calendarId`) while the
transformation test stays quiet. Measured 2026-08-28: removing one `#[MapName]` from a swept DTO
produced **no finding at all** until this check existed.

That is the difference between the audit and `splicewire:beam:dev:wire-names`. The command needs two
readings and answers *"did this change move a key?"*. The audit needs one and answers *"is this
declaration internally coherent right now?"* — which is what a doctor can ask of a codebase it is
seeing for the first time. Neither subsumes the other, and the estate wants both.

## ⚠️ The transformation rule, and why the first version got it wrong

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
them, because nothing else moving can confound it — a schema diff on a live estate picks up every
neighbour's in-flight DTO edit.

`splicewire:beam:dev:wire-names` is that check. It lives in **`splicewire/laravel-beam-dev`**, not
here — it is a developer act like taking a scratch database, not runtime particle code, and beam-dev
is `require-dev` at hosts so it never ships to production.

```
artisan splicewire:beam:dev:wire-names <src…> > before.txt
# …rename properties, leaving every attribute argument untouched…
artisan splicewire:beam:dev:wire-names <src…> > after.txt
diff before.txt after.txt        # MUST be empty, or a published key moved
```

`--count` gives a summary instead of the diffable listing. A path that is not a directory is a hard
error rather than an empty listing — an empty result would read as "no keys declared", which is the
failure this check exists to prevent.

### ⚠️ Two ways this check has lied, both fixed, both worth knowing

**It read prose as code.** The first version did not strip comments, so it matched the attribute
inside docblocks *explaining the convention*: a file illustrating `#[MapInputName('expires_in_days')]`
reported that key twice, and one writing `#[MapInputName('<snake_key>')]` generically reported a key
literally named `<snake_key>`. Mid-sweep the output read as a botched rename. The script now strips
comments with `token_get_all()` first.

**It was run against the file that had just been reverted.** During the same investigation, a
generated artifact was copied, regenerated, restored (to avoid committing a neighbour's drift), and
*then* grepped — measuring the pre-run copy. Every reading came back zero and a working pipeline was
recorded as broken for hours.

**Measure BEFORE you restore, or measure a copy of the post-run output. Never inspect the artifact
you just reverted.**

These are two of five instruments that, in a single day, **succeeded while measuring something other
than the question** — alongside an import scan that called itself source-blindness, an audit that
flagged identity mappings, and a namespace grep against a file that writes nested `declare namespace`
blocks so the dotted string never appears. Four of the five were caught by a second,
differently-shaped measurement. **None were caught by re-reading the first.**

## Adding a property

Multi-word, and a configured mapper would rewrite it? Declare it. Single word? Nothing to do — every
mapper is the identity on `$id`. If a rename moves a key in that invariant, the attribute is missing.
