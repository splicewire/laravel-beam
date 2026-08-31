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

## ⚠️ The audit cannot see an undeclared ARRAY, and that is its blind spot by construction

It inspects declared properties. A DTO field typed `array<string, mixed>` and filled by a
hand-rolled builder has **no properties to inspect**, so it is invisible — to this audit, to codegen,
and to the schema projection. It still crosses the wire.

Found 2026-08-28 in `AuthUserData`: `$tenants` was `array<int, array<string, mixed>>`, built by a
`tenantRow()` helper returning four keys. It shipped on every authenticated request, and
`ui/src/stores/user.ts` hand-wrote the TypeScript for it because there was nothing to generate. Fixed
by declaring `TenantRefData` — same keys, now visible to every instrument.

⚠️ **The name hid it.** "row" reads like raw SQL, so nobody looked twice at a *published projection*.
If a method returns an array that ends up inside a DTO property, it is a wire shape whatever it is
called.

**The tell:** any `array<…>`-typed property on a Data class whose contents are built by hand. Grep
`@var array<` on your DTOs. Each one is either a genuinely open bag (rare, and the doctrine's
`meta`-style exception) or an undeclared shape.

## A FOREIGN key is not ours to rename, and that is not an exception to house style

House style governs the shapes this estate **authors**. A field arriving from a third party and being
relayed is a different thing: renaming it makes our copy disagree with the upstream every operator
reads, for no gain, and the disagreement surfaces at the worst moment — comparing our payload against
the vendor's docs during an incident.

`SellerRepoData::$fullName` is the worked case: `full_name` is **GitHub's** field, arriving verbatim
in the `installation` webhook and stored verbatim in a JSON column that keys its merge on it. It
stays `full_name` on the wire.

⚠️ **This is not a carve-out from the convention — it is the convention working.** *Declare the wire,
then the PHP spelling is free* means exactly this: the property is house-style camelCase
(`$fullName`), and `#[MapName('full_name')]` pins the foreign key. Both halves are right. The failure
would be spelling the PHP property `$full_name` to "match", which publishes the same key at this host
**and** raises an `undeclared-wire-name` finding, because a global camel input mapper rewrites it.

**Ask where the name comes from before deciding whether it may change.** Ours → house style applies.
Theirs → relay it, declare it, and say so in the docblock, because the next reader's instinct will be
to tidy it. "It already shipped" is a real reason too, but it is the weaker one and it invites the
argument that a breaking change is acceptable if done early enough. Provenance does not.

## ⚠️ `{@see}` a class in another package and pint will import it for you

Writing `{@see \Some\Other\Package\Thing}` in a docblock is not inert. **pint's
`fully_qualified_strict_types` fixer resolves it and adds the `use` line**, manufacturing a real
import — and therefore a real dependency edge — out of prose.

Measured 2026-08-31 while declaring `SellerRepoData` in `laravel-beam-market`: a `{@see}` pointing at
`beam-accounts` gained a `use` statement unprompted. `beam-accounts` is `require-dev` there, so it
resolves locally and in tests and would have been a **runtime** edge the manifest does not carry.
Caught before commit; the reference is prose now, and pint leaves prose alone.

**Name a cross-package class in prose, never `{@see}`, unless the package genuinely requires it.**
This bites hardest exactly where declaring a shape is most useful — a nested DTO in one package
naturally wants to cite its twin in another.

## ⚠️ Quote a finding count as a delta, never as a number

The audit's total moves under you on a live estate, because its population is *"every Data class this
host declares"* and neighbouring sessions add classes continuously. Measured 2026-08-28 within one
afternoon: **20 → 22 → 25 → 27** with no change to the audit and no defect introduced — new classes
simply reached the registry.

So `AuthUserData`'s fix was verified as **its own two findings going to zero**, not as a total
dropping by two. A brief that carried "the baseline is 22" was already stale by the time it was read,
and the executor correctly re-measured rather than trusting it.

**Take your own before-reading, immediately before the change, and assert the delta on the rows you
touched.** This is the same discipline the estate applies to test counts (quote a spread, and treat a
spread as a thing with a cause), for the same reason.

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
